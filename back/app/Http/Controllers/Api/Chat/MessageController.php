<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Actions\Chat\DeleteMessage;
use App\Actions\Chat\DeleteMessages;
use App\Actions\Chat\EditMessage;
use App\Actions\Chat\SayInConversation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\DeleteMessagesRequest;
use App\Http\Requests\Chat\EditMessageRequest;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Resources\Chat\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Лента переписки.
 */
final class MessageController extends Controller
{
    /** Сколько сообщений отдаётся за раз. */
    private const PAGE = 40;

    /**
     * Сколько закреплённого показывать.
     *
     * Полоса над лентой листается, но закреплять три десятка реплик значит не
     * закреплять ничего: то, что нужно всем, помещается в несколько строк.
     */
    private const PINNED = 10;

    /**
     * Кусок ленты от конца, а не страница с номером.
     *
     * Переписка растёт снизу, и «страница 3» в ней означает разное до и после
     * нового сообщения. «Сорок штук до вот этого» — не означает.
     */
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        /** @var User $reader */
        $reader = $request->user();

        $membership = $conversation->membershipOf($reader);

        // Удалённое у себя не показывается заново: для этого человека переписка
        // начинается с той минуты, когда он её убрал.
        $clearedAt = $membership?->cleared_at;

        $before = $request->integer('before');
        $after = $request->integer('after');

        $messages = $this->page($conversation, $clearedAt, $before, $after);

        return MessageResource::collection($messages)->additional(['meta' => [
            /*
             * Закреплённое приезжает вместе с лентой, а не отдельным запросом.
             *
             * Полоса над разговором рисуется в тот же миг, что и сам разговор, и
             * второе обращение ради неё означало бы, что она появляется рывком
             * через полсекунды после открытия.
             */
            'pinned' => MessageResource::collection($this->pinned($conversation, $clearedAt))->resolve(),

            /*
             * Где начинается непрочитанное — чтобы поставить там отбивку и
             * открыть переписку на ней, а не в самом низу.
             *
             * Считается до того, как переписку отметят прочитанной: открыв её,
             * человек сразу гасит счётчик, и спросить об этом второй раз будет
             * уже не у кого.
             */
            'first_unread_id' => $this->firstUnread($conversation, $reader, $clearedAt),
        ]]);
    }

    /**
     * Кусок ленты: сорок штук до указанного сообщения, сорок после — или
     * последние сорок, если не указано ничего.
     *
     * «После» нужен перескоку к найденному поиском: перенеслись на реплику
     * годичной давности, и вокруг неё должен быть разговор, а не пустота снизу.
     *
     * @return Collection<int, Message>
     */
    private function page(Conversation $conversation, ?CarbonInterface $clearedAt, int $before, int $after): Collection
    {
        $messages = $conversation->messages()
            ->with(['author', 'attachments', 'replyTo.author', 'reactions.person'])
            ->when($clearedAt !== null, fn ($query) => $query->where('messages.created_at', '>', $clearedAt))
            ->when($before > 0, fn ($query) => $query->where('id', '<', $before))
            ->when($after > 0, fn ($query) => $query->where('id', '>', $after))
            // Читая вперёд, берём ближайшие к точке, а не самые свежие: иначе
            // между перескоком и подгруженным куском осталась бы дыра.
            ->when($after > 0, fn ($query) => $query->oldest('id'), fn ($query) => $query->latest('id'))
            ->limit(self::PAGE)
            ->get();

        // Выбираем с конца, показываем с начала: читают разговор сверху вниз.
        return $after > 0 ? $messages : $messages->reverse()->values();
    }

    /**
     * Закреплённое — последнее поднятое первым.
     *
     * Удалившему переписку у себя показывается только то, что закрепили после:
     * закрепление общее, но прошлого, которого для человека больше нет, оно не
     * возвращает.
     *
     * @return Collection<int, Message>
     */
    private function pinned(Conversation $conversation, ?CarbonInterface $clearedAt): Collection
    {
        return $conversation->pinnedMessages()
            ->with(['author', 'attachments'])
            ->when($clearedAt !== null, fn ($query) => $query->where('messages.created_at', '>', $clearedAt))
            ->limit(self::PINNED)
            ->get();
    }

    /**
     * Первое непрочитанное чужое сообщение — то, над которым встанет отбивка.
     *
     * Своё непрочитанным не бывает: отправив, ты его прочёл.
     */
    private function firstUnread(Conversation $conversation, User $reader, ?CarbonInterface $clearedAt): ?int
    {
        $readAt = $conversation->membershipOf($reader)?->last_read_at;

        $first = $conversation->messages()
            ->when($clearedAt !== null, fn ($query) => $query->where('messages.created_at', '>', $clearedAt))
            ->when($readAt !== null, fn ($query) => $query->where('messages.created_at', '>', $readAt))
            ->where(fn ($query) => $query->whereNull('user_id')->orWhere('user_id', '!=', $reader->getKey()))
            ->oldest('id')
            ->value('id');

        return $first === null ? null : (int) $first;
    }

    public function store(
        SendMessageRequest $request,
        Conversation $conversation,
        SayInConversation $say,
    ): JsonResponse {
        Gate::authorize('speak', $conversation);

        /** @var User $author */
        $author = $request->user();

        // Карточка «письмо пришло с этого материала» ставится только тому, кто
        // сам материал видит: иначе по ссылке из чужих рук в разговор попадало
        // бы название закрытого документа.
        $about = $request->about();

        $message = $say->handle(
            $conversation,
            $author,
            $request->body(),
            $request->attachments(),
            $request->replyToId(),
            $about?->visibleTo($author) === true ? $about->card() : null,
            $request->mentions(),
            $request->voice(),
        );

        return MessageResource::make($message->load(['author', 'attachments', 'replyTo.author']))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    /**
     * Правка своей реплики.
     *
     * Принадлежность сообщения переписке из адреса проверяется отдельно: без
     * этого чужую реплику можно было бы править, подставив к своей переписке
     * идентификатор из соседней.
     */
    public function update(
        EditMessageRequest $request,
        Conversation $conversation,
        Message $message,
        EditMessage $edit,
    ): MessageResource {
        $this->ensureBelongs($message, $conversation);
        Gate::authorize('update', $message);

        /** @var User $editor */
        $editor = $request->user();

        return MessageResource::make($edit->handle($message, $editor, $request->body()));
    }

    public function destroy(
        Conversation $conversation,
        Message $message,
        DeleteMessage $delete,
    ): Response {
        $this->ensureBelongs($message, $conversation);
        Gate::authorize('delete', $message);

        $delete->handle($message);

        return response()->noContent();
    }

    /**
     * Удаление выделенного: несколько реплик разом.
     *
     * Права на каждую проверяет действие — и проверяет все до того, как удалит
     * первую: вернуть удалённое нельзя, и «девять убрано, на десятом отказ» —
     * худший из возможных исходов.
     */
    public function destroyMany(
        DeleteMessagesRequest $request,
        Conversation $conversation,
        DeleteMessages $delete,
    ): JsonResponse {
        Gate::authorize('view', $conversation);

        /** @var User $actor */
        $actor = $request->user();

        $messages = Message::query()
            ->whereKey($request->messageIds())
            ->with('conversation')
            ->get();

        return response()->json(['data' => ['deleted' => $delete->handle($messages, $actor)]]);
    }

    private function ensureBelongs(Message $message, Conversation $conversation): void
    {
        abort_unless($message->conversation_id === $conversation->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
