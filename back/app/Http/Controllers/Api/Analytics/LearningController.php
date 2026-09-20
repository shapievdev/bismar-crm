<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Analytics;

use App\Enums\MaterialKind;
use App\Http\Controllers\Controller;
use App\Models\Quiz;
use App\Support\Analytics\LearningReport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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
     * Люди за цифрой сводки.
     *
     * Своим адресом, а не внутри общего ответа: список — персональные данные, и
     * присылать семь списков всякому, кто открыл страницу, незачем. Какую
     * именно цифру раскрывают, говорит `slice` — как и в аналитике штата.
     */
    public function people(Request $request, LearningReport $report): JsonResponse
    {
        $slice = (string) $request->validate([
            'slice' => ['nullable', 'string', 'in:not-started,completed,progress,learners,plan,acknowledgements,attestations'],
        ])['slice'] ?? 'not-started';

        ['total' => $total, 'rows' => $rows] = $report->people($slice);

        return response()->json([
            'data' => [
                'slice' => $slice,

                // Сколько их всего: список обрезан, и молчать об этом значит
                // выдать двести строк за полный ответ.
                'total' => $total,
                'people' => $rows,
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
