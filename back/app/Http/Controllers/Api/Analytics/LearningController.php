<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Support\Analytics\LearningReport;
use Illuminate\Http\JsonResponse;

/**
 * Аналитика обучения: сколько материала собрано и как его проходят.
 *
 * Одним ответом, а не тремя: экран показывает сводку и два рейтинга вместе, и
 * три запроса ради одной страницы дали бы три разных мгновения на одном экране.
 *
 * Право — «вести обучение»: кому доверено решать, что людям проходить, тому и
 * смотреть, как это идёт. Продажной аналитике это ортогонально — она про
 * деньги и живёт в ClickHouse.
 */
final class LearningController extends Controller
{
    public function __invoke(LearningReport $report): JsonResponse
    {
        return response()->json([
            'data' => [
                'summary' => $report->summary(),
                'courses' => $report->courses(),
                'regulations' => $report->regulations(),
            ],
        ]);
    }
}
