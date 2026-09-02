<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Support\Ai\Embedder;
use App\Support\Ai\Vector;
use Throwable;

/**
 * Насколько ответ своими словами похож на эталон.
 *
 * Письменный ответ нельзя сверить с ключом побуквенно: «прибыль ушла в запасы и
 * дебиторку» и «деньги заморожены на складе и у клиентов» — один и тот же ответ
 * разными словами. Поэтому сравнивается смысл (решение пользователя
 * 2026-09-02).
 *
 * Мерок две, и они на разных шкалах — отсюда два порога в `config/ai.php`:
 *
 *  - **по смыслу**: тот же эмбеддер, что у консультанта. Замер на настоящих
 *    ответах (text-embedding-3-small, 2026-09-02): верный ответ своими словами
 *    даёт 0.69-0.70, неверный по той же теме — 0.43, не по теме — 0.24.
 *  - **по словам**: доля общих слов (Дайса) — запасной путь, когда эмбеддинги
 *    не настроены. На тех же ответах: 0.32, 0.16 и 0.00 — шкала вдвое ниже.
 *
 * Сами пороги стоят в `config/ai.php` с этими же замерами: они зависят от
 * модели эмбеддингов, а не от предметной области.
 *
 * Чем измерено — часть ответа: путать шкалы нельзя, и человеку, который смотрит
 * оценку, важно знать, читал его ответ смысл или пересечение слов.
 */
final readonly class AnswerSimilarity
{
    public const BY_MEANING = 'meaning';

    public const BY_WORDS = 'words';

    public function __construct(private Embedder $embedder) {}

    /**
     * Насколько ответ похож на эталон и зачтён ли он.
     *
     * Пустой ответ или пустой эталон — не совпадение: сравнивать нечего, и
     * выдавать за схожесть отсутствие текста нельзя.
     *
     * @return array{similarity: float, threshold: float, measured_by: string, is_accepted: bool}
     */
    public function of(?string $given, ?string $expected): array
    {
        $given = trim((string) $given);
        $expected = trim((string) $expected);

        if ($given === '' || $expected === '') {
            return $this->verdict(0.0, self::BY_WORDS);
        }

        $byMeaning = $this->byMeaning($given, $expected);

        return $byMeaning === null
            ? $this->verdict($this->byWords($given, $expected), self::BY_WORDS)
            : $this->verdict($byMeaning, self::BY_MEANING);
    }

    /**
     * @return array{similarity: float, threshold: float, measured_by: string, is_accepted: bool}
     */
    private function verdict(float $similarity, string $measuredBy): array
    {
        $threshold = (float) config(
            $measuredBy === self::BY_MEANING
                ? 'ai.answer_similarity_floor'
                : 'ai.answer_similarity_floor_by_words',
        );

        return [
            // Округляем до сотых: третий знак ничего не решает, а в разборе
            // оценки «0.8137» читается хуже, чем «0.81».
            'similarity' => round($similarity, 2),
            'threshold' => $threshold,
            'measured_by' => $measuredBy,
            'is_accepted' => $similarity >= $threshold,
        ];
    }

    /**
     * Близость по смыслу — или null, если эмбеддинги недоступны.
     *
     * Сорвавшийся запрос к провайдеру не должен ронять сдачу теста: человек
     * ответил, и его ответ уже записан. Тогда меряем словами и говорим об этом
     * прямо.
     */
    private function byMeaning(string $given, string $expected): ?float
    {
        if (! $this->embedder->isAvailable()) {
            return null;
        }

        try {
            $vectors = $this->embedder->embed([$given, $expected]);
        } catch (Throwable) {
            return null;
        }

        if (count($vectors) < 2) {
            return null;
        }

        // Векторы нормируются при упаковке, поэтому косинус здесь — скалярное
        // произведение; отрицательная близость для оценки равна нулю.
        return max(0.0, Vector::similarity(
            Vector::unpack(Vector::pack($vectors[0])),
            Vector::unpack(Vector::pack($vectors[1])),
        ));
    }

    /**
     * Доля общих слов: 2 × пересечение ÷ сумма длин.
     *
     * Слова приводятся к нижнему регистру и обрезаются до пяти знаков — это
     * грубая замена стемминга, но она склеивает «запасы» с «запасов» и
     * «дебиторка» с «дебиторки», а именно на падежах пересечение и теряется.
     */
    private function byWords(string $given, string $expected): float
    {
        $left = $this->stems($given);
        $right = $this->stems($expected);

        if ($left === [] || $right === []) {
            return 0.0;
        }

        $shared = count(array_intersect($left, $right));

        return 2 * $shared / (count($left) + count($right));
    }

    /**
     * @return list<string>
     */
    private function stems(string $text): array
    {
        $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [];

        $stems = [];

        foreach ($words as $word) {
            // Слова короче трёх букв выбрасываются: «и», «в», «на» есть в любом
            // ответе и только завышают схожесть.
            if (mb_strlen($word) < 3) {
                continue;
            }

            $stems[] = mb_substr($word, 0, 5);
        }

        return array_values(array_unique($stems));
    }
}
