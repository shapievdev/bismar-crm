<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Course;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Кто, кроме автора, допущен к приватному курсу.
 *
 * Список задаётся целиком, а не по одному человеку: экран доступа показывает
 * его весь, и «сохранить» там означает «пусть будет вот так». Разница видна,
 * когда двое правят список одновременно, — но здесь это правильнее: увидеть в
 * приватном курсе человека, которого ты только что убрал, хуже, чем потерять
 * чужое добавление, о котором на экране и не говорилось.
 */
final readonly class SyncCourseAccess
{
    /**
     * @param  list<int>  $userIds
     */
    public function handle(Course $course, array $userIds, User $actor): Course
    {
        // Автор в списке не состоит: его доступ следует из авторства, и строка
        // о нём означала бы, что доступ можно снять, — а его нельзя.
        $wanted = array_values(array_diff(
            array_unique(array_map(intval(...), $userIds)),
            [$course->author_id],
        ));

        DB::transaction(function () use ($course, $wanted, $actor): void {
            $current = $course->members()->pluck('users.id')->all();

            $course->members()->detach(array_values(array_diff($current, $wanted)));

            $added = array_values(array_diff($wanted, $current));

            if ($added !== []) {
                // Пропущенным через attach, а не sync: sync переписал бы
                // «кто открыл доступ» у тех, кого впустили до этого.
                $course->members()->attach($added, ['granted_by_id' => $actor->getKey()]);
            }
        });

        return $course->load('members');
    }
}
