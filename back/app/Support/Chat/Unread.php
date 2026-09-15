<?php

declare(strict_types=1);

namespace App\Support\Chat;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Сколько человек не прочёл — и в каждой переписке, и всего.
 *
 * Одним запросом на весь список, а не подзапросом на каждую строку: список
 * переписок открывают чаще всего в мессенджере, и полсотни подзапросов на нём
 * — это полсотни лишних обращений к базе на каждое открытие.
 *
 * Непрочитанным считается чужое сообщение, сказанное позже той минуты, до
 * которой человек дочитал. Своё — никогда: отправив сообщение, ты его прочёл.
 */
final readonly class Unread
{
    /**
     * Непрочитанное по переписками — и отдельно то, где человека позвали.
     *
     * Обе цифры одним проходом, через `filter`: упоминания — подмножество
     * непрочитанного, и второй запрос читал бы те же строки ради другого
     * счётчика. В списке они стоят рядом: общая цифра и значок «@», как в
     * телеграме, — и появляются они тоже вместе.
     *
     * @param  list<int>  $conversationIds
     * @return array<int, array{total: int, mentions: int}>
     */
    public function forConversations(User $reader, array $conversationIds): array
    {
        if ($conversationIds === []) {
            return [];
        }

        $rows = $this->query($reader)
            ->whereIn('messages.conversation_id', $conversationIds)
            ->groupBy('messages.conversation_id')
            ->selectRaw('messages.conversation_id')
            ->selectRaw('count(*) as total')
            // Номер читателя внутри списка позванных. Containment по jsonb, а не
            // разбор массива: так это один оператор, который Postgres умеет
            // считать по индексу, если он однажды понадобится.
            ->selectRaw('count(*) filter (where messages.mentions @> ?::jsonb) as mentions', [
                json_encode([$reader->getKey()], JSON_THROW_ON_ERROR),
            ])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[(int) $row->conversation_id] = [
                'total' => (int) $row->total,
                'mentions' => (int) $row->mentions,
            ];
        }

        return $counts;
    }

    /** Общий счётчик — тот, что висит в навигации. */
    public function total(User $reader): int
    {
        return (int) $this->query($reader)->count();
    }

    private function query(User $reader): Builder
    {
        return DB::table('messages')
            // Запрос идёт мимо модели, а значит и мимо мягкого удаления:
            // убранная реплика оставляла за собой непрочитанное, которое в
            // ленте уже нечем показать.
            ->whereNull('messages.deleted_at')
            ->join('conversation_participants', function ($join) use ($reader): void {
                $join->on('conversation_participants.conversation_id', '=', 'messages.conversation_id')
                    ->where('conversation_participants.user_id', '=', $reader->getKey());
            })
            // Вышедший из группы перестаёт получать непрочитанное: он её
            // покинул, а не отложил.
            ->whereNull('conversation_participants.left_at')
            ->where(function ($query) use ($reader): void {
                $query->whereNull('messages.user_id')
                    ->orWhere('messages.user_id', '!=', $reader->getKey());
            })
            ->where(function ($query): void {
                $query->whereNull('conversation_participants.last_read_at')
                    ->orWhereColumn('messages.created_at', '>', 'conversation_participants.last_read_at');
            })
            // Удалённое у себя не считается непрочитанным: этой переписки для
            // человека больше нет, и висящая над ней цифра вела бы в пустое.
            ->where(function ($query): void {
                $query->whereNull('conversation_participants.cleared_at')
                    ->orWhereColumn('messages.created_at', '>', 'conversation_participants.cleared_at');
            });
    }
}
