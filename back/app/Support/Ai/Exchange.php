<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Один круг разговора: что спросил сотрудник и что ответил консультант.
 *
 * Ответ хранится уже без ссылок на источники. Номер в «[источник 2]» — это
 * место фрагмента в списке того разговора, а в новом вопросе список другой:
 * увидев старую разметку, модель переносит номера в свежий ответ, и ссылка
 * ведёт не туда, куда вела вчера.
 */
final readonly class Exchange
{
    public function __construct(
        public string $question,
        public string $answer,
    ) {}

    public static function of(string $question, string $answer): self
    {
        return new self(trim($question), self::withoutCitations($answer));
    }

    /** Есть ли что показывать: половина разговора разговором не является. */
    public function isComplete(): bool
    {
        return $this->question !== '' && $this->answer !== '';
    }

    private static function withoutCitations(string $answer): string
    {
        $stripped = preg_replace('/\[источник[^]]*]/u', '', $answer) ?? $answer;

        return trim((string) preg_replace(['/[ \t]{2,}/u', '/\s+([.,;:!?])/u'], [' ', '$1'], $stripped));
    }
}
