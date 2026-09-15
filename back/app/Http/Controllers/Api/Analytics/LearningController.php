<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Enums\MaterialKind;
use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Support\Analytics\LearningReport;
use Illuminate\Http\JsonResponse;

/**
 * Аналитика обучения: сколько материала собрано и как его проходят.
 *
 * Одним ответом, а не пятью: экран показывает сводку, рейтинги и отчёт по
 * проверкам вместе, и пять запросов ради одной страницы дали бы пять разных
 * мгновений на одном экране. Отдельно спрашивается только состав одной
 * проверки — его раскрывают у одной строки из пятнадцати.
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

                // Документы и справочники — двумя списками: разделы разные, и
                // пятнадцать строк на двоих означали бы, что один вытеснит
                // другой.
                'documents' => $report->materials(MaterialKind::Document),
                'handbooks' => $report->materials(MaterialKind::Handbook),

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
