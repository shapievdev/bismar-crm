<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Jobs\SendPush;
use App\Models\LearningPlanItem;
use App\Models\User;
use App\Support\Push\PushMessage;
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

        // Что было до правки — чтобы сказать сотруднику, что именно изменилось.
        // Читаем прежде транзакции: после неё прежнего плана уже нет.
        $before = $this->keysOf($learner);

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

        $plan = $learner->planItems()->with('plannable')->get();

        $this->notify($learner, $actor, $before, $plan);

        return $plan;
    }

    /**
     * Сообщает сотруднику, что план изменился.
     *
     * Только о составе: переставленные местами шаги — совет о порядке, а не
     * новое задание (порядок в плане и так не запрет), и звонить телефоном ради
     * этого незачем.
     *
     * Себе не сообщаем: тот, кто правит свой план, только что его и правил.
     *
     * Имя уведомления одно на сотрудника — новое **заменяет** прежнее: важно
     * не то, сколько раз план правили, а каким он стал.
     *
     * @param  list<string>  $before
     * @param  Collection<int, LearningPlanItem>  $plan
     */
    private function notify(User $learner, User $actor, array $before, Collection $plan): void
    {
        if ($learner->is($actor)) {
            return;
        }

        $after = $plan->map($this->keyOf(...))->all();

        $added = $plan->filter(fn (LearningPlanItem $item): bool => ! in_array($this->keyOf($item), $before, true));
        $removed = array_values(array_diff($before, $after));

        if ($added->isEmpty() && $removed === []) {
            return;
        }

        SendPush::dispatch([(int) $learner->getKey()], new PushMessage(
            title: 'План обучения изменился',
            body: $this->summary($added, count($removed)),
            url: '/lms/plan',
            tag: 'learning-plan',
        ));
    }

    /**
     * О чём уведомление одной строкой.
     *
     * Название — когда добавили ровно один шаг: тогда оно и есть новость.
     * Дальше числами, иначе уведомление обрежется на середине второго названия.
     *
     * @param  Collection<int, LearningPlanItem>  $added
     */
    private function summary(Collection $added, int $removed): string
    {
        $count = $added->count();

        if ($count === 0) {
            return $removed === 1
                ? 'Один шаг убрали из плана.'
                : sprintf('Из плана убрали шагов: %d.', $removed);
        }

        /** @var LearningPlanItem $first */
        $first = $added->first();

        $news = $count === 1
            ? sprintf('Вам назначено: %s', PushMessage::shorten($first->plannable?->title, 80))
            : sprintf('Вам назначено новых шагов: %d', $count);

        return $removed === 0 ? $news.'.' : $news.sprintf(' (убрано: %d)', $removed);
    }

    /**
     * Ключи шагов плана: «course:3». Вид и номер вместе — курс №3 и регламент
     * №3 разные вещи.
     *
     * @return list<string>
     */
    private function keysOf(User $learner): array
    {
        return $learner->planItems()->get()->map($this->keyOf(...))->all();
    }

    private function keyOf(LearningPlanItem $item): string
    {
        return $item->plannable_type.':'.$item->plannable_id;
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
