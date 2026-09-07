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
 * Запрет накрывает всю базу знаний — курсы, документы и справочники (решение
 * пользователя 2026-09-05). Сначала он касался одних курсов, но половинчатым
 * правилом обходился сам себя: содержание курса нередко лежит и в документе,
 * и «пройди сначала своё» читалось как «пройди, если не найдёшь обходной
 * дороги».
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
     * Что этот человек уже прошёл — по видам материала. Считается при первом
     * вопросе: каталог спрашивает про пятнадцать строк подряд, и ходить за
     * одним и тем же списком пятнадцать раз незачем.
     *
     * @var array<class-string, list<int>>|null
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
     * Открыт ли материал — по очереди плана, а не по правам: права спрашивают
     * отдельно и раньше.
     *
     * Пройденное остаётся открытым навсегда. Запрет тут про то, чтобы не
     * забегать вперёд и не уходить в сторону, а не про то, чтобы отбирать
     * прочитанное: за пройденным курсом возвращаются перечитать, разбор своей
     * же попытки теста живёт там же, а правило, под которым однажды
     * расписались, обязаны показывать по первому требованию.
     */
    public function allows(Course|Regulation $material): bool
    {
        if ($this->currentStep() === null) {
            return true;
        }

        return $this->isCurrent($material) || $this->isPassed($material);
    }

    /**
     * Тот ли это материал, до которого дошла очередь.
     *
     * Сверяется и вид, и номер: документ и курс нумеруются каждый со своей
     * единицы, и один только номер открыл бы курс № 3 на шаге «документ № 3».
     */
    private function isCurrent(Course|Regulation $material): bool
    {
        $current = $this->currentStep();

        return $current?->plannable_type === $material->getMorphClass()
            && (int) $current->plannable_id === (int) $material->getKey();
    }

    /**
     * Пройден ли материал этим человеком — где угодно, не только в плане:
     * курс, закрытый до того, как план назначили, тоже закрыт, а документ, под
     * которым расписались, тоже прочитан.
     */
    private function isPassed(Course|Regulation $material): bool
    {
        $this->finished ??= [
            Course::class => Enrollment::query()
                ->where('user_id', $this->learner->getKey())
                ->whereNotNull('completed_at')
                ->pluck('course_id')
                ->map(intval(...))
                ->all(),
            Regulation::class => app(StepCompletion::class)->documentsPassedBy($this->learner),
        ];

        return in_array((int) $material->getKey(), $this->finished[$material::class], strict: true);
    }

    /**
     * Почему материал не открылся — словами, которые увидит сотрудник.
     *
     * Называет шаг, на котором он стоит: «завершите план» без имени того, что
     * проходить, отправляет искать это самому.
     */
    public function refusal(Course|Regulation $material): string
    {
        $refused = $this->nameOf($material);
        $current = $this->currentStep();
        $title = $current?->plannable?->title;

        if ($title === null) {
            return $refused.' откроется, когда вы завершите план обучения.';
        }

        $step = $current?->plannable instanceof Regulation
            ? 'прочитайте «'.$title.'»'
            : 'пройдите курс «'.$title.'»';

        return $refused.' откроется, когда вы завершите план обучения. Сейчас ваш шаг — '.$step.'.';
    }

    /**
     * Как материал называется на экране: у документа и справочника разделы
     * разные, и «курс закрыт» на странице справочника читалось бы ошибкой.
     */
    private function nameOf(Course|Regulation $material): string
    {
        return $material instanceof Regulation ? $material->kind->label() : 'Курс';
    }
}
