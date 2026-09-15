<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Resources\Chat\MessageHitResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Chat\MessageSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Поиск по сказанному.
 *
 * Две точки на два разных вопроса. «Где это было в нашем разговоре» — поиск по
 * ленте, с переходами вверх-вниз и счётчиком «третье из семнадцати»; ради
 * счётчика и считается общее число находок. «Где вообще это обсуждали» — поиск
 * по всем перепискам сразу, и там счётчик не нужен, нужен список.
 *
 * Люди и названия переписок здесь не ищутся, и это не упущение: список
 * переписок у вкладки уже на руках и отбирается ею мгновенно, без обращения к
 * серверу, а сотрудники ищутся там же, где и всегда, — в contacts.
 */
final class MessageSearchController extends Controller
{
    public function __construct(private readonly MessageSearch $search) {}

    /**
     * Находки в одной переписке.
     *
     * Вместе с общим числом: без него нельзя написать «третье из семнадцати», а
     * без этой надписи поиск по ленте превращается в угадывание, сколько ещё
     * осталось.
     */
    public function inConversation(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        /** @var User $reader */
        $reader = $request->user();

        $found = $this->search->inConversation($conversation, $reader, (string) $request->query('q', ''));

        return $this->answer($found, $request->integer('before'), withTotal: true);
    }

    /** Находки во всех своих переписках — свежие первыми. */
    public function everywhere(Request $request): AnonymousResourceCollection
    {
        /** @var User $reader */
        $reader = $request->user();

        $found = $this->search->everywhere($reader, (string) $request->query('q', ''));

        return $this->answer($found, $request->integer('before'), withTotal: false);
    }

    /**
     * Страница находок: сорок штук до указанной.
     *
     * Как и лента, страницами от конца, а не по номеру: пока человек листает
     * находки, в переписке продолжают говорить, и «страница 3» успевает
     * означать разное.
     *
     * @param  Builder<Message>|null  $found
     */
    private function answer(?Builder $found, int $before, bool $withTotal): AnonymousResourceCollection
    {
        if ($found === null) {
            return MessageHitResource::collection([])->additional(['meta' => ['total' => 0]]);
        }

        // Считаем до того, как отрежем страницу: общее число — это сколько их
        // всего, а не сколько поместилось.
        $total = $withTotal ? (clone $found)->toBase()->getCountForPagination() : null;

        $hits = $found
            ->with('author')
            ->when($before > 0, fn (Builder $query) => $query->where('messages.id', '<', $before))
            ->limit(MessageSearch::PAGE)
            ->get();

        return MessageHitResource::collection($hits)->additional(['meta' => array_filter([
            'total' => $total,
        ], static fn (mixed $value): bool => $value !== null)]);
    }
}
