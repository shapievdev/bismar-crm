<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\ClearConversation;
use App\Actions\Chat\DeleteConversation;
use App\Actions\Chat\ManageParticipants;
use App\Actions\Chat\MarkConversationRead;
use App\Actions\Chat\SayInConversation;
use App\Actions\Chat\StartConversation;
use App\Enums\ConversationKind;
use App\Enums\DeletionScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\DeleteConversationRequest;
use App\Http\Requests\Chat\StartConversationRequest;
use App\Http\Resources\Chat\ConversationResource;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Chat\Unread;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Переписки сотрудника.
 *
 * Мессенджер открыт всякому, кто вошёл: писать коллеге — не то же, что читать
 * базу знаний или смотреть выручку, и права здесь ни при чём. Закрыта только
 * чужая переписка, и закрыта она наглухо — см. ConversationPolicy.
 */
final class ConversationController extends Controller
{
    public function __construct(private readonly Unread $unread) {}

    /**
     * Список переписок — свежие сверху, с последним сообщением и счётчиком.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $reader */
        $reader = $request->user();

        $conversations = Conversation::query()
            ->of($reader)
            ->withClearing($reader)
            ->with(['activeParticipants', 'lastMessage.author', 'lastMessage.attachments'])
            ->withCount('activeParticipants')
            // Пустая переписка — только что заведённая: её место наверху, пока
            // в ней не сказали ни слова.
            ->orderByRaw('coalesce(last_message_at, created_at) desc')
            ->get();

        return ConversationResource::collection($this->withUnread($reader, $conversations));
    }

    /**
     * Заводит переписку. Личная у пары одна: второй раз попадёшь в ту же.
     */
    public function store(StartConversationRequest $request, StartConversation $start): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        $conversation = $request->kind() === ConversationKind::Direct
            ? $start->direct($actor, User::query()->findOrFail($request->validated('user_id')))
            : $start->group($actor, (string) $request->validated('title'), $request->userIds());

        return ConversationResource::make($this->loaded($conversation, $actor))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function show(Request $request, Conversation $conversation): ConversationResource
    {
        Gate::authorize('view', $conversation);

        /** @var User $reader */
        $reader = $request->user();

        return ConversationResource::make($this->withUnread(
            $reader,
            new Collection([$this->loaded($conversation, $reader)]),
        )->first());
    }

    /** Переименование группы — с отметкой в ленте, как и всякая правка состава. */
    public function update(Request $request, Conversation $conversation, SayInConversation $say): ConversationResource
    {
        Gate::authorize('manage', $conversation);

        /** @var User $actor */
        $actor = $request->user();

        $title = trim((string) $request->validate([
            'title' => ['required', 'string', 'max:120'],
        ])['title']);

        if ($title !== $conversation->title) {
            $was = (string) $conversation->title;
            $conversation->forceFill(['title' => $title])->save();

            $say->system($conversation, sprintf('%s переименовал группу «%s» в «%s»', $actor->name, $was, $title));
        }

        return ConversationResource::make($this->loaded($conversation, $actor));
    }

    /**
     * Удаляет переписку — у себя либо у всех.
     *
     * Два разных дела, и потому две разные проверки прав: убрать у себя может
     * всякий участник, у всех — только тот, кому этот разговор принадлежит
     * целиком (см. ConversationPolicy). Чего именно хотят, говорит запрос;
     * умолчание — меньшее из двух.
     */
    public function destroy(
        DeleteConversationRequest $request,
        Conversation $conversation,
        ClearConversation $clear,
        DeleteConversation $delete,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $scope = $request->scope();

        if ($scope === DeletionScope::Everyone) {
            Gate::authorize('deleteForEveryone', $conversation);

            $delete->handle($conversation);
        } else {
            Gate::authorize('clear', $conversation);

            $clear->handle($conversation, $actor);
        }

        return response()->json(['data' => ['scope' => $scope->value]]);
    }

    /** Прочитано до сих пор — и собеседник об этом узнаёт сразу. */
    public function read(Request $request, Conversation $conversation, MarkConversationRead $mark): JsonResponse
    {
        Gate::authorize('view', $conversation);

        /** @var User $reader */
        $reader = $request->user();

        return response()->json(['data' => ['read_at' => $mark->handle($conversation, $reader)]]);
    }

    /** Выход из группы: сообщения остаются, новых человек больше не получает. */
    public function leave(Request $request, Conversation $conversation, ManageParticipants $participants): JsonResponse
    {
        Gate::authorize('leave', $conversation);

        /** @var User $actor */
        $actor = $request->user();

        $participants->remove($conversation, $actor, $actor);

        return response()->json(['data' => ['left' => true]]);
    }

    /** Общий счётчик непрочитанного — тот, что висит в навигации. */
    public function unreadTotal(Request $request): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        return response()->json(['data' => ['unread' => $this->unread->total($reader)]]);
    }

    /**
     * Одна переписка глазами читателя.
     *
     * Отметку об удалении у себя здесь приходится доставать отдельно: подзапрос
     * из scopeWithClearing() выгоден списку, а не одной строке, которую и так
     * уже нашли по адресу.
     */
    private function loaded(Conversation $conversation, User $reader): Conversation
    {
        $conversation->setAttribute('cleared_at', $conversation->membershipOf($reader)?->cleared_at);

        return $conversation->load(['activeParticipants', 'lastMessage.author', 'lastMessage.attachments'])
            ->loadCount('activeParticipants');
    }

    /**
     * Проставляет счётчики непрочитанного — одним запросом на весь список.
     *
     * @param  Collection<int, Conversation>  $conversations
     * @return Collection<int, Conversation>
     */
    private function withUnread(User $reader, Collection $conversations): Collection
    {
        $counts = $this->unread->forConversations($reader, $conversations->modelKeys());

        return $conversations->each(function (Conversation $conversation) use ($counts): void {
            $conversation->setAttribute('unread_count', $counts[$conversation->getKey()] ?? 0);
        });
    }
}
