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
 *
 * Шаг — курс или регламент. Что именно, действие не разбирает: ему приходят
 * пары «вид и номер», а какие виды бывают, знает карта в AppServiceProvider.
 */
final readonly class SyncLearningPlan
{
    /**
     * @param  list<array{type: string, id: int}>  $items  В том порядке, в каком проходить.
     * @return Collection<int, LearningPlanItem>
     */
    public function handle(User $learner, array $items, User $actor): Collection
    {
        $wanted = $this->unique($items);

        DB::transaction(function () use ($learner, $wanted, $actor): void {
            $this->dropEverythingBut($learner, $wanted);

            foreach ($wanted as $index => $item) {
                $row = LearningPlanItem::firstOrNew([
                    'user_id' => $learner->getKey(),
                    'plannable_type' => $item['type'],
                    'plannable_id' => $item['id'],
                ]);

                // Кто назначил — только у новой строки. Переставить шаг местами
                // не значит назначить его заново, а «почему это в моём плане»
                // спрашивают про того, кто его туда поставил.
                if (! $row->exists) {
                    $row->assigned_by_id = $actor->getKey();
                }

                $row->position = $index + 1;
                $row->save();
            }
        });

        return $learner->planItems()->with('plannable')->get();
    }

    /**
     * @param  list<array{type: string, id: int}>  $wanted
     */
    private function dropEverythingBut(User $learner, array $wanted): void
    {
        // Через модель, а не через связь: у связи есть сортировка, а
        // DELETE ... ORDER BY Postgres не понимает.
        $query = LearningPlanItem::query()->where('user_id', $learner->getKey());

        // Пустой список сносит план целиком. Иначе перечисляем то, что
        // остаётся, парами: номер сам по себе ничего не значит — курс №3 и
        // регламент №3 разные вещи.
        if ($wanted !== []) {
            $query->whereNot(function ($inner) use ($wanted): void {
                foreach ($wanted as $item) {
                    $inner->orWhere(fn ($pair) => $pair
                        ->where('plannable_type', $item['type'])
                        ->where('plannable_id', $item['id']));
                }
            });
        }

        $query->delete();
    }

    /**
     * @param  list<array{type: string, id: int}>  $items
     * @return list<array{type: string, id: int}>
     */
    private function unique(array $items): array
    {
        $seen = [];
        $result = [];

        foreach ($items as $item) {
            $key = $item['type'].':'.$item['id'];

            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = ['type' => $item['type'], 'id' => (int) $item['id']];
            }
        }

        return $result;
    }
}
