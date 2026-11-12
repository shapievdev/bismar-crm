<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Onboarding;

use App\Actions\Lms\SaveQuiz;
use App\Actions\Onboarding\ChangeTemplateStatus;
use App\Actions\Onboarding\DeleteStage;
use App\Actions\Onboarding\DeleteTemplate;
use App\Actions\Onboarding\DuplicateTemplate;
use App\Actions\Onboarding\ReorderStages;
use App\Actions\Onboarding\SaveStage;
use App\Actions\Onboarding\SaveTemplate;
use App\Enums\OnboardingTemplateStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveQuizRequest;
use App\Http\Requests\Onboarding\SaveStageRequest;
use App\Http\Requests\Onboarding\SaveTemplateRequest;
use App\Http\Resources\Lms\QuizResource;
use App\Http\Resources\Onboarding\TemplateResource;
use App\Models\OnboardingStage;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\Lms\PlannableMaterial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

/**
 * Шаблоны маршрутов: программа, этапы, их содержимое.
 *
 * Вся группа закрыта правом `onboarding.manage` на маршруте. Вопросы тестов
 * ведёт SaveQuiz — редактор тестов в приложении один.
 */
final class ManageTemplateController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return TemplateResource::collection(
            OnboardingTemplate::query()
                ->withCount('stages')
                ->with('author')
                ->latest('id')
                ->get(),
        );
    }

    public function store(SaveTemplateRequest $request, SaveTemplate $save): TemplateResource
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{title: string} $attributes */
        $attributes = $request->validated();

        return TemplateResource::make($save->handle(null, $attributes, $actor));
    }

    public function show(OnboardingTemplate $template): TemplateResource
    {
        return TemplateResource::make(
            $template->load([
                'stages.links.linkable',
                'stages.meetings.counterpart',
                'stages.tasks',
                'stages.quiz.questions.options',
                'stages.quiz.examiner',
                'author',

                // Значки шагов — чтобы редактор рисовал их сразу, а не ходил
                // за каждым в каталог.
                'stages.achievement',
                'stages.quizAchievement',
                'stages.links.achievement',
                'stages.meetings.achievement',
                'stages.tasks.achievement',
            ]),
        );
    }

    public function update(SaveTemplateRequest $request, OnboardingTemplate $template, SaveTemplate $save): TemplateResource
    {
        /** @var User $actor */
        $actor = $request->user();

        /** @var array{title: string} $attributes */
        $attributes = $request->validated();

        return TemplateResource::make($save->handle($template, $attributes, $actor));
    }

    public function destroy(OnboardingTemplate $template, DeleteTemplate $delete): Response
    {
        $delete->handle($template);

        return response()->noContent();
    }

    /** «Версия» маршрута: глубокая копия черновиком. */
    public function duplicate(Request $request, OnboardingTemplate $template, DuplicateTemplate $duplicate): TemplateResource
    {
        /** @var User $actor */
        $actor = $request->user();

        return TemplateResource::make($duplicate->handle($template, $actor));
    }

    public function status(Request $request, OnboardingTemplate $template, ChangeTemplateStatus $change): TemplateResource
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(OnboardingTemplateStatus::class)],
        ]);

        return TemplateResource::make($change->handle(
            $template,
            OnboardingTemplateStatus::from((string) $validated['status']),
        ));
    }

    /* ---------- Этапы ---------- */

    public function saveStage(SaveStageRequest $request, OnboardingTemplate $template, SaveStage $save): JsonResponse
    {
        $stage = $save->handle($template, $request->stage());

        return response()->json(['data' => TemplateResource::stagePayload($stage)], 201);
    }

    public function deleteStage(OnboardingTemplate $template, OnboardingStage $stage, DeleteStage $delete): Response
    {
        $this->ensureBelongs($template, $stage);

        $delete->handle($stage);

        return response()->noContent();
    }

    public function reorderStages(Request $request, OnboardingTemplate $template, ReorderStages $reorder): Response
    {
        $validated = $request->validate([
            'stages' => ['required', 'array'],
            'stages.*' => ['integer'],
        ]);

        $reorder->handle($template, array_map(intval(...), $validated['stages']));

        return response()->noContent();
    }

    /* ---------- Тест этапа ---------- */

    public function saveQuiz(
        SaveQuizRequest $request,
        OnboardingTemplate $template,
        OnboardingStage $stage,
        SaveQuiz $save,
    ): QuizResource {
        $this->ensureBelongs($template, $stage);

        /** @var array{title: string, questions: array<int, array<string, mixed>>} $attributes */
        $attributes = $request->validated();

        return QuizResource::make($save->handle($stage, $attributes));
    }

    public function destroyQuiz(OnboardingTemplate $template, OnboardingStage $stage): Response
    {
        $this->ensureBelongs($template, $stage);

        $stage->loadMissing('quiz')->quiz?->delete();

        return response()->noContent();
    }

    /* ---------- Каталог материалов для этапа ---------- */

    /**
     * Что можно поставить материалом этапа — список с разделами, как у плана
     * обучения: составляют программу, глядя на то, что вообще есть.
     */
    public function materials(Request $request, PlannableMaterial $materials): JsonResponse
    {
        /** @var User $actor */
        $actor = $request->user();

        // Стажёр на этом экране неизвестен — шаблон пишут до назначения,
        // поэтому вторая видимость (is_visible_to_learner) здесь не о ком.
        return response()->json(['data' => $materials->catalogue($actor, $actor)]);
    }

    private function ensureBelongs(OnboardingTemplate $template, OnboardingStage $stage): void
    {
        if ((int) $stage->template_id !== (int) $template->getKey()) {
            abort(404);
        }
    }
}
