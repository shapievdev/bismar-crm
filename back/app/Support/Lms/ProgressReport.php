<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningPlanItem;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Как материал проходят — поимённо.
 *
 * Отличается от LearningReport тем же, чем вопрос от сводки: тот отвечает
 * «сколько всего и в среднем по компании», этот — «кто именно застрял на
 * третьем уроке». Отсюда и место: не в разделе аналитики, а внизу самой
 * страницы курса, урока и документа, где о них и думают.
 *
 * Уволенные не участвуют: отчёт читают, чтобы решить, с кем сесть и разобрать
 * материал, а с ушедшим уже не сядешь.
 *
 * Цифры считаются сгруппированными запросами, а не ProgressCalculator'ом: тот
 * отвечает об одном человеке и ходит в базу трижды за каждого — на списке в
 * полсотни имён это полтораста запросов ради одной таблицы.
 */
final readonly class ProgressReport
{
    /**
     * Курс целиком: кто его проходит и как далеко ушёл.
     *
     * @return array{summary: array<string, int>, people: list<array<string, mixed>>}
     */
    public function onCourse(Course $course): array
    {
        $lessonIds = $course->lessons()->pluck('lessons.id')->all();
        $quizIds = $this->quizIdsFor('lesson', $lessonIds);

        $learners = $this->circle([
            Enrollment::query()->where('course_id', $course->getKey())->select('user_id'),
            $this->planned('course', (int) $course->getKey()),
        ]);

        $enrollments = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->whereIn('user_id', $learners->modelKeys())
            ->get()
            ->keyBy('user_id');

        // Пройденные уроки считаются по тем, что в курсе есть сейчас: удалённый
        // урок не должен оставлять человека выше ста процентов.
        $completed = DB::table('lesson_completions')
            ->join('enrollments', 'enrollments.id', '=', 'lesson_completions.enrollment_id')
            ->where('enrollments.course_id', $course->getKey())
            ->whereIn('lesson_completions.lesson_id', $lessonIds === [] ? [0] : $lessonIds)
            ->groupBy('enrollments.user_id')
            ->selectRaw('enrollments.user_id as user_id, count(*) as total')
            ->pluck('total', 'user_id');

        $passed = $this->passedQuizzesBy($quizIds);
        $inPlan = $this->plannedUserIds('course', (int) $course->getKey());

        $lessons = count($lessonIds);

        $people = $learners->map(function (User $learner) use ($enrollments, $completed, $passed, $inPlan, $lessons, $quizIds): array {
            $enrollment = $enrollments->get($learner->getKey());
            $done = (int) ($completed[$learner->getKey()] ?? 0);

            return [
                ...$this->person($learner, in_array((int) $learner->getKey(), $inPlan, strict: true)),

                'is_enrolled' => $enrollment !== null,
                'status' => $this->status($enrollment, $done),
                'progress' => $lessons === 0 ? 0 : (int) round(min($done, $lessons) / $lessons * 100),

                'lessons_done' => min($done, $lessons),
                'lessons' => $lessons,

                'quizzes_passed' => (int) ($passed[$learner->getKey()] ?? 0),
                'quizzes' => count($quizIds),

                'started_at' => $enrollment?->started_at?->toIso8601String(),
                'completed_at' => $enrollment?->completed_at?->toIso8601String(),
            ];
        });

        return [
            'summary' => [
                'people' => $people->count(),
                'not_started' => $people->where('status', 'not_started')->count(),
                'in_progress' => $people->where('status', 'in_progress')->count(),
                'completed' => $people->where('status', 'completed')->count(),

                // Средний прогресс — по всем, кому курс адресован, включая не
                // приступавших: считать его по одним начавшим значит показывать
                // курс тем успешнее, чем меньше людей до него дошло.
                'average_progress' => $people->isEmpty() ? 0 : (int) round($people->avg('progress')),

                'lessons' => $lessons,
                'quizzes' => count($quizIds),
            ],
            'people' => $this->ordered($people),
        ];
    }

    /**
     * Один человек по урокам — то, что раскрывается у строки отчёта.
     *
     * Отдельным адресом, а не внутри общего ответа: уроков в курсе бывает
     * тридцать, людей — сотня, и присылать все три тысячи строк ради одной
     * раскрытой значит присылать штат, помноженный на программу.
     *
     * @return array{learner: array<string, mixed>, lessons: list<array<string, mixed>>}
     */
    public function ofLearner(Course $course, User $learner): array
    {
        $course->loadMissing('modules.lessons.quiz');

        $enrollment = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('user_id', $learner->getKey())
            ->first();

        $completions = $enrollment === null
            ? collect()
            : $enrollment->completions()->pluck('completed_at', 'lesson_id');

        $attempts = $this->attemptsOf(
            $learner,
            $this->quizIdsFor('lesson', $course->lessons()->pluck('lessons.id')->all()),
        );

        $lessons = [];

        foreach ($course->modules->sortBy('position') as $module) {
            foreach ($module->lessons->sortBy('position') as $lesson) {
                $quiz = $lesson->quiz;

                $lessons[] = [
                    'id' => (int) $lesson->getKey(),
                    'title' => $lesson->title,
                    'module' => $module->title,

                    'is_done' => $completions->has($lesson->getKey()),
                    'done_at' => $this->moment($completions->get($lesson->getKey())),

                    'quiz' => $quiz === null
                        ? null
                        : $this->attemptSummary($attempts->get($quiz->getKey()), $quiz),
                ];
            }
        }

        return [
            'learner' => $this->identity($learner),
            'lessons' => $lessons,
        ];
    }

    /**
     * Один урок: кто его закрыл и чем кончился тест.
     *
     * Круг тот же, что у курса, плюс отправлявшие ответы: попытка без записи
     * на курс — редкость, но человек, который тест уже писал, не должен
     * пропасть из списка только потому, что записи не нашлось.
     *
     * @return array{summary: array<string, int>, people: list<array<string, mixed>>}
     */
    public function onLesson(Lesson $lesson): array
    {
        $course = $lesson->course();
        $quiz = $lesson->loadMissing('quiz')->quiz;

        $sources = $course === null ? [] : [
            Enrollment::query()->where('course_id', $course->getKey())->select('user_id'),
            $this->planned('course', (int) $course->getKey()),
        ];

        if ($quiz !== null) {
            $sources[] = QuizAttempt::query()->where('quiz_id', $quiz->getKey())->select('user_id');
        }

        $learners = $this->circle($sources);

        $completions = DB::table('lesson_completions')
            ->join('enrollments', 'enrollments.id', '=', 'lesson_completions.enrollment_id')
            ->where('lesson_completions.lesson_id', $lesson->getKey())
            ->whereIn('enrollments.user_id', $learners->modelKeys())
            ->pluck('lesson_completions.completed_at', 'enrollments.user_id');

        $attempts = $quiz === null ? collect() : $this->attemptsByUser((int) $quiz->getKey());
        $inPlan = $course === null ? [] : $this->plannedUserIds('course', (int) $course->getKey());

        $people = $learners->map(fn (User $learner): array => [
            ...$this->person($learner, in_array((int) $learner->getKey(), $inPlan, strict: true)),

            'is_done' => $completions->has($learner->getKey()),
            'done_at' => $this->moment($completions->get($learner->getKey())),

            'quiz' => $quiz === null
                ? null
                : $this->attemptSummary($attempts->get($learner->getKey()), $quiz),
        ]);

        return [
            'summary' => $this->materialSummary($people, $quiz),
            'people' => $this->ordered($people),
        ];
    }

    /**
     * Документ или справочник: кто с ним ознакомился.
     *
     * Ознакомление — весь прогресс, какой у документа бывает; при проверке его
     * заменяет сдача. Поэтому в списке стоят и отправлявшие ответы: не сдавший
     * проверку не ознакомлен, но он к документу приходил, и молчать о нём
     * значит терять ровно тех, с кем и надо разговаривать.
     *
     * @return array{summary: array<string, int>, people: list<array<string, mixed>>}
     */
    public function onRegulation(Regulation $regulation): array
    {
        $quiz = $regulation->loadMissing('quiz')->quiz;

        /*
         * Проверок у документа с версиями столько, сколько версий (2026-09-12):
         * каждый проходит свою. Отчёт поэтому смотрит на все разом — иначе
         * сдавший проверку своей версии числился бы непроходившим, а
         * разговаривать пошли бы не с тем.
         */
        $quizzes = $regulation->versions()->with('quiz')->get()
            ->map(static fn (RegulationVersion $version): ?Quiz => $version->quiz)
            ->filter()
            ->when($quiz !== null, static fn (Collection $all): Collection => $all->prepend($quiz))
            ->values();

        // Номерами, а не modelKeys(): собранный через map() список — обычная
        // коллекция, и об Eloquent она ничего не знает.
        $quizIds = $quizzes->map(static fn (Quiz $one): int => (int) $one->getKey())->all();

        $sources = [
            DB::table('regulation_acknowledgements')
                ->where('regulation_id', $regulation->getKey())
                ->select('user_id'),
            $this->planned('regulation', (int) $regulation->getKey()),
        ];

        if ($quizzes->isNotEmpty()) {
            $sources[] = QuizAttempt::query()
                ->whereIn('quiz_id', $quizIds)
                ->select('user_id');
        }

        $learners = $this->circle($sources);

        $acknowledgements = DB::table('regulation_acknowledgements')
            ->where('regulation_id', $regulation->getKey())
            ->whereIn('user_id', $learners->modelKeys())
            ->get()
            ->keyBy('user_id');

        // Названия версий — чтобы в строке было видно, чью версию человек
        // читал: у кого какая, там и спрашивать.
        $names = $regulation->versions()->pluck('name', 'id');

        $attempts = $quizIds === [] ? collect() : $this->attemptsAcross($quizIds);
        $inPlan = $this->plannedUserIds('regulation', (int) $regulation->getKey());

        $people = $learners->map(function (User $learner) use ($acknowledgements, $attempts, $inPlan, $quizzes, $names): array {
            $acknowledgement = $acknowledgements->get($learner->getKey());
            $own = $attempts->get($learner->getKey());

            return [
                ...$this->person($learner, in_array((int) $learner->getKey(), $inPlan, strict: true)),

                'is_done' => $acknowledgement !== null,
                'done_at' => $this->moment($acknowledgement?->acknowledged_at),

                // По какой версии он ознакомился. Null — по общей, то есть по
                // самому документу.
                'version' => $names->get($acknowledgement?->version_id),

                // Проверка своя у каждой версии, а колонка одна: показываем ту,
                // которую человек проходил. Название теста при ней и стоит.
                'quiz' => $quizzes->isEmpty() || $own === null
                    ? ($quizzes->isEmpty() ? null : $this->attemptSummary(null, $quizzes->first()))
                    : $this->attemptSummary($own, $own->first()->quiz),
            ];
        });

        return [
            'summary' => $this->materialSummary($people, $quizzes->first()),
            'people' => $this->ordered($people),
        ];
    }

    /**
     * Круг людей, о которых идёт речь: начавшие и те, кому материал назначен
     * планом (решение пользователя 2026-09-12).
     *
     * Не весь штат: документ открыт всякому, кто читает базу знаний, и «ещё не
     * ознакомился» означало бы там расписание компании вместо отчёта. Не одни
     * начавшие: тогда назначенный, но не открытый материал выглядел бы
     * пройденным целиком — некому проваливать.
     *
     * @param  list<Builder<*>|QueryBuilder>  $sources  выборки номеров сотрудников
     * @return EloquentCollection<int, User>
     */
    private function circle(array $sources): EloquentCollection
    {
        if ($sources === []) {
            return new EloquentCollection;
        }

        return User::query()
            ->employed()
            ->where(function (Builder $query) use ($sources): void {
                foreach ($sources as $source) {
                    $query->orWhereIn('users.id', $source);
                }
            })
            ->get();
    }

    /**
     * Номера тестов, висящих на материале.
     *
     * @param  list<int|string>  $ownerIds
     * @return list<int>
     */
    private function quizIdsFor(string $type, array $ownerIds): array
    {
        if ($ownerIds === []) {
            return [];
        }

        return Quiz::query()
            ->where('quizzable_type', $type)
            ->whereIn('quizzable_id', $ownerIds)
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Сколько тестов курса человек сдал. По тестам, а не по попыткам: сдавший с
     * третьего раза сдал один тест, а не три.
     *
     * @param  list<int>  $quizIds
     * @return Collection<int, int>
     */
    private function passedQuizzesBy(array $quizIds): Collection
    {
        if ($quizIds === []) {
            return collect();
        }

        return DB::table('quiz_attempts')
            ->whereIn('quiz_id', $quizIds)
            ->where('passed', true)
            ->groupBy('user_id')
            ->selectRaw('user_id, count(distinct quiz_id) as total')
            ->pluck('total', 'user_id');
    }

    /**
     * Попытки одного теста, сложенные по людям.
     *
     * @return Collection<int, EloquentCollection<int, QuizAttempt>>
     */
    private function attemptsByUser(int $quizId): Collection
    {
        return QuizAttempt::query()
            ->where('quiz_id', $quizId)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');
    }

    /**
     * Попытки нескольких тестов сразу, сложенные по людям.
     *
     * У документа с версиями проверок столько, сколько версий, и каждый
     * проходит свою: спрашивать по одной значило бы потерять всех, кто читал
     * не общий текст. Сам тест едет вместе с попыткой — по нему в строке
     * пишут, какую проверку человек проходил.
     *
     * @param  list<int|string>  $quizIds
     * @return Collection<int, EloquentCollection<int, QuizAttempt>>
     */
    private function attemptsAcross(array $quizIds): Collection
    {
        return QuizAttempt::query()
            ->whereIn('quiz_id', $quizIds)
            ->with('quiz')
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get()
            ->groupBy('user_id');
    }

    /**
     * Попытки одного человека по нескольким тестам, сложенные по тестам.
     *
     * @param  list<int>  $quizIds
     * @return Collection<int, EloquentCollection<int, QuizAttempt>>
     */
    private function attemptsOf(User $learner, array $quizIds): Collection
    {
        if ($quizIds === []) {
            return collect();
        }

        return QuizAttempt::query()
            ->where('user_id', $learner->getKey())
            ->whereIn('quiz_id', $quizIds)
            ->orderBy('completed_at')
            ->orderBy('id')
            ->get()
            ->groupBy('quiz_id');
    }

    /**
     * Чем кончился тест у одного человека.
     *
     * Лучшая попытка, а не последняя: пересдача существует затем, чтобы
     * засчитать лучшую, и «сдал со второго раза» — это сдал.
     *
     * @param  EloquentCollection<int, QuizAttempt>|null  $attempts
     * @return array<string, mixed>
     */
    private function attemptSummary(?EloquentCollection $attempts, Quiz $quiz): array
    {
        $last = $attempts?->last();

        return [
            'id' => (int) $quiz->getKey(),
            'title' => $quiz->title,

            // Аттестацию читает человек, и «сдал» у неё появляется не в момент
            // отправки: пока работа ждёт проверяющего, об этом надо сказать
            // прямо, иначе ноль у фамилии читается как провал.
            'is_attestation' => $quiz->isAttestation(),
            'awaits_review' => $last?->isAwaitingReview() ?? false,

            'attempts' => $attempts?->count() ?? 0,
            'best_score' => $attempts === null || $attempts->isEmpty() ? null : (int) $attempts->max('score'),
            'passed' => $attempts?->contains('passed', true) ?? false,
            'last_at' => $last?->completed_at?->toIso8601String(),
        ];
    }

    /**
     * Сводка урока и документа: пройдено столькими из стольких.
     *
     * @param  Collection<int, array<string, mixed>>  $people
     * @return array<string, int>
     */
    private function materialSummary(Collection $people, ?Quiz $quiz): array
    {
        $summary = [
            'people' => $people->count(),
            'done' => $people->where('is_done', true)->count(),
        ];

        if ($quiz === null) {
            return $summary;
        }

        return [
            ...$summary,
            'attempted' => $people->filter(fn (array $person): bool => ($person['quiz']['attempts'] ?? 0) > 0)->count(),
            'passed' => $people->filter(fn (array $person): bool => ($person['quiz']['passed'] ?? false) === true)->count(),
        ];
    }

    /**
     * Общая часть строки о человеке.
     *
     * @return array<string, mixed>
     */
    private function person(User $learner, bool $inPlan): array
    {
        return [
            ...$this->identity($learner),

            // Назначено планом или взято самим: у первого спрашивают, почему не
            // сделано, у второго — нет.
            'in_plan' => $inPlan,
        ];
    }

    /**
     * Кто это — без всякого отношения к материалу.
     *
     * @return array<string, mixed>
     */
    private function identity(User $learner): array
    {
        return [
            'id' => (int) $learner->getKey(),
            'name' => $learner->name,
            'job_title' => $learner->job_title,
            'avatar_url' => $learner->avatarUrl(),
        ];
    }

    /**
     * Вперёд — те, у кого дело не сдвинулось.
     *
     * Список читают, чтобы решить, кому напомнить и с кем сесть, а не чтобы
     * полюбоваться закончившими: не начинал, идёт, закончил — и по имени внутри
     * каждой ступени.
     *
     * @param  Collection<int, array<string, mixed>>  $people
     * @return list<array<string, mixed>>
     */
    private function ordered(Collection $people): array
    {
        return $people
            ->sortBy(fn (array $person): string => $this->rank($person).mb_strtolower((string) $person['name']))
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $person
     */
    private function rank(array $person): string
    {
        $status = $person['status'] ?? ($person['is_done'] === true ? 'completed' : 'not_started');

        return match ($status) {
            'completed' => '2',
            'in_progress' => '1',
            default => '0',
        };
    }

    /**
     * Приступал ли человек к курсу и дошёл ли до конца.
     */
    private function status(?Enrollment $enrollment, int $completedLessons): string
    {
        if ($enrollment === null) {
            return 'not_started';
        }

        if ($enrollment->isCompleted()) {
            return 'completed';
        }

        return $enrollment->started_at !== null || $completedLessons > 0
            ? 'in_progress'
            : 'not_started';
    }

    /**
     * Выборка номеров сотрудников, у кого материал стоит в плане.
     *
     * @return Builder<LearningPlanItem>
     */
    private function planned(string $type, int $id): Builder
    {
        return LearningPlanItem::query()
            ->where('plannable_type', $type)
            ->where('plannable_id', $id)
            ->select('user_id');
    }

    /**
     * @return list<int>
     */
    private function plannedUserIds(string $type, int $id): array
    {
        return $this->planned($type, $id)->pluck('user_id')->map(intval(...))->all();
    }

    /**
     * Дата из сырой строки: запросы к таблицам идут мимо Eloquent, и приводить
     * время к одному виду приходится здесь.
     */
    private function moment(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return Carbon::parse((string) $value)->toIso8601String();
    }
}
