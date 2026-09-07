<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\AttachLessonMaterials;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateLessonMaterialsRequest;
use App\Http\Resources\Lms\RegulationLinkResource;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Документы и справочники, приложенные к уроку.
 *
 * Ведёт список тот, кто правит курс, — как и всё остальное в уроке. Читателю
 * своего адреса не нужно: материалы едут вместе с уроком и показываются под
 * статьёй.
 *
 * Всюду, где материал приходит номером или уезжает названием, он пропускается
 * через доступ спрашивающего: закрытый материал выдаёт себя одним заголовком
 * не хуже, чем открытой страницей.
 */
final class LessonMaterialController extends Controller
{
    /** Сколько материалов показывает подсказка поиска. */
    private const CANDIDATES = 20;

    public function index(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        $this->editing($lesson);

        /** @var User $editor */
        $editor = $request->user();

        return RegulationLinkResource::collection($this->materialsOf($lesson, $editor));
    }

    public function update(
        UpdateLessonMaterialsRequest $request,
        Lesson $lesson,
        AttachLessonMaterials $materials,
    ): AnonymousResourceCollection {
        $this->editing($lesson);

        /** @var User $editor */
        $editor = $request->user();

        // Только то, что этому редактору открыто: иначе чужой закрытый
        // документ можно было бы приложить наугад по номеру и прочитать его
        // название в ответе. Порядок остаётся тот, в каком его прислали.
        $materials->set($lesson, $this->allowed($request->documents(), $editor), $editor);

        return RegulationLinkResource::collection($this->materialsOf($lesson->refresh(), $editor));
    }

    /**
     * Что ещё можно приложить. Поиском, а не списком целиком: материалов
     * сотни, а нужен из них один.
     */
    public function candidates(Request $request, Lesson $lesson): AnonymousResourceCollection
    {
        $this->editing($lesson);

        /** @var User $editor */
        $editor = $request->user();

        $found = Regulation::query()
            ->with('category')
            // Оба раздела: урок отправляет читателя к ответу, а не по своему
            // разделу, — как и «частые вопросы» у документа.
            ->whereNotIn('id', fn ($query) => $query
                ->select('regulation_id')
                ->from('lesson_materials')
                ->where('lesson_id', $lesson->getKey()))
            ->visibleTo($editor)
            ->matching($request->query('search'))
            ->orderByRaw('title COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();

        return RegulationLinkResource::collection($found);
    }

    /**
     * Список ведёт тот, кто правит курс: у урока своего права нет — он часть
     * курса, и правится вместе с ним.
     */
    private function editing(Lesson $lesson): void
    {
        $course = $lesson->owningCourse();

        abort_if($course === null, 404);

        Gate::authorize('update', $course);
    }

    /**
     * @return Collection<int, Regulation>
     */
    private function materialsOf(Lesson $lesson, User $reader): Collection
    {
        return $lesson->materials()->with('category')->visibleTo($reader)->get();
    }

    /**
     * Из присланных номеров — те материалы, что этому человеку открыты, в том
     * же порядке.
     *
     * @param  list<int>  $documents
     * @return list<int>
     */
    private function allowed(array $documents, User $editor): array
    {
        if ($documents === []) {
            return [];
        }

        $open = Regulation::query()
            ->whereKey($documents)
            ->visibleTo($editor)
            ->pluck('id')
            ->map(intval(...))
            ->all();

        return array_values(array_filter(
            $documents,
            static fn (int $id): bool => in_array($id, $open, strict: true),
        ));
    }
}
