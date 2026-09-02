<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Support\Analytics\LearningReport;
use Illuminate\Http\JsonResponse;

/**
 * Аналитика обучения: сколько материала собрано и как его проходят.
 *
 * Одним ответом, а не четырьмя: экран показывает сводку, два рейтинга и отчёт
 * по тестам вместе, и четыре запроса ради одной страницы дали бы четыре разных
 * мгновения на одном экране. Отдельно спрашивается только состав одного
 * теста — его раскрывают у одной строки из пятнадцати.
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
                'quizzes' => $report->quizzes(),
            ],
        ]);
    }

    /**
     * Кто и как прошёл один тест.
     *
     * Своим адресом, а не внутри общего ответа: список людей раскрывают у
     * одного теста из пятнадцати, и присылать все пятнадцать составов ради
     * этого значит присылать штат помноженный на тесты.
     */
    public function results(Quiz $quiz, LearningReport $report): JsonResponse
    {
        return response()->json([
            'data' => [
                'quiz' => ['id' => $quiz->getKey(), 'title' => $quiz->title],
                'people' => $report->quizResults((int) $quiz->getKey()),
            ],
        ]);
    }
}
