<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Course;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * Кто, кроме автора, допущен к приватному курсу — поимённо и группами.
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
     * @param  list<int>  $groupIds  группы, впущенные целиком (2026-09-12)
     */
    public function handle(Course $course, array $userIds, array $groupIds, User $actor): Course
    {
        // Автор в списке не состоит: его доступ следует из авторства, и строка
        // о нём означала бы, что доступ можно снять, — а его нельзя.
        $wanted = array_values(array_diff(
            array_unique(array_map(intval(...), $userIds)),
            [$course->author_id],
        ));

        $wantedGroups = array_values(array_unique(array_map(intval(...), $groupIds)));

        DB::transaction(function () use ($course, $wanted, $wantedGroups, $actor): void {
            $this->apply($course->members(), $wanted, 'users.id', $actor);
            $this->apply($course->memberGroups(), $wantedGroups, 'groups.id', $actor);
        });

        return $course->load('members', 'memberGroups');
    }

    /**
     * Привести список к присланному.
     *
     * Один способ на людей и на группы: правило у них общее — лишних убрать,
     * новых добавить, уже впущенных не трогать.
     *
     * @param  BelongsToMany<Model, Course>  $relation
     * @param  list<int>  $wanted
     */
    private function apply(BelongsToMany $relation, array $wanted, string $key, User $actor): void
    {
        $current = $relation->pluck($key)->map(intval(...))->all();

        $relation->detach(array_values(array_diff($current, $wanted)));

        $added = array_values(array_diff($wanted, $current));

        if ($added !== []) {
            // Пропущенным через attach, а не sync: sync переписал бы
            // «кто открыл доступ» у тех, кого впустили до этого.
            $relation->attach($added, ['granted_by_id' => $actor->getKey()]);
        }
    }
}
