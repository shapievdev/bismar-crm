<?php

declare(strict_types=1);

namespace App\Support\Lms;

/**
 * Режет текст урока на фрагменты, по которым ищет консультант.
 *
 * Границы проходят по абзацам, а не по числу знаков: обрывок, начинающийся с
 * середины фразы, читается моделью как отдельное утверждение и цитируется ею
 * так же уверенно, как целое. Абзац, который сам длиннее допустимого, режется
 * по границам предложений — это последнее место, где текст ещё осмыслен.
 */
final readonly class PassageSplitter
{
    /**
     * Целевой размер фрагмента.
     *
     * Достаточно, чтобы мысль поместилась вместе с оговорками, и достаточно
     * мало, чтобы в промпт поместился десяток таких из разных уроков. Абзацы
     * набираются, пока не наберётся столько.
     */
    private const TARGET = 900;

    /**
     * Предел, за которым фрагмент режется, даже если абзац не кончился.
     */
    private const LIMIT = 1600;

    /**
     * @return list<string>
     */
    public function split(?string $text): array
    {
        $text = trim(preg_replace('/[ \t\x{00A0}]+/u', ' ', (string) $text) ?? '');

        if ($text === '') {
            return [];
        }

        $passages = [];
        $current = '';

        foreach ($this->paragraphs($text) as $paragraph) {
            // Абзац, который сам не помещается, идёт отдельными фрагментами:
            // приклеивать к нему соседей нечего.
            if (mb_strlen($paragraph) > self::LIMIT) {
                $passages = [...$passages, ...$this->flush($current), ...$this->sentences($paragraph)];
                $current = '';

                continue;
            }

            $current = $current === '' ? $paragraph : $current."\n\n".$paragraph;

            if (mb_strlen($current) >= self::TARGET) {
                $passages[] = $current;
                $current = '';
            }
        }

        return array_values([...$passages, ...$this->flush($current)]);
    }

    /**
     * @return list<string>
     */
    private function paragraphs(string $text): array
    {
        $parts = preg_split('/\n\s*\n+/u', $text) ?: [$text];

        return array_values(array_filter(array_map(trim(...), $parts), static fn (string $p): bool => $p !== ''));
    }

    /**
     * Собирает предложения в куски по TARGET знаков.
     *
     * @return list<string>
     */
    private function sentences(string $paragraph): array
    {
        // Точка, восклицательный или вопросительный знак, за которыми пробел:
        // сокращения вроде «т. е.» при этом не разрываются, потому что после
        // точки там идёт строчная буква.
        $parts = preg_split('/(?<=[.!?])\s+(?=[А-ЯЁA-Z0-9])/u', $paragraph) ?: [$paragraph];

        $passages = [];
        $current = '';

        foreach ($parts as $sentence) {
            $candidate = $current === '' ? $sentence : $current.' '.$sentence;

            if ($current !== '' && mb_strlen($candidate) > self::TARGET) {
                $passages[] = $current;
                $current = $sentence;

                continue;
            }

            $current = $candidate;
        }

        return [...$passages, ...$this->flush($current)];
    }

    /**
     * @return list<string>
     */
    private function flush(string $current): array
    {
        return trim($current) === '' ? [] : [trim($current)];
    }
}
