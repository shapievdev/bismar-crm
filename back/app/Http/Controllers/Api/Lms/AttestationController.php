<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\ReviewAttestation;
use App\Enums\AttestationStatus;
use App\Exceptions\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\ReviewAttestationRequest;
use App\Http\Resources\Lms\AttestationResource;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Models\QuizAttempt;
use App\Models\User;
use App\Support\Lms\QuizReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Работы, сданные на аттестацию.
 *
 * Всё здесь — про одного человека: проверяющего. Права на маршруте нет и не
 * нужно, потому что доступ даёт не роль, а назначение: автор теста выбрал, кому
 * сдают работы, и этот выбор и есть право. Чужую очередь так не открыть — отбор
 * идёт по вошедшему, а не по тому, что попросил клиент.
 */
final class AttestationController extends Controller
{
    /** Сколько человек показывать в подсказке поиска. */
    private const CANDIDATES = 20;

    public function __construct(private readonly QuizReview $review) {}

    /**
     * Очередь проверяющего: сперва ждущие, потом разобранные.
     *
     * Проверенные не прячутся: к ним возвращаются — вспомнить, что ответил
     * человек в прошлый раз, и не спрашивать дважды об одном.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $examiner */
        $examiner = $request->user();

        $attempts = QuizAttempt::query()
            ->whereHas('quiz', fn ($query) => $query->where('examiner_id', $examiner->getKey()))
            ->with(['user:id,last_name,first_name,middle_name', 'quiz.quizzable', 'reviewer:id,last_name,first_name,middle_name'])
            // Ждущие сверху и по старшинству: первым разбирают то, что дольше
            // всех ждёт ответа.
            ->orderByRaw('case when review_status = ? then 0 else 1 end', [AttestationStatus::Pending->value])
            ->orderBy('completed_at')
            ->get();

        return AttestationResource::collection($attempts);
    }

    /**
     * Кого можно назначить проверяющим.
     *
     * Любой работающий сотрудник: проверять работу — не привилегия и не право
     * с галочкой, а поручение. Кто в нём разбирается, знает автор теста, а не
     * список прав. Уволенных не предлагаем — им сдавать некуда.
     */
    public function candidates(Request $request): AnonymousResourceCollection
    {
        $search = trim((string) $request->query('search'));

        $people = User::query()
            ->employed()
            ->when($search !== '', fn ($query) => $query->matching($search))
            // По фамилии и с учётом ICU, иначе «Ёлкин» окажется после
            // «Яковлева»: база собрана с C-сортировкой.
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();

        return CoursePersonResource::collection($people);
    }

    /**
     * Сколько работ ждёт ответа. Нужен значку в навигации, поэтому отвечает
     * одним числом, а не списком.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        /** @var User $examiner */
        $examiner = $request->user();

        return response()->json([
            'data' => [
                'pending' => QuizAttempt::query()
                    ->where('review_status', AttestationStatus::Pending)
                    ->whereHas('quiz', fn ($query) => $query->where('examiner_id', $examiner->getKey()))
                    ->count(),
            ],
        ]);
    }

    /**
     * Работа целиком: что человек написал и заполнил.
     *
     * Ключ и эталоны открыты: проверяющий сверяет работу с ними, а не угадывает
     * замысел автора теста. Разбор тот же, что видит автор материала, — см.
     * QuizReview::forAuthor.
     */
    public function show(Request $request, QuizAttempt $attempt): JsonResponse
    {
        $this->ensureExaminer($request, $attempt);

        $attempt->loadMissing(['user', 'quiz.quizzable', 'reviewer']);

        return response()->json([
            'data' => [
                ...AttestationResource::make($attempt)->resolve($request),
                'review' => $this->review->forAuthor($attempt),
            ],
        ]);
    }

    /**
     * Зачесть или не зачесть.
     *
     * @throws ConflictException
     */
    public function store(
        ReviewAttestationRequest $request,
        QuizAttempt $attempt,
        ReviewAttestation $review,
    ): AttestationResource {
        $this->ensureExaminer($request, $attempt);

        /** @var User $examiner */
        $examiner = $request->user();

        $reviewed = $review->handle(
            $attempt,
            $examiner,
            (bool) $request->validated('is_accepted'),
            $request->validated('comment'),
        );

        return AttestationResource::make($reviewed->load(['user', 'quiz.quizzable', 'reviewer']));
    }

    /**
     * 404, а не 403: чужая очередь — не то, о существовании чего стоит
     * сообщать, и уж точно не то, о чём стоит спорить.
     */
    private function ensureExaminer(Request $request, QuizAttempt $attempt): void
    {
        $examiner = $attempt->loadMissing('quiz')->quiz?->examiner_id;

        abort_if($examiner === null || $examiner !== $request->user()?->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
