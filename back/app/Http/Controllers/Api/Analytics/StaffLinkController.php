<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Models\StaffReportLink;
use App\Models\User;
use App\Support\Staff\StaffQuery;
use App\Support\Staff\StaffScope;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Ссылки на отчёт для тех, у кого нет учётной записи.
 *
 * Выдаются под конкретный разговор: показать собственнику движение за квартал,
 * отправить консультанту разрез по подразделениям. Поэтому в ссылке нет ни
 * одной фамилии и не может быть — отдаёт её SharedStaffReportController, и
 * списка людей он не умеет вовсе.
 *
 * Срез замораживается в момент выдачи — **уже суженный правами того, кто
 * выдаёт**. Директор направления не выдаст ссылку на компанию, даже подставив
 * чужие номера в запрос: сузит StaffQuery, а не доверие.
 */
final class StaffLinkController extends Controller
{
    /** Сколько дней ссылка живёт по умолчанию и сколько — самое большее. */
    private const DEFAULT_DAYS = 14;

    private const MAX_DAYS = 180;

    public function __construct(
        private readonly StaffScope $scope,
        private readonly StaffQuery $query,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($this->scope->allows($viewer), 403);

        $links = StaffReportLink::query()
            ->with('createdBy:id,first_name,last_name,middle_name')
            // Свои — всем, чужие — тому, кто отвечает за компанию целиком:
            // ссылка наружу это решение о данных, и следить за ним кадровой
            // службе, а не автору соседней ссылки.
            ->unless($this->scope->seesEverything($viewer), fn ($query) => $query
                ->where('created_by_id', $viewer->getKey()))
            ->latest()
            ->limit(100)
            ->get();

        return response()->json(['data' => $links->map($this->present(...))->all()]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($this->scope->allows($viewer), 403);

        $input = $request->validate([
            'days' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_DAYS],
        ]);

        $filters = $this->query->from($request, $viewer);

        $link = StaffReportLink::query()->create([
            'created_by_id' => $viewer->getKey(),
            'filters' => $filters->toArray(),
            'expires_at' => CarbonImmutable::now()->addDays((int) ($input['days'] ?? self::DEFAULT_DAYS)),
        ]);

        // Токен целиком — только здесь, в ответе на создание: дальше в списке
        // остаётся хвост. Ссылку показывают один раз, как и положено секрету.
        return response()->json([
            'data' => [...$this->present($link), 'token' => $link->token],
        ], 201);
    }

    /**
     * Отзыв.
     *
     * Записью, а не удалением: «ссылку отозвали такого-то числа» — часть
     * истории обращения с данными, и стирать её вместе со ссылкой значит
     * стирать ответ на вопрос, была ли она вообще.
     */
    public function destroy(Request $request, StaffReportLink $link): JsonResponse
    {
        /** @var User $viewer */
        $viewer = $request->user();

        abort_unless($this->scope->allows($viewer), 403);
        abort_unless(
            $this->scope->seesEverything($viewer) || $link->created_by_id === $viewer->getKey(),
            403,
        );

        if ($link->revoked_at === null) {
            $link->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        }

        return response()->json(['data' => $this->present($link)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(StaffReportLink $link): array
    {
        return [
            'id' => $link->getKey(),
            'hint' => $link->hint(),
            'created_at' => $link->created_at?->toIso8601String(),
            'created_by' => $link->createdBy?->name,
            'expires_at' => $link->expires_at->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'live' => $link->isLive(),
            'period' => [
                'from' => $link->filters['from'] ?? null,
                'to' => $link->filters['to'] ?? null,
            ],
        ];
    }
}
