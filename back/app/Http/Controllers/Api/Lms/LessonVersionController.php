<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Concerns\ManagesMaterialVersions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveVersionRequest;
use App\Http\Resources\Lms\MaterialVersionResource;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\MaterialVersion;
use App\Support\Lms\BlockIdentifier;
use App\Support\Lms\MaterialVersions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Версии урока — тот же урок, рассказанный своим людям (2026-09-25).
 *
 * Заведены по той же нужде, что и версии документа: вводный курс один, а
 * рассказывают его в магазине и в офисе по-разному — своя запись, свой бланк,
 * своя проверка. Разводить курсы по группам значило бы размножить и модули, и
 * порядок уроков, и записи людей.
 *
 * Права берутся у курса: уроки пишет тот, кто ведёт курс, и своего автора у
 * урока нет. Читает версию всякий, кому открыт курс: закрытая — только своим
 * группам и отделам.
 *
 * Сами действия — в ManagesMaterialVersions, общие с документом: расходиться
 * этим двум нельзя.
 */
final class LessonVersionController extends Controller
{
    use ManagesMaterialVersions;

    public function __construct(
        private readonly MaterialVersions $versions,
        private readonly BlockIdentifier $blocks,
    ) {}

    public function index(Lesson $lesson): AnonymousResourceCollection
    {
        return $this->listFor($lesson);
    }

    public function show(Request $request, Lesson $lesson, MaterialVersion $version): MaterialVersionResource
    {
        return $this->showOne($request, $lesson, $version);
    }

    public function store(SaveVersionRequest $request, Lesson $lesson): JsonResponse
    {
        return $this->storeFor($request, $lesson);
    }

    public function update(
        SaveVersionRequest $request,
        Lesson $lesson,
        MaterialVersion $version,
    ): MaterialVersionResource {
        return $this->updateOne($request, $lesson, $version);
    }

    public function reorder(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        return $this->reorderFor($request, $lesson);
    }

    public function destroy(Lesson $lesson, MaterialVersion $version): Response
    {
        return $this->destroyOne($lesson, $version);
    }

    /**
     * Версии урока ведёт тот, кто ведёт курс.
     *
     * @param  Lesson  $material
     */
    protected function authorizeManaging(Model $material): void
    {
        Gate::authorize('update', $this->courseOf($material));
    }

    /**
     * @param  Lesson  $material
     */
    protected function authorizeReading(Model $material): void
    {
        Gate::authorize('view', $this->courseOf($material));
    }

    /**
     * Курс, которому принадлежит урок.
     *
     * Урок без курса — испорченные данные, а не плохой запрос: отвечаем «не
     * найдено», как и везде, где хозяин потерялся.
     */
    private function courseOf(Lesson $lesson): Course
    {
        $course = $lesson->owningCourse();

        abort_if($course === null, HttpResponse::HTTP_NOT_FOUND);

        return $course;
    }
}
