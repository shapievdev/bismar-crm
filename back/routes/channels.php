<?php

declare(strict_types=1);

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * Кого куда пускают в реальном времени.
 *
 * Проверка здесь — единственная: сокет-сервер сам ничего не знает ни о курсах,
 * ни о переписках, он лишь верит подписи, которую поставило приложение. Пустая
 * или неверная проверка означает, что чужую переписку можно читать, зная её
 * номер.
 */

// Личный канал: список переписок и счётчик непрочитанного.
Broadcast::channel('users.{id}', fn (User $user, int $id): bool => $user->getKey() === $id);

// Лента переписки — только тем, кто в ней состоит сейчас.
Broadcast::channel('conversations.{conversation}', function (User $user, int $conversation): bool {
    $found = Conversation::query()->find($conversation);

    return $found !== null && $found->includes($user);
});

/*
 * Кто сейчас в сети.
 *
 * Presence-канал на всех: сокет-сервер сам ведёт список подключённых и
 * рассылает приход и уход, поэтому «онлайн» не стоит ни одной строки в базе и
 * ни одного опроса. Отдаётся ровно то, что и так видно каждому сотруднику в
 * списке коллег.
 */
Broadcast::presence('presence.employees', fn (User $user): array => [
    'id' => $user->getKey(),
    'name' => $user->name,
    'avatar_url' => $user->avatarUrl(),
]);
