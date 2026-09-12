<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Enums\MaterialKind;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\CourseAccess;
use App\Support\Lms\DiscardedFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Корзина: курсы и документы, удалённые, но ещё не стёртые.
 *
 * Удаление здесь всегда было мягким — за курсом стоит чужой прогресс, за
 * документом отметки об ознакомлении, и уносить их нажатием одной кнопки
 * нельзя. Но пока корзины не было, мягкое удаление ничем не отличалось от
 * настоящего: вернуть удалённое можно было только запросом в базу.
 *
 * Право то же, что на удаление: кто вправе выбросить, тот вправе и достать
 * обратно. А стереть насовсем — только администратору: это единственное
 * действие во всей базе знаний, после которого возвращать нечего.
 *
 * Разделов при этом три, и право у каждого своё (см. App\Enums\MaterialKind).
 * Маршрут пускает сюда с любым из трёх — иначе правящий справочники не увидел
 * бы и собственной корзины, — а каждая строка сверяется отдельно: в списке
 * видно только то, что этот человек вправе вернуть, и попытка вернуть чужое
 * отвечает «не найдено».
 */
final class TrashController extends Controller
{
    public function __construct(private readonly DiscardedFiles $files) {}

    /**
     * Что лежит в корзине — курсы и документы вместе.
     *
     * Вместе, потому что вопрос у человека один: «куда делось то, что я вчера
     * видел». Помнить, курсом это было или документом, чтобы найти пропажу, ему
     * незачем.
     */
    public function index(Request $request): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        // Свежее сверху: возвращают обычно то, что выбросили только что.
        $rows = $this->discardedCourses($reader)
            ->concat($this->discardedMaterials($reader))
            ->sortByDesc('deleted_at')
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
    }

    /**
     * Выброшенные курсы — тому, кто вправе их удалять.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function discardedCourses(User $reader): Collection
    {
        if ($reader->cannot(Permission::DeleteCourses->value)) {
            return collect();
        }

        $access = CourseAccess::of($reader);

        return Course::onlyTrashed()
            ->with('author:id,last_name,first_name,middle_name', 'remover:id,last_name,first_name,middle_name')
            ->get()
            // Закрытый курс не показывается тому, кого в него не пускали, — и
            // в корзине тоже: удаление не открывает того, что было закрыто.
            ->filter(fn (Course $course): bool => $access->allows($course))
            ->map(fn (Course $course): array => [
                'id' => $course->getKey(),
                'kind' => 'course',
                'title' => $course->title,
                'author' => $course->author?->name,
                'deleted_at' => $course->deleted_at?->toIso8601String(),
                'deleted_by' => $course->remover?->name,
                // Чем курс тяжелее, тем дороже ошибка: число уроков говорит,
                // что именно уйдёт при окончательном удалении.
                'lessons' => $course->loadCount('lessons')->lessons_count,
            ]);
    }

    /**
     * Выброшенные документы и справочники — только из разделов, в которых этот
     * человек вправе удалять: выброшенный справочник не дело того, кто ведёт
     * одни курсы, и названия его он видеть не должен.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function discardedMaterials(User $reader): Collection
    {
        return Regulation::onlyTrashed()
            ->whereIn('kind', MaterialKind::valuesOf($this->discardableBy($reader)))
            ->with('author:id,last_name,first_name,middle_name', 'remover:id,last_name,first_name,middle_name')
            ->get()
            ->map(fn (Regulation $document): array => [
                'id' => $document->getKey(),
                // Документ или справочник: возвращают их в разные разделы, и
                // в корзине это надо видеть до нажатия.
                'kind' => $document->kind->value,
                'title' => $document->title,
                'author' => $document->author?->name,
                'deleted_at' => $document->deleted_at?->toIso8601String(),
                'deleted_by' => $document->remover?->name,
                'lessons' => null,
            ]);
    }

    /** Вернуть на место — со всем, что за ним стояло. */
    public function restoreCourse(Request $request, int $course): JsonResponse
    {
        $trashed = $this->trashedCourse($request, $course);

        $trashed->restore();
        $trashed->forceFill(['deleted_by' => null])->save();

        return response()->json(['data' => ['id' => $trashed->getKey(), 'title' => $trashed->title]]);
    }

    public function restoreDocument(Request $request, int $document): JsonResponse
    {
        $trashed = $this->trashedDocument($request, $document);

        $trashed->restore();
        $trashed->forceFill(['deleted_by' => null])->save();

        return response()->json(['data' => ['id' => $trashed->getKey(), 'title' => $trashed->title]]);
    }

    /**
     * Стереть насовсем.
     *
     * Строки уносит каскад, файлы — мы: адреса собираются до удаления, потому
     * что после спрашивать уже некого. Уборка идёт после записи в базу и её
     * неудача ничего не отменяет — недоступное хранилище не должно превращать
     * сделанное удаление в ошибку (см. StoredFiles).
     */
    public function purgeCourse(Request $request, int $course): Response
    {
        $trashed = $this->trashedCourse($request, $course);

        $files = $this->files->of($trashed);

        DB::transaction(fn () => $trashed->forceDelete());

        $this->files->discard($files);

        return response()->noContent();
    }

    public function purgeDocument(Request $request, int $document): Response
    {
        $trashed = $this->trashedDocument($request, $document);

        $files = $this->files->of($trashed);

        DB::transaction(fn () => $trashed->forceDelete());

        $this->files->discard($files);

        return response()->noContent();
    }

    /**
     * Удалённый курс, до которого этому человеку есть дело.
     *
     * Привязка модели по маршруту здесь не годится: она ищет среди живых, а нам
     * нужны как раз мёртвые.
     */
    private function trashedCourse(Request $request, int $id): Course
    {
        /** @var User $actor */
        $actor = $request->user();

        $course = Course::onlyTrashed()->find($id);

        abort_if($course === null, HttpResponse::HTTP_NOT_FOUND);
        abort_unless($actor->can(Permission::DeleteCourses->value), HttpResponse::HTTP_NOT_FOUND);
        abort_unless(CourseAccess::of($actor)->allows($course), HttpResponse::HTTP_NOT_FOUND);

        return $course;
    }

    /**
     * Удалённый материал, до которого этому человеку есть дело.
     *
     * Раздел спрашивается у самой строки, а не у маршрута: адрес в корзине
     * один на документы и справочники — она показывает выброшенное вместе, —
     * и только найденная строка знает, каким разделом она была.
     *
     * Отказ — «не найдено», как и у чужого закрытого курса: перебор номеров не
     * должен рассказывать, что в компании удаляли.
     */
    private function trashedDocument(Request $request, int $id): Regulation
    {
        /** @var User $actor */
        $actor = $request->user();

        $document = Regulation::onlyTrashed()->find($id);

        abort_if($document === null, HttpResponse::HTTP_NOT_FOUND);
        abort_unless($actor->can($document->kind->deletePermission()->value), HttpResponse::HTTP_NOT_FOUND);

        return $document;
    }

    /**
     * Разделы, выброшенное из которых этот человек вправе вернуть.
     *
     * @return list<MaterialKind>
     */
    private function discardableBy(User $actor): array
    {
        return array_values(array_filter(
            MaterialKind::cases(),
            static fn (MaterialKind $kind): bool => $actor->can($kind->deletePermission()->value),
        ));
    }
}
