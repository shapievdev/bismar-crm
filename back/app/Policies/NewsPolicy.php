<?php

declare(strict_types=1);

namespace App\Policies;

use App\Enums\Permission;
use App\Models\News;
use App\Models\User;

/**
 * Кто читает новость и кто её ведёт.
 *
 * Права на чтение нет: новости живут на главной странице, и вошедший видит
 * адресованное ему по определению. Всё остальное — под `news.manage`;
 * администраторы, как везде, проходят через Gate::before.
 */
class NewsPolicy
{
    public function view(User $user, News $news): bool
    {
        if ($user->can(Permission::ManageNews->value)) {
            return true;
        }

        // Черновик не показывают даже тому, кому он адресован: пока новость не
        // опубликована, её ещё пишут, и прочитанное может смениться.
        return $news->isPublished() && $news->isAddressedTo($user);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ManageNews->value);
    }

    public function update(User $user, News $news): bool
    {
        return $user->can(Permission::ManageNews->value);
    }

    public function delete(User $user, News $news): bool
    {
        return $user->can(Permission::ManageNews->value);
    }

    /**
     * Кто ознакомился, а кто нет.
     *
     * Отдельным правилом, потому что это список людей, а не текст новости:
     * читателю знать, кто ещё её не открыл, незачем.
     */
    public function viewAcknowledgements(User $user, News $news): bool
    {
        return $user->can(Permission::ManageNews->value);
    }

    /**
     * Отметиться можно только на том, что вправе прочитать.
     */
    public function acknowledge(User $user, News $news): bool
    {
        return $news->isPublished() && $news->isAddressedTo($user);
    }
}
