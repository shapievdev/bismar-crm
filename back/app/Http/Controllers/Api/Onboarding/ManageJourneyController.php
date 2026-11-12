<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Onboarding;

use App\Actions\Lms\SaveQuiz;
use App\Actions\Onboarding\ApplyTemplate;
use App\Actions\Onboarding\ConfirmGraduation;
use App\Actions\Onboarding\ConfirmMeeting;
use App\Actions\Onboarding\ConfirmTask;
use App\Actions\Onboarding\DeleteJourneyStage;
use App\Actions\Onboarding\GrantExtraAttempt;
use App\Actions\Onboarding\MoveStageOpening;
use App\Actions\Onboarding\OpenJourney;
use App\Actions\Onboarding\PauseJourney;
use App\Actions\Onboarding\RefreshJourneyProgress;
use App\Actions\Onboarding\ReorderJourneyStages;
use App\Actions\Onboarding\ResumeJourney;
use App\Actions\Onboarding\SaveJourneyStage;
use App\Actions\Onboarding\ScheduleMeeting;
use App\Actions\Onboarding\UpdateJourneySettings;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveQuizRequest;
use App\Http\Requests\Onboarding\ApplyTemplateRequest;
use App\Http\Requests\Onboarding\SaveJourneyStageRequest;
use App\Http\Requests\Onboarding\ScheduleMeetingRequest;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Http\Resources\Lms\QuizAttemptResource;
use App\Http\Resources\Lms\QuizResource;
use App\Models\OnboardingJourney;
use App\Models\OnboardingJourneyMeeting;
use App\Models\OnboardingJourneyStage;
use App\Models\OnboardingJourneyTask;
use App\Models\OnboardingTemplate;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Lms\QuizReview;
use App\Support\Onboarding\HiddenMaterials;
use App\Support\Onboarding\JourneyPresenter;
use App\Support\Onboarding\JourneySchedule;
use App\Support\Onboarding\ProfessionalKit;
use App\Support\Onboarding\RouteSettings;
use App\Support\Staff\EmployedPeople;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Date;

/**
 * Маршруты сотрудников — ведущему адаптацию.
 *
 * Назначений здесь нет: маршрут у человека свой и один, его открывают и
 * наполняют — руками или наложением шаблона. Отсюда и список: это список
 * **сотрудников**, у каждого со своей картой, а не список назначенных.
 *
 * Группа под `onboarding.manage`. Подтверждения встреч и заданий здесь те же
 * действия, что у ответственных по назначению, — право лишь второй путь к ним.
 */
final class ManageJourneyController extends Controller
{
    public function __construct(
        private readonly JourneyPresenter $presenter,
        private readonly RefreshJourneyProgress $refresh,
        private readonly OpenJourney $open,
    ) {}

