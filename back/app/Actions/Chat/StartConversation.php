<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Enums\ConversationKind;
use App\Models\Conversation;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Заводит переписку — личную или групповую.
 *
 * Личная у пары одна навсегда: написав коллеге второй раз, человек попадает в
 * тот же разговор, а не в новый пустой. Держится это не проверкой «а нет ли
 * уже», а уникальным ключом пары в базе, — между проверкой и вставкой
 * помещается чужой запрос, и в мессенджере это не редкость, а обычное дело:
 * двое отвечают друг другу одновременно.
 */
final readonly class StartConversation
{
    public function __construct(private SayInConversation $say) {}

    public function direct(User $initiator, User $companion): Conversation
    {
        $key = Conversation::directKey((int) $initiator->getKey(), (int) $companion->getKey());

        $existing = Conversation::query()->where('direct_key', $key)->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($initiator, $companion, $key): Conversation {
                $conversation = Conversation::create([
                    'kind' => ConversationKind::Direct,
                    'direct_key' => $key,
                    'created_by_id' => $initiator->getKey(),
                ]);

                $conversation->participants()->attach([$initiator->getKey(), $companion->getKey()]);

                return $conversation;
            });
        } catch (QueryException $exception) {
            // Кто-то успел раньше: уникальный ключ на то и стоит. Его переписка
            // ничем не хуже нашей — она та же самая.
            $raced = Conversation::query()->where('direct_key', $key)->first();

            if ($raced === null) {
                throw $exception;
            }

            return $raced;
        }
    }

    /**
     * @param  list<int>  $userIds  кого позвали, кроме заводящего
     */
    public function group(User $owner, string $title, array $userIds): Conversation
    {
        $members = array_values(array_unique(array_map(intval(...), $userIds)));
        $members = array_values(array_diff($members, [$owner->getKey()]));

        $conversation = DB::transaction(function () use ($owner, $title, $members): Conversation {
            $conversation = Conversation::create([
                'kind' => ConversationKind::Group,
                'title' => trim($title),
                'created_by_id' => $owner->getKey(),
            ]);

            $conversation->participants()->attach([$owner->getKey(), ...$members]);

            return $conversation;
        });

        $this->say->system($conversation, sprintf('%s создал группу «%s»', $owner->name, trim($title)));

        return $conversation;
    }
}
