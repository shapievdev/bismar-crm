<?php

declare(strict_types=1);

namespace App\Support\Chat;

use App\Enums\MessageKind;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Support\Ai\RussianText;
use Illuminate\Database\Eloquent\Builder;

/**
 * Поиск по сказанному — в одном разговоре и во всех сразу.
 *
 * Русским словарём, а не подстрокой: спрашивают «бланк», а написано «бланки» и
 * «бланком». Последнее слово запроса ищется по началу — человек ещё печатает, и
 * ждать, пока он допишет, незачем.
 *
 * Выражение обязано слово в слово повторять то, на котором построен индекс (см.
 * миграцию и RussianText), иначе Postgres индекс не возьмёт и пойдёт читать
 * таблицу целиком — на десятках тысяч реплик это видно глазом.
 *
 * Границы видимого те же, что у ленты: разговоры, из которых человек не вышел,
 * и только то, что сказано после того, как он удалил переписку у себя. Поиск не
 * должен становиться щелью, через которую видно больше, чем в самой переписке.
 */
final readonly class MessageSearch
{
    /** Сколько находок отдаётся за раз. */
    public const PAGE = 40;

    /**
     * Сколько слов запроса участвует в поиске.
     *
     * Дальше седьмого слова запрос не уточняет, а лишь стоит дороже: каждое
     * добавляет пересечение по индексу, а формулировок длиннее в поиске по
     * переписке не бывает.
     */
    private const MAX_TERMS = 6;

    /** Короче двух букв не ищем: одна буква находит всё и потому ничего. */
    private const MIN_LENGTH = 2;

    /**
     * Находки в одном разговоре — свежие первыми.
     *
     * @return Builder<Message>|null null, если искать нечего
     */
    public function inConversation(Conversation $conversation, User $reader, string $query): ?Builder
    {
        $found = $this->matching($query);

        if ($found === null) {
            return null;
        }

        return $this->visible($found, $reader)
            ->where('messages.conversation_id', $conversation->getKey());
    }

    /**
     * Находки во всех своих переписках.
     *
     * @return Builder<Message>|null null, если искать нечего
     */
    public function everywhere(User $reader, string $query): ?Builder
    {
        $found = $this->matching($query);

        return $found === null ? null : $this->visible($found, $reader);
    }

    /**
     * Реплики, подходящие под запрос, — без оглядки на то, кто спрашивает.
     *
     * @return Builder<Message>|null
     */
    private function matching(string $query): ?Builder
    {
        $tsquery = $this->tsquery($query);

        if ($tsquery === null) {
            return null;
        }

        return Message::query()
            // Системные отметки — не разговор: «Иванов вышел из группы» в
            // находках только мешает.
            ->where('messages.kind', MessageKind::Text->value)
            ->whereNotNull('messages.body')
            ->whereRaw(
                sprintf(
                    "to_tsvector('russian', %s) @@ to_tsquery('russian', %s)",
                    RussianText::normalised('messages.body'),
                    RussianText::normalised('?'),
                ),
                [$tsquery],
            );
    }

    /**
     * Сужает найденное до того, что этому человеку и так видно.
     *
     * Соединением, а не подзапросом: у отметки об удалении у себя своя дата в
     * каждой переписке, и сравнивать с ней приходится каждую найденную реплику.
     *
     * @param  Builder<Message>  $query
     * @return Builder<Message>
     */
    private function visible(Builder $query, User $reader): Builder
    {
        return $query
            ->join('conversation_participants as reader', function ($join) use ($reader): void {
                $join->on('reader.conversation_id', '=', 'messages.conversation_id')
                    ->where('reader.user_id', '=', $reader->getKey())
                    // Вышедший из группы не ищет в ней: он её покинул, а не
                    // отложил.
                    ->whereNull('reader.left_at');
            })
            ->where(fn (Builder $cleared) => $cleared
                ->whereNull('reader.cleared_at')
                ->orWhereColumn('messages.created_at', '>', 'reader.cleared_at'))
            // Соединение приносит с собой чужие колонки, и без этого `id`
            // находки оказался бы номером строки участия.
            ->select('messages.*')
            ->latest('messages.id');
    }

    /**
     * Запрос человека, переведённый на язык полнотекстового поиска.
     *
     * Из слов вычищается всё, кроме букв и цифр, и это единственное, что стоит
     * между пользовательским вводом и синтаксисом tsquery: операторы `&`, `:` и
     * `*` в итоговой строке могут появиться только отсюда, из кода. Без этого
     * «а & б» уронил бы запрос, а подобранная строка — сделала бы больше.
     *
     * Последнее слово ищется по началу: человек ещё печатает «бланк», и находки
     * должны появляться, не дожидаясь, пока он допишет «бланков».
     */
    private function tsquery(string $query): ?string
    {
        $words = preg_split('/\s+/u', trim($query), flags: PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = [];

        foreach ($words as $word) {
            $clean = preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? '';

            if ($clean !== '') {
                $terms[] = $clean;
            }
        }

        $terms = array_slice($terms, 0, self::MAX_TERMS);

        if ($terms === [] || mb_strlen(implode('', $terms)) < self::MIN_LENGTH) {
            return null;
        }

        $last = array_key_last($terms);
        $terms[$last] .= ':*';

        return implode(' & ', $terms);
    }
}
