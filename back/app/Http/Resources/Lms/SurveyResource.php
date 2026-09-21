<?php

declare(strict_types=1);

namespace App\Http\Resources\Lms;

use App\Models\Survey;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Survey
 */
final class SurveyResource extends JsonResource
{
    /**
     * Опрос, каким его видит и тот, кто его проходит, и тот, кто его правит.
     *
     * Скрывать здесь нечего — в отличие от теста, где ключ к ответам уходит
     * только правящему: у опроса верного ответа нет, и весь он одинаков для
     * всех. Разное лишь одно — проходил ли его этот человек, и это не про опрос,
     * а про читателя.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $reader = $request->user();

        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,

            // Обязательный держит зачёт материала. Сотруднику это показывают
            // прямо: он должен понимать, почему урок не закрывается.
            'is_required' => $this->is_required,

            // Анонимность — обещание, и человек обязан видеть его до того, как
            // ответит, а не после.
            'is_anonymous' => $this->is_anonymous,

            'closes_at' => $this->closes_at?->toIso8601String(),
            'is_open' => $this->resource->isOpen(),
            'thanks' => $this->thanks,

            // Проходил ли этот человек. Второго раза не бывает, и экран по этому
            // полю и решает, показывать бланк или благодарность.
            'is_answered' => $reader !== null && $this->resource->isAnsweredBy($reader),

            'questions' => $this->whenLoaded('questions', fn () => $this->questions->map(
                fn ($question): array => [
                    'id' => $question->id,
                    'text' => $question->text,
                    'type' => $question->type->value,
                    'is_required' => $question->is_required,
                    'allows_other' => $question->allows_other,
                    'position' => $question->position,

                    // Шкала целиком — делениями, а не границами: рисовать её
                    // приходится и в бланке, и в сводке, и считать деления в
                    // двух местах значит однажды разойтись.
                    'scale' => $question->type->isScale() ? [
                        'steps' => $question->scaleSteps(),
                        'min_label' => $question->scale_min_label,
                        'max_label' => $question->scale_max_label,
                    ] : null,

                    'options' => $question->options->map(fn ($option): array => [
                        'id' => $option->id,
                        'text' => $option->text,
                        'position' => $option->position,
                    ])->values(),
                ],
            )->values()),
        ];
    }
}
