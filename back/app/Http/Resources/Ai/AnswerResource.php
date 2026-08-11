<?php

declare(strict_types=1);

namespace App\Http\Resources\Ai;

use App\Actions\Ai\Answer;
use App\Support\Ai\CourseExpert;
use App\Support\Ai\Source;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Answer
 */
final class AnswerResource extends JsonResource
{
    /**
     * @param  int|null  $questionId  строка журнала, в которую записан вопрос
     */
    public function __construct(Answer $answer, private readonly ?int $questionId = null)
    {
        parent::__construct($answer);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            // Та самая строка журнала: по ней сотрудник оценивает ответ и
            // просит его дописать. Null — журнал был недоступен, и оценивать
            // нечего; кнопки в этом случае не показываются.
            'id' => $this->questionId,

            'answer' => $this->text,

            // Отдан ли ответ автора как есть. Читателю это стоит показать: у
            // выверенной человеком формулировки другой вес, чем у собранной
            // моделью.
            'verbatim' => $this->verbatim,

            // Only what the answer actually cited, each addressable, so the
            // reader can open the lesson and check the claim rather than
            // trust it.
            'sources' => array_map(
                static fn (Source $source): array => $source->citation()->toArray(),
                $this->sources,
            ),

            // Материал по соседству: не то, на чём стоит ответ, а то, что
            // читателю стоит открыть следом. Отдельным списком, потому что
            // ручаться за него ответ не может — и не должен делать вид.
            'related' => array_map(
                static fn (Source $source): array => $source->citation()->toArray(),
                $this->related,
            ),

            // К кому идти, если написанного не хватило. Пусто, когда ответ
            // нашёлся или когда за курсом никто не закреплён.
            'experts' => array_map(
                static fn (CourseExpert $expert): array => $expert->toArray(),
                $this->experts,
            ),
        ];
    }
}
