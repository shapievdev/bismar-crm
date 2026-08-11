<?php

declare(strict_types=1);

namespace App\Actions\Chat;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Состав группы: кого позвали, кто ушёл.
 *
 * Каждая правка отмечается в самой ленте. Без этого в группе внезапно появляется
 * новый голос, и понять, откуда он, участникам неоткуда — а через неделю и тому,
 * кто добавлял.
 *
 * Ушедший не удаляется, а помечается: его прошлые сообщения остаются в
 * переписке, и подписывать их нечем, если строки о нём не станет.
 */
final readonly class ManageParticipants
{
    public function __construct(private SayInConversation $say) {}

    /**
     * @param  list<int>  $userIds
     */
    public function add(Conversation $conversation, array $userIds, User $actor): Conversation
    {
        $wanted = array_values(array_unique(array_map(intval(...), $userIds)));

        $added = DB::transaction(function () use ($conversation, $wanted): array {
            $existing = $conversation->participants()->pluck('users.id')->all();

            // Вернувшийся не заводится заново: у него уже есть строка, и в ней
            // помнится, до какого места он дочитал в прошлый раз.
            $returning = array_values(array_intersect($wanted, $existing));
            $fresh = array_values(array_diff($wanted, $existing));

            foreach ($returning as $id) {
                $conversation->participants()->updateExistingPivot($id, ['left_at' => null]);
            }

            if ($fresh !== []) {
                $conversation->participants()->attach($fresh);
            }

            return [...$returning, ...$fresh];
        });

        if ($added === []) {
            return $conversation;
        }

        $names = User::query()->whereKey($added)->get()->map(fn (User $one): string => $one->name);

        $this->say->system($conversation, sprintf(
            '%s добавил в группу: %s',
            $actor->name,
            $names->implode(', '),
        ));

        return $conversation->load('activeParticipants');
    }

    public function remove(Conversation $conversation, User $person, User $actor): Conversation
    {
        $conversation->participants()->updateExistingPivot($person->getKey(), ['left_at' => now()]);

        $this->say->system($conversation, $person->is($actor)
            ? sprintf('%s вышел из группы', $person->name)
            : sprintf('%s убрал из группы: %s', $actor->name, $person->name));

        return $conversation->load('activeParticipants');
    }
}
