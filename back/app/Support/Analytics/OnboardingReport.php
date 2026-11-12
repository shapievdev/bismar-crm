<?php

declare(strict_types=1);

namespace App\Support\Analytics;

use App\Models\OnboardingJourney;
use App\Models\OnboardingTemplate;
use App\Models\QuizAttempt;
use App\Support\Structure\DepartmentReach;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * HR-отчёт по адаптации: как проходят маршруты.
 *
 * Считается по маршрутам, а не по шаблонам: вопросы отчёта — про людей
 * («сколько начали, сколько дошли, где буксуют»), и строка здесь — человек со
 * своей картой.
 *
 * Пустые маршруты не в счёт: карта заведена у каждого сотрудника, и считать
 * «начавшим» того, кому ещё ничего не положили, значило бы объявить всю
 * компанию проходящей адаптацию.
 *
 * Объёмы небольшие — десятки маршрутов, — поэтому считается на коллекциях, а
 * не сырым SQL: читаемость правил здесь дороже миллисекунд.
 */
final readonly class OnboardingReport
{
    public function __construct(private DepartmentReach $reach) {}

    /**
     * @param  array{from?: ?string, to?: ?string, template_id?: ?int, department_id?: ?int}  $filters
     * @return array<string, mixed>
     */
    public function build(array $filters): array
    {
        $journeys = $this->journeys($filters);

        $completed = $journeys->filter(fn (OnboardingJourney $journey): bool => $journey->completed_at !== null);

        return [
            'started' => $journeys->count(),
            'completed' => $completed->count(),
            'in_progress' => $journeys->count() - $completed->count(),
            'completion_rate' => $journeys->isEmpty()
                ? null
                : round($completed->count() / $journeys->count() * 100, 1),

            // Среднее время прохождения — календарными днями от старта до
            // завершения, по завершённым.
            'average_days' => $completed->isEmpty()
                ? null
                : round($completed->avg(fn (OnboardingJourney $journey): float => CarbonImmutable::parse($journey->started_at)
                    ->diffInDays($journey->completed_at, true)), 1),

            // Время до подтверждённого допуска к продажам.
            'average_days_to_access' => $this->averageDaysToAccess($journeys),

            ...$this->examFigures($journeys),

            'slowest_stages' => $this->slowestStages($journeys),
        ];
    }

    /**
     * @param  array{from?: ?string, to?: ?string, template_id?: ?int, department_id?: ?int}  $filters
     * @return Collection<int, OnboardingJourney>
     */
    private function journeys(array $filters): Collection
    {
        return OnboardingJourney::query()
            ->with(['stages.quiz.questions', 'user'])
            // Пустая карта — не начатый маршрут.
            ->whereHas('stages')
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query
                ->whereDate('started_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query
                ->whereDate('started_at', '<=', $to))
            // Шаблон у маршрута не один: фильтр ищет тех, кому его накладывали.
            ->when($filters['template_id'] ?? null, fn (Builder $query, int $template) => $query
                ->whereHas('stages', fn (Builder $stage) => $stage
                    ->whereIn('template_id', OnboardingTemplate::withTrashed()->where('id', $template)->orWhere('parent_id', $template)->pluck('id'))))
            ->when($filters['version_id'] ?? null, fn (Builder $q, int $id) => $q->whereHas('stages', fn ($s) => $s->where('template_id', $id)))
            ->when($filters['store'] ?? null, fn (Builder $q, string $store) => $q->where('store', $store))
            ->when($filters['department_id'] ?? null, fn (Builder $query, int $department) => $query
                ->whereHas('user.departments', fn (Builder $inner) => $inner
                    ->whereIn('departments.id', $this->reach->branch([$department]))))
            ->get();
    }

    /**
     * @param  Collection<int, OnboardingJourney>  $journeys
     */
    private function averageDaysToAccess(Collection $journeys): ?float
    {
        $admitted = $journeys->filter(
            fn (OnboardingJourney $journey): bool => $journey->access_confirmed_at !== null,
        );

        if ($admitted->isEmpty()) {
            return null;
        }

        return round($admitted->avg(
            fn (OnboardingJourney $journey): float => CarbonImmutable::parse($journey->started_at)
                ->diffInDays($journey->access_confirmed_at, true),
        ), 1);
    }

    /**
     * Экзамен маршрута: сдача с первой попытки и среднее число попыток.
     *
     * Экзаменом считается тест последнего этапа с тестом — в программе из ТЗ
     * это и есть итоговый экзамен. Доля — от садившихся, а не от всех: тот,
     * кто до экзамена не дошёл, о его сложности ничего не говорит.
     *
     * @param  Collection<int, OnboardingJourney>  $journeys
     * @return array<string, mixed>
     */
    private function examFigures(Collection $journeys): array
    {
        $pairs = $journeys
            ->map(function (OnboardingJourney $journey): ?array {
                $examStage = $journey->stages->firstWhere('is_final_exam', true) ?? $journey->stages
                    ->filter(fn ($stage): bool => $stage->quiz !== null && $stage->quiz->questions->isNotEmpty())
                    ->last();

                return $examStage === null || $examStage->quiz === null
                    ? null
                    : ['user_id' => (int) $journey->user_id, 'quiz_id' => (int) $examStage->quiz->getKey()];
            })
            ->filter()
            ->values();

        if ($pairs->isEmpty()) {
            return ['exam_first_try_rate' => null, 'exam_average_attempts' => null, 'exam_attempts' => 0];
        }

        $attempts = QuizAttempt::query()
            ->whereIn('quiz_id', $pairs->pluck('quiz_id')->unique())
            ->whereIn('user_id', $pairs->pluck('user_id')->unique())
            ->orderBy('completed_at')
            ->get()
            ->groupBy(fn (QuizAttempt $attempt): string => $attempt->quiz_id.':'.$attempt->user_id);

        $sat = 0;
        $firstTry = 0;
        $attemptCounts = [];

        foreach ($pairs as $pair) {
            $own = $attempts->get($pair['quiz_id'].':'.$pair['user_id']);

            if ($own === null || $own->isEmpty()) {
                continue;
            }

            $sat++;
            $attemptCounts[] = $own->count();

            if ((bool) $own->first()->passed) {
                $firstTry++;
            }
        }

        return [
            'exam_attempts' => array_sum($attemptCounts),
            'exam_first_try_rate' => $sat === 0 ? null : round($firstTry / $sat * 100, 1),
            'exam_average_attempts' => $attemptCounts === []
                ? null
                : round(array_sum($attemptCounts) / count($attemptCounts), 1),
        ];
    }

    /**
     * Этапы с наибольшими задержками: сколько дней в среднем лежат открытыми
     * до закрытия. Открытые и не закрытые считаются «до сегодня» — именно они
     * и есть задержка, о которой спрашивает HR.
     *
     * @param  Collection<int, OnboardingJourney>  $journeys
     * @return list<array{title: string, average_days: float, journeys: int}>
     */
    private function slowestStages(Collection $journeys): array
    {
        $now = CarbonImmutable::now();

        return $journeys
            ->flatMap(fn (OnboardingJourney $journey) => $journey->stages)
            // Этап без даты в задержках не участвует: ему нечего просрочить.
            ->filter(fn ($stage): bool => $stage->opens_at !== null && $stage->opens_at->isPast())
            ->groupBy(fn ($stage) => ($stage->template_id ?? 'personal').':'.($stage->source_stage_id ?? $stage->id))
            ->map(function (Collection $stages, string $title) use ($now): array {
                $days = $stages->avg(
                    fn ($stage): float => CarbonImmutable::parse($stage->opens_at)
                        ->diffInDays($stage->completed_at ?? $now, true),
                );

                return [
                    'title' => $stages->first()->title.($stages->first()->template_version ? ' · v'.$stages->first()->template_version : ''),
                    'average_delay_days' => round((float) $stages->filter(fn ($s) => $s->due_at !== null)->avg(fn ($s) => max(0, CarbonImmutable::parse($s->due_at)->diffInDays($s->completed_at ?? $now, false))), 1),
                    'average_days' => round((float) $days, 1),
                    'journeys' => $stages->count(),
                ];
            })
            ->sortByDesc(fn ($row) => $row['average_delay_days'] > 0 ? $row['average_delay_days'] + 1000000 : $row['average_days'])
            ->take(5)
            ->values()
            ->all();
    }
}
