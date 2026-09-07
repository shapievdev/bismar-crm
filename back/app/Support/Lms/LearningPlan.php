<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\AccessLevel;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningPlanItem;
use App\Models\Regulation;
use App\Models\User;
use Carbon\CarbonImmutable as Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * План обучения сотрудника как очередь: что он проходит сейчас и что из-за
 * этого закрыто.
 *
 * Порядок шагов был советом и стал запретом (решение пользователя
 * 2026-09-05). Правило одно и читается так: пока в плане есть непройденный
 * шаг, сотруднику открыт ровно один курс — тот, до которого дошла очередь.
 * Остальные курсы — и следующие по плану, и любые посторонние из каталога —
 * закрыты, пока план не пройден целиком.
 *
 * Запрет касается только курсов (решение пользователя 2026-09-07; день до
 * этого он накрывал и документы со справочниками). Документы читают свободно:
 * к правилу компании приходят за ответом, а не за обучением, и закрыть его до
 * конца плана значит закрыть в тот единственный момент, когда оно
 * понадобилось. Шагом плана документ при этом остаётся и очередь держит
 * наравне с курсом: не прочитан — курсы закрыты.
 *
 * Пустой план не запрещает ничего: у большинства сотрудников его нет вовсе, и
 * «нечего проходить» не должно читаться как «нельзя ничего».
 *
 * Шаги, которых сотрудник не видит, в очередь не входят: закрытый от него
 * курс он не пройдёт никогда, и такой шаг запер бы каталог насовсем.
 */
final class LearningPlan
{
    /** Что вообще бывает шагом плана. */
    private const KINDS = [Course::class, Regulation::class];

    /**
     * Курсы, закрытые этим человеком целиком. Считаются при первом вопросе:
     * каталог спрашивает про пятнадцать курсов подряд, и ходить за одним и тем
     * же списком пятнадцать раз незачем.
     *
     * @var list<int>|null
     */
    private ?array $finished = null;

    /**
     * @param  Collection<int, LearningPlanItem>  $steps  по порядку
     * @param  array<int, ?Carbon>  $completions  номер шага => когда пройден
     */
    private function __construct(
        private readonly User $learner,
        private readonly Collection $steps,
        private readonly array $completions,
    ) {}

    /**
     * План этого сотрудника — тот, по которому решается доступ.
     */
    public static function of(User $learner): self
    {
        $steps = $learner->planItems()
            // Условие одно на оба вида: у курса и у регламента область чтения
            // называется одинаково — scopeVisibleTo.
            ->whereHasMorph(
                'plannable',
                self::KINDS,
                fn (Builder $query) => $query->visibleTo($learner),
            )
            ->with('plannable')
            ->get();

        return self::over($learner, $steps);
    }

    /**
     * План из уже прочитанных шагов — когда их только что взяли из базы и
     * второй такой же запрос был бы данью удобству.
     *
     * @param  Collection<int, LearningPlanItem>  $steps
     */
    public static function over(User $learner, Collection $steps): self
    {
        $ordered = $steps->sortBy([['position', 'asc'], ['id', 'asc']])->values();

        return new self($learner, $ordered, app(StepCompletion::class)->of($learner, $ordered));
    }

    /**
     * Действует ли очередь на этого человека.
     *
     * Администратора она не касается вовсе, как и того, кто курсы пишет или
     * ведёт обучение: редактор иначе не открыл бы курс, который сам же и
     * составляет, а тому, кто назначает план, пришлось бы проходить его
     * самому, чтобы посмотреть, что он назначил.
     */
    public static function restrains(User $user): bool
    {
        return $user->accessLevel() === AccessLevel::User
            && $user->cannot(Permission::UpdateCourses->value)
            && $user->cannot(Permission::ManageEnrollments->value);
    }

    /**
     * Шаг, до которого дошла очередь, — первый непройденный. Пусто, когда
     * плана нет или он пройден целиком.
     */
    public function currentStep(): ?LearningPlanItem
    {
        return $this->steps->first(fn (LearningPlanItem $step): bool => ! $this->isCompleted($step));
    }

    /**
     * Пройден ли план — и потому открыт ли каталог.
     */
    public function isFinished(): bool
    {
        return $this->currentStep() === null;
    }

    public function isCompleted(LearningPlanItem $step): bool
    {
        return $this->completedAt($step) !== null;
    }

    public function completedAt(LearningPlanItem $step): ?Carbon
    {
        return $this->completions[(int) $step->getKey()] ?? null;
    }

    /**
     * Открыт ли курс — по очереди плана, а не по правам: права спрашивают
     * отдельно и раньше.
     *
     * Пройденное остаётся открытым навсегда. Запрет тут про то, чтобы не
     * забегать вперёд и не уходить в сторону, а не про то, чтобы отбирать
     * прочитанное: за пройденным курсом возвращаются перечитать, и разбор
     * своей же попытки теста живёт там же.
     */
    public function allows(Course $course): bool
    {
        $current = $this->currentStep();

        if ($current === null) {
            return true;
        }

        if ($current->plannable instanceof Course && (int) $current->plannable_id === (int) $course->getKey()) {
            return true;
        }

        return in_array((int) $course->getKey(), $this->finishedCourseIds(), strict: true);
    }

    /**
     * Курсы, которые этот человек уже закрыл, — все, а не только плановые:
     * курс, пройденный до того, как план назначили, тоже пройден.
     *
     * @return list<int>
     */
    private function finishedCourseIds(): array
    {
        return $this->finished ??= Enrollment::query()
            ->where('user_id', $this->learner->getKey())
            ->whereNotNull('completed_at')
            ->pluck('course_id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Почему курс не открылся — словами, которые увидит сотрудник.
     *
     * Называет шаг, на котором он стоит: «завершите план» без имени того, что
     * проходить, отправляет искать это самому.
     */
    public function refusal(): string
    {
        $current = $this->currentStep();
        $title = $current?->plannable?->title;

        if ($title === null) {
            return 'Курс откроется, когда вы завершите план обучения.';
        }

        $step = $current?->plannable instanceof Regulation
            ? 'прочитайте «'.$title.'»'
            : 'пройдите курс «'.$title.'»';

        return 'Курс откроется, когда вы завершите план обучения. Сейчас ваш шаг — '.$step.'.';
    }
}
