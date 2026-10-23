<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Knowledge;

use App\Enums\MaterialKind;
use App\Mcp\Tools\BismarTool;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\CourseAccess;
use App\Support\Lms\LearningPlan;
use App\Support\Lms\RichTextExtractor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

/**
 * Прочитать найденное целиком.
 *
 * Статья приходит текстом, а не содержимым редактора: в базе она лежит деревом
 * блоков (`content_json`), и пересказывать модели это дерево незачем — ей нужен
 * текст. Разворачивает его тот же класс, которым собирается корпус поиска
 * (RichTextExtractor), а не свой обход: два обхода одного дерева однажды
 * разошлись бы, и поиск начал бы находить то, чего в прочитанном нет.
 *
 * Правила доступа — не свои, а те же, что у страницы: политика материала
 * (`view`), доступ к приватному курсу (CourseAccess) и очередь плана обучения
 * (LearningPlan). Последняя здесь не формальность: план на то и план, что
 * шестой урок не читают прежде первого, и коннектор не должен быть лазейкой
 * вокруг него.
 */
#[Name('knowledge_read')]
#[Title('Прочитать материал')]
#[Description('Отдаёт материал целиком: статью документа или справочника, урок с текстом, курс с его оглавлением, новость. Вид и номер берутся из knowledge_search. Доступ тот же, что на сайте: закрытый курс, черновик и запертый очередью плана обучения материал ответят отказом.')]
final class ReadKnowledge extends BismarTool
{
    public function handle(Request $request, RichTextExtractor $text): ResponseFactory|Response
    {
        $actor = $this->permitAnyone($request);

        $input = $request->validate([
            'kind' => ['required', 'in:course,lesson,document,handbook,news'],
            'id' => ['required', 'integer'],
        ]);

        $id = (int) $input['id'];

        return match ((string) $input['kind']) {
            'course' => $this->course($actor, $id),
            'lesson' => $this->lesson($actor, $id, $text),
            'news' => $this->news($actor, $id, $text),
            default => $this->material($actor, MaterialKind::from((string) $input['kind']), $id, $text),
        };
    }

    /**
     * Курс — оглавлением, а не текстом: текст лежит в уроках, и курс целиком
     * был бы ответом в полсотни страниц.
     */
    private function course(User $actor, int $id): ResponseFactory|Response
    {
        $course = Course::query()->find($id);

        if (! $course instanceof Course) {
            return Response::error("Курса №{$id} нет.");
        }

        Gate::forUser($actor)->authorize('view', $course);
        $this->ensureTheCourseIsOpen($actor, $course);

        // Порядок и отбор черновиков — в самих связях: `modules()` упорядочена,
        // `lessons()` отдаёт только выложенные (см. Course::lessons).
        $course->load('modules.lessons');

        return Response::structured([
            'kind' => 'course',
            'id' => $course->getKey(),
            'title' => $course->title,
            'summary' => $course->summary,
            'status' => $course->status?->value,
            'modules' => $course->modules
                ->map(static fn (CourseModule $module): array => [
                    'id' => $module->getKey(),
                    'title' => $module->title,
                    'lessons' => $module->lessons
                        ->map(static fn (Lesson $lesson): array => [
                            'id' => $lesson->getKey(),
                            'title' => $lesson->title,
                        ])->all(),
                ])->all(),
            'hint' => 'Текст уроков читается отдельно: knowledge_read с kind=lesson и номером урока.',
        ]);
    }

    private function lesson(User $actor, int $id, RichTextExtractor $text): ResponseFactory|Response
    {
        $lesson = Lesson::query()->with('module.course')->find($id);

        if (! $lesson instanceof Lesson) {
            return Response::error("Урока №{$id} нет.");
        }

        $course = $lesson->owningCourse();

        // Урок, который не может назвать свой курс, — часть удалённого: его
        // удалили мягко, ради прогресса учеников, а читать его больше нельзя.
        if (! $course instanceof Course) {
            return Response::error("Урок №{$id} принадлежит удалённому курсу.");
        }

        Gate::forUser($actor)->authorize('view', $course);
        $this->ensureTheCourseIsOpen($actor, $course);

        return Response::structured([
            'kind' => 'lesson',
            'id' => $lesson->getKey(),
            'title' => $lesson->title,
            'course' => ['id' => $course->getKey(), 'title' => $course->title],
            'article' => $text->toPlainText($lesson->content_json),
        ]);
    }

    private function material(User $actor, MaterialKind $kind, int $id, RichTextExtractor $text): ResponseFactory|Response
    {
        $material = Regulation::query()->ofKind($kind)->with('category', 'experts')->find($id);

        if (! $material instanceof Regulation) {
            return Response::error($kind === MaterialKind::Handbook
                ? "Справочника №{$id} нет."
                : "Документа №{$id} нет.");
        }

        Gate::forUser($actor)->authorize('view', $material);

        return Response::structured([
            'kind' => $kind->value,
            'id' => $material->getKey(),
            'title' => $material->title,
            'category' => $material->category?->name,
            'summary' => $material->summary,
            'status' => $material->status?->value,
            'article' => $text->toPlainText($material->content_json),
            'experts' => $material->experts->map(static fn (User $expert): string => $expert->name)->all(),
        ]);
    }

    private function news(User $actor, int $id, RichTextExtractor $text): ResponseFactory|Response
    {
        $one = News::query()->find($id);

        if (! $one instanceof News) {
            return Response::error("Новости №{$id} нет.");
        }

        Gate::forUser($actor)->authorize('view', $one);

        return Response::structured([
            'kind' => 'news',
            'id' => $one->getKey(),
            'title' => $one->title,
            'published_at' => $one->published_at?->toDateString(),
            'excerpt' => $one->excerpt,
            'article' => $text->toPlainText($one->content_json),
        ]);
    }

    /**
     * Закрытость курса и очередь плана — те же две проверки, что стоят на
     * входе во все маршруты базы знаний (EnsureCourseAccess и
     * EnsureLearningPlanOrder).
     *
     * @throws AuthorizationException
     */
    private function ensureTheCourseIsOpen(User $actor, Course $course): void
    {
        if (! CourseAccess::of($actor)->allows($course)) {
            throw new AuthorizationException('Этот курс вам не открыт.');
        }

        if (LearningPlan::restrains($actor)) {
            $plan = LearningPlan::of($actor);

            if (! $plan->allows($course)) {
                throw new AuthorizationException($plan->refusal());
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->string()
                ->required()
                ->enum(['course', 'lesson', 'document', 'handbook', 'news'])
                ->description('Что читаем — вид из knowledge_search.'),
            'id' => $schema->integer()->required()->description('Номер материала из knowledge_search.'),
        ];
    }
}
