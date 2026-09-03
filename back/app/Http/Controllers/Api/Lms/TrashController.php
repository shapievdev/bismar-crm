<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\Regulation;
use App\Support\Lms\CourseAccess;
use App\Support\Lms\DiscardedFiles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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
        $access = CourseAccess::of($request->user());

        $courses = Course::onlyTrashed()
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

        $documents = Regulation::onlyTrashed()
            ->with('author:id,last_name,first_name,middle_name', 'remover:id,last_name,first_name,middle_name')
            ->get()
            ->map(fn (Regulation $document): array => [
                'id' => $document->getKey(),
                'kind' => 'document',
                'title' => $document->title,
                'author' => $document->author?->name,
                'deleted_at' => $document->deleted_at?->toIso8601String(),
                'deleted_by' => $document->remover?->name,
                'lessons' => null,
            ]);

        // Свежее сверху: возвращают обычно то, что выбросили только что.
        $rows = $courses->concat($documents)
            ->sortByDesc('deleted_at')
            ->values()
            ->all();

        return response()->json(['data' => $rows]);
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
        $course = Course::onlyTrashed()->find($id);

        abort_if($course === null, HttpResponse::HTTP_NOT_FOUND);
        abort_unless(CourseAccess::of($request->user())->allows($course), HttpResponse::HTTP_NOT_FOUND);

        return $course;
    }

    private function trashedDocument(Request $request, int $id): Regulation
    {
        $document = Regulation::onlyTrashed()->find($id);

        abort_if($document === null, HttpResponse::HTTP_NOT_FOUND);

        return $document;
    }
}
