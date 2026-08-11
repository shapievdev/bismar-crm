<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Enums\AnswerPath;
use App\Support\Ai\CourseExpert;
use App\Support\Ai\Source;

final readonly class Answer
{
    /**
     * @param  list<Source>  $sources  источники, на которые ответ сослался
     * @param  int  $retrieved  сколько источников нашёл поиск
     * @param  AnswerPath|null  $path  чем отвечали; null — не ответили вовсе
     * @param  bool  $verbatim  отдан готовый ответ автора, модель не вызывалась
     * @param  list<Source>  $related  материал по соседству: не ответ, а «смотрите также»
     * @param  list<CourseExpert>  $experts  к кому идти, если ответа не нашлось
     * @param  list<int>  $privateCourseIds  приватные курсы, из которых собран ответ
     * @param  string|null  $searchedAs  чем искали, если вопрос дополнили разговором
     */
    public function __construct(
        public string $text,
        public array $sources,
        public int $retrieved = 0,
        public ?AnswerPath $path = null,
        public bool $verbatim = false,
        public array $related = [],
        public array $experts = [],
        public array $privateCourseIds = [],
        public ?string $searchedAs = null,
    ) {}
}
