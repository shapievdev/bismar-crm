<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\AcknowledgeRegulation;
use App\Http\Controllers\Controller;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «Прочитал и понял» — и кто ещё этого не сделал.
 *
 * У курса это место занимает прогресс по урокам; у регламента проходить нечего,
 * и весь его прогресс — вот эта отметка. Она же отвечает за то, пройден ли шаг
 * плана обучения.
 */
final class RegulationAcknowledgementController extends Controller
{
    public function store(
        Request $request,
        Regulation $regulation,
        AcknowledgeRegulation $acknowledge,
    ): JsonResponse {
        Gate::authorize('acknowledge', $regulation);

        /** @var User $reader */
        $reader = $request->user();

        $acknowledgement = $acknowledge->handle($regulation, $reader);

        return response()->json([
            'data' => [
                'is_acknowledged' => true,
                'acknowledged_at' => $acknowledgement->acknowledged_at?->toIso8601String(),
            ],
        ]);
    }

    /**
     * Кто ознакомился — тому, кто регламент ведёт.
     *
     * Списка «кто ещё нет» здесь нет намеренно, в отличие от новости: у новости
     * есть адресаты, а регламент открыт всем, кто читает базу знаний, и «ещё
     * нет» означало бы весь штат за вычетом отметившихся. Кому именно правило
     * обязательно — вопрос плана обучения, там и видно.
     */
    public function index(Regulation $regulation): JsonResponse
    {
        Gate::authorize('update', $regulation);

        $people = $regulation->acknowledgements()
            ->with('user')
            ->get()
            ->map(function ($row) {
                $user = $row->user;

                return $user?->setAttribute(
                    'acknowledged_at',
                    $row->acknowledged_at?->toIso8601String(),
                );
            })
            ->filter()
            ->sortBy(fn (User $user): string => (string) $user->name, SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        return response()->json([
            'data' => CoursePersonResource::collection($people)->resolve(),
        ]);
    }
}
