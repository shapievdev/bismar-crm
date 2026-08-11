<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Кто отвечает за курс.
 *
 * Список задаётся целиком, как и список доступа: экран показывает его весь, и
 * «сохранить» там означает «пусть будет вот так». См. SyncCourseAccess — там же
 * изложено, почему это правильнее посылки правок по одной.
 *
 * Автор из списка не исключается, в отличие от доступа: он вправе отвечать за
 * свой курс, а вправе и не отвечать — собрал материал и передал его тем, кто
 * работает с этим каждый день.
 */
final readonly class SyncCourseExperts
{
    /**
     * @param  list<int>  $userIds
     */
    public function handle(Course $course, array $userIds, User $actor): Course
    {
        $wanted = array_values(array_unique(array_map(intval(...), $userIds)));

        DB::transaction(function () use ($course, $wanted, $actor): void {
            $current = $course->experts()->pluck('users.id')->all();

            $course->experts()->detach(array_values(array_diff($current, $wanted)));

            $added = array_values(array_diff($wanted, $current));

            if ($added !== []) {
                // Через attach, а не sync: sync переписал бы «кто назначил» у
                // тех, кого назначили раньше.
                $course->experts()->attach($added, ['appointed_by_id' => $actor->getKey()]);
            }
        });

        return $course->load('experts');
    }
}
