<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\DecideMaterialApproval;
use App\Enums\ApprovalStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\ReturnMaterialRequest;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Http\Resources\Lms\MaterialReviewResource;
use App\Models\MaterialReview;
use App\Models\User;
use App\Support\Lms\MaterialApprovals;
use App\Support\Staff\EmployedPeople;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Согласование — обе его стороны.
 *
 * Одна половина про согласующего: что ждёт его ответа. Вторая про автора: что
 * стало с тем, что он отправил, — кого ещё ждём и что просили исправить. Держать
 * их врозь было бы странно: это один разговор двух людей об одном материале, и
 * попадают в него оба по одному и тому же поводу.
 *
 * Права на маршруте нет и не нужно, потому что доступ даёт не роль, а
 * назначение: автор выбрал, кого попросить, и этот выбор и есть право — ровно
 * как у аттестаций. Чужой очереди так не открыть: отбор везде идёт по вошедшему,
 * а не по тому, что попросил клиент.
 */
final class ApprovalController extends Controller
{
    public function __construct(private readonly MaterialApprovals $approvals) {}

    /**
     * Очередь согласующего: сперва ждущие ответа, потом решённые.
     *
     * Решённые не прячутся: к ним возвращаются — вспомнить, что просил
     * исправить, и посмотреть, выпустили ли материал в итоге.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $person */
        $person = $request->user();

        $reviews = MaterialReview::query()
            ->whereHas('decisions', fn ($query) => $query->where('user_id', $person->getKey()))
            ->with([
                'reviewable',
                'requester:id,last_name,first_name,middle_name',
                'decisions.user:id,last_name,first_name,middle_name',
            ])
            // Ждущие сверху и по старшинству: первым смотрят то, что дольше всех
            // ждёт ответа.
            ->orderByRaw('case when status = ? then 0 else 1 end', [ApprovalStatus::Pending->value])
            ->orderBy('created_at')
            ->get()
            // Материал мог уехать в корзину, пока круг шёл: показывать строку, за
            // которой ничего нет, незачем — согласовывать удалённое нечего.
            ->filter(fn (MaterialReview $review): bool => $review->reviewable !== null)
            ->values();

        return MaterialReviewResource::collection($reviews);
    }

    /**
     * Что сейчас с материалами, которые отправлял этот человек.
     *
     * Вторая половина раздела, и она про ответ проверяющего: возврат приходит
     * уведомлением на телефон, но телефон мог быть выключен, а уведомление —
     * заменено следующим. Вернувшаяся работа обязана числиться за автором так
     * же, как чужой материал числится за согласующим.
     */
    public function mine(Request $request): AnonymousResourceCollection
    {
        /** @var User $person */
        $person = $request->user();

        return MaterialReviewResource::collection($this->approvals->mine($person));
    }

    /**
     * Сколько материалов ждёт ответа и звали ли этого человека вообще.
     *
     * Двумя числами, а не списком: значок висит на каждой странице. Второе нужно
     * навигации — по числу ждущих решать, показывать ли раздел, нельзя: вкладка
     * исчезала бы, едва человек разобрал очередь, а с нею и путь к тому, что он
     * уже решил. Та же ошибка была у аттестаций, и её там уже исправляли.
     */
    public function pendingCount(Request $request): JsonResponse
    {
        /** @var User $person */
        $person = $request->user();

        return response()->json([
            'data' => [
                // Ждут вашего ответа.
                'pending' => $this->approvals->pendingFor($person),

                // …и вернулись к вам на исправление: и то, и другое — работа,
                // числящаяся за этим человеком, и значок обязан считать обе.
                'returned' => $this->approvals->returnedFor($person),

                // Раздел показывают тому, кого звали, и тому, кто отправлял:
                // по числу ждущих это решать нельзя — вкладка исчезала бы,
                // едва человек разобрал очередь.
                'is_approver' => $this->approvals->everAsked($person)
                    || $this->approvals->everSubmitted($person),
            ],
        ]);
    }

    /**
     * Кого можно позвать согласовать.
     *
     * Любой работающий сотрудник: согласование — поручение, а не право с
     * галочкой. Список общий с аттестациями, см. EmployedPeople.
     */
    public function candidates(Request $request, EmployedPeople $people): AnonymousResourceCollection
    {
        return CoursePersonResource::collection($people->suggest($request->query('search')));
    }

    public function approve(Request $request, MaterialReview $review, DecideMaterialApproval $decide): MaterialReviewResource
    {
        /** @var User $person */
        $person = $request->user();

        $this->ensureWasAsked($review, $person);

        return MaterialReviewResource::make(
            $decide->approve($review, $person, $request->string('comment')->toString() ?: null)
                ->load(['requester:id,last_name,first_name,middle_name', 'reviewable']),
        );
    }

    public function returnForRevision(
        ReturnMaterialRequest $request,
        MaterialReview $review,
        DecideMaterialApproval $decide,
    ): MaterialReviewResource {
        /** @var User $person */
        $person = $request->user();

        $this->ensureWasAsked($review, $person);

        return MaterialReviewResource::make(
            $decide->returnForRevision($review, $person, $request->reason())
                ->load(['requester:id,last_name,first_name,middle_name', 'reviewable']),
        );
    }

    /**
     * Чужой круг — тот же случай, что и несуществующий.
     *
     * 404, а не 403: сам факт, что материал кому-то отправили на согласование,
     * посторонним знать незачем — как и с закрытым материалом.
     */
    private function ensureWasAsked(MaterialReview $review, User $person): void
    {
        abort_unless(
            $review->decisions()->where('user_id', $person->getKey())->exists(),
            HttpResponse::HTTP_NOT_FOUND,
        );
    }
}