    /**
     * Сотрудники и их маршруты.
     *
     * Показываются все работающие, а не только идущие по маршруту: у пустой
     * карты тоже есть владелец, и чтобы положить в неё первый этап, человека
     * надо сперва найти. Поиск — тот же, каким ищут везде (EmployedPeople).
     */
    public function index(Request $request, EmployedPeople $people, JourneySchedule $schedule): JsonResponse
    {
        $employees = $people->suggest($request->query('search'), limit: 100);

        $journeys = OnboardingJourney::query()
            ->whereIn('user_id', $employees->modelKeys())
            ->withCount('stages')
            ->with('stages')
            ->get()
            ->keyBy('user_id');

        $rows = $employees->map(function (User $employee) use ($journeys, $schedule): array {
            $journey = $journeys->get($employee->getKey());
            $total = $journey?->stages->count() ?? 0;
            $completed = $journey?->stages->filter(fn ($stage): bool => $stage->isCompleted())->count() ?? 0;

            return [
                'employee' => [
                    'id' => (int) $employee->getKey(),
                    'name' => $employee->name,
                    'job_title' => $employee->job_title,
                ],
                'journey_id' => $journey?->getKey(),
                'status' => $journey?->status->value,
                'status_label' => $journey?->status->label(),
                'started_at' => $journey?->started_at,
                'current_day' => $journey === null ? null : $schedule->currentDay($journey, Date::now()),
                'progress' => [
                    'completed' => $completed,
                    'total' => $total,
                    'percent' => $total === 0 ? 0 : (int) round($completed / $total * 100),
                ],
                'completed_at' => $journey?->completed_at,
                'fully_equipped' => $journey?->isFullyEquipped() ?? false,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    /**
     * Маршрут сотрудника. Пустую карту заводит само открытие: «маршрута ещё
     * нет» — не состояние, о котором стоит сообщать отказом.
     */
    public function show(User $employee): JsonResponse
    {
        $journey = $this->open->handle($employee);

        $this->refresh->handle($journey);

        return response()->json(['data' => $this->presenter->present($journey->refresh(), forManager: true)]);
    }

    public function settings(Request $request, User $employee): JsonResponse
    {
        $attributes = $request->validate([
            ...RouteSettings::rules(),
            ...ProfessionalKit::rules(),
            'allow_ahead' => ['sometimes', 'boolean'],
            'requires_graduation' => ['sometimes', 'boolean'],
            'meeting_reminder_minutes' => ['nullable', 'integer', 'min:1', 'max:1440'],
        ]);
        $journey = $this->open->handle($employee);
        $journey = app(UpdateJourneySettings::class)->handle($journey, $attributes);

        return $this->presented($journey);
    }

    /** Наложить шаблон: его этапы дописываются в конец маршрута. */
    public function applyTemplate(
        ApplyTemplateRequest $request,
        User $employee,
        OnboardingTemplate $template,
        ApplyTemplate $apply,
    ): JsonResponse {
        /** @var User $actor */
        $actor = $request->user();

        $journey = $this->open->handle($employee);

        /** @var array{anchor?: string, date?: ?string} $attributes */
        $attributes = $request->validated();

        $apply->handle($journey, $template, $attributes, $actor);

        return $this->presented($journey);
    }

    /**
     * Что из шаблона будет закрыто этому сотруднику — до наложения.
     */
    public function templateVisibility(User $employee, OnboardingTemplate $template, HiddenMaterials $hidden): JsonResponse
    {
        return response()->json(['data' => ['hidden' => $hidden->inTemplate($template, $employee)]]);
    }

    /* ---------- Этапы маршрута ---------- */

    public function saveStage(SaveJourneyStageRequest $request, User $employee, SaveJourneyStage $save): JsonResponse
    {
        $journey = $this->open->handle($employee);

        $save->handle($journey, $request->stage());

        return $this->presented($journey);
    }

    public function deleteStage(User $employee, OnboardingJourneyStage $stage, DeleteJourneyStage $delete): JsonResponse
    {
        $journey = $this->journeyOf($employee, $stage);

        $delete->handle($stage);

        return $this->presented($journey);
    }

    public function reorderStages(Request $request, User $employee, ReorderJourneyStages $reorder): JsonResponse
    {
        $journey = $this->open->handle($employee);

        $validated = $request->validate([
            'stages' => ['required', 'array'],
            'stages.*' => ['integer'],
        ]);

        $reorder->handle($journey, array_map(intval(...), $validated['stages']));

        return $this->presented($journey);
    }

    /** Тест этапа маршрута — тем же редактором, что у урока и экзамена. */
    public function saveStageQuiz(SaveQuizRequest $request, User $employee, OnboardingJourneyStage $stage, SaveQuiz $save): QuizResource
    {
        $this->journeyOf($employee, $stage);

        /** @var array{title: string, questions: array<int, array<string, mixed>>} $attributes */
        $attributes = $request->validated();

        return QuizResource::make($save->handle($stage, $attributes));
    }

    public function deleteStageQuiz(User $employee, OnboardingJourneyStage $stage): Response
    {
        $this->journeyOf($employee, $stage);

        $stage->loadMissing('quiz')->quiz?->delete();

        return response()->noContent();
    }

    /* ---------- Ход маршрута ---------- */

    public function pause(User $employee, PauseJourney $pause): JsonResponse
    {
        $journey = $this->open->handle($employee);

        return $this->presented($pause->handle($journey));
    }

    public function resume(User $employee, ResumeJourney $resume): JsonResponse
    {
        $journey = $this->open->handle($employee);

        return $this->presented($resume->handle($journey));
    }

    /** Перенос и досрочное открытие этапа: пустая дата — открыть сейчас. */
    public function moveStage(Request $request, User $employee, OnboardingJourneyStage $stage, MoveStageOpening $move): JsonResponse
    {
        $journey = $this->journeyOf($employee, $stage);

        $validated = $request->validate(['opens_at' => ['nullable', 'date']]);

        $move->handle($stage, $validated['opens_at'] ?? null);

        return $this->presented($journey);
    }

    public function scheduleMeeting(
        ScheduleMeetingRequest $request,
        User $employee,
        OnboardingJourneyMeeting $meeting,
        ScheduleMeeting $schedule,
    ): JsonResponse {
        $journey = $this->journeyOfMeeting($employee, $meeting);

        /** @var array{scheduled_at: string, place?: ?string, with_user_id?: ?int} $attributes */
        $attributes = $request->validated();

        $schedule->handle($meeting, $attributes);

        return $this->presented($journey);
    }

    public function confirmMeeting(Request $request, User $employee, OnboardingJourneyMeeting $meeting, ConfirmMeeting $confirm): JsonResponse
    {
        $journey = $this->journeyOfMeeting($employee, $meeting);

        /** @var User $actor */
        $actor = $request->user();

        $confirm->handle($meeting, $actor);

        return $this->presented($journey);
    }

    public function confirmTask(Request $request, User $employee, OnboardingJourneyTask $task, ConfirmTask $confirm): JsonResponse
    {
        $journey = $this->open->handle($employee);

        if ((int) $task->loadMissing('journeyStage')->journeyStage->journey_id !== (int) $journey->getKey()) {
            abort(404);
        }

        /** @var User $actor */
        $actor = $request->user();

        $confirm->handle($task, $actor);

        return $this->presented($journey);
    }

    /** Попытка сверх предела — выход из «тест не сдан». */
    public function grantAttempt(User $employee, OnboardingJourneyStage $stage, GrantExtraAttempt $grant): JsonResponse
    {
        $journey = $this->journeyOf($employee, $stage);

        $grant->handle($stage);

        return $this->presented($journey);
    }

    /** Выпуск: допуск, футболка, штрих-код — по одной отметке за запрос. */
    public function graduate(Request $request, User $employee, ConfirmGraduation $confirm): JsonResponse
    {
        $journey = $this->open->handle($employee);

        $validated = $request->validate([
            'what' => ['required', 'string', 'regex:/^[a-zA-Z0-9_-]{1,64}$/'],
        ]);

        /** @var User $actor */
        $actor = $request->user();

        $confirm->handle($journey, (string) $validated['what'], $actor);

        return $this->presented($journey->refresh());
    }

    /** Чужая попытка — ведущему: разбор с открытым ключом. */
    public function attempt(User $employee, QuizAttempt $attempt, QuizReview $review): QuizAttemptResource
    {
        if ((int) $attempt->user_id !== (int) $employee->getKey()) {
            abort(404);
        }

        $attempt->setAttribute('review', $review->forAuthor($attempt));

        return QuizAttemptResource::make($attempt->loadMissing('user'));
    }

    /**
     * Кого можно найти — тот же список, каким выбирают проверяющего и
     * согласующего (EmployedPeople): работающие сотрудники.
     */
    public function people(Request $request, EmployedPeople $people): AnonymousResourceCollection
    {
        return CoursePersonResource::collection($people->suggest($request->query('search')));
    }

    private function presented(OnboardingJourney $journey): JsonResponse
    {
        $this->refresh->handle($journey);

        return response()->json(['data' => $this->presenter->present($journey->refresh(), forManager: true)]);
    }

    /** Этап — из маршрута этого человека, иначе «не найдено». */
    private function journeyOf(User $employee, OnboardingJourneyStage $stage): OnboardingJourney
    {
        $journey = $this->open->handle($employee);

        if ((int) $stage->journey_id !== (int) $journey->getKey()) {
            abort(404);
        }

        return $journey;
    }

    private function journeyOfMeeting(User $employee, OnboardingJourneyMeeting $meeting): OnboardingJourney
    {
        $journey = $this->open->handle($employee);

        if ((int) $meeting->loadMissing('journeyStage')->journeyStage->journey_id !== (int) $journey->getKey()) {
            abort(404);
        }

        return $journey;
    }
}
