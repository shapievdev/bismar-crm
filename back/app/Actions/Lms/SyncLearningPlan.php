<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\LearningPlanItem;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * План обучения сотрудника — целиком и разом.
 *
 * Список задаётся полностью, как и доступ к курсу (см. SyncCourseAccess): экран
 * показывает план весь, и «сохранить» там значит «пусть будет вот так». Порядок
 * присланного и есть порядок плана — номера шагов не приходят с клиента, их
 * расставляет эта строка кода, и разойтись им не с чем.
 */
final readonly class SyncLearningPlan
{
    /**
     * @param  list<int>  $courseIds  Курсы в том порядке, в каком их проходить.
     * @return Collection<int, LearningPlanItem>
     */
    public function handle(User $learner, array $courseIds, User $actor): Collection
    {
        $wanted = array_values(array_unique(array_map(intval(...), $courseIds)));

        DB::transaction(function () use ($learner, $wanted, $actor): void {
            // Через модель, а не через связь: у связи есть сортировка, а
            // DELETE ... ORDER BY Postgres не понимает. Пустой список сносит
            // план целиком — `whereNotIn` без значений это и означает.
            LearningPlanItem::query()
                ->where('user_id', $learner->getKey())
                ->whereNotIn('course_id', $wanted)
                ->delete();

            foreach ($wanted as $index => $courseId) {
                $item = LearningPlanItem::firstOrNew([
                    'user_id' => $learner->getKey(),
                    'course_id' => $courseId,
                ]);

                // Кто назначил — только у новой строки. Переставить шаг местами
                // не значит назначить его заново, а «почему это в моём плане»
                // спрашивают про того, кто его туда поставил.
                if (! $item->exists) {
                    $item->assigned_by_id = $actor->getKey();
                }

                $item->position = $index + 1;
                $item->save();
            }
        });

        return $learner->planItems()->with('course')->get();
    }
}
