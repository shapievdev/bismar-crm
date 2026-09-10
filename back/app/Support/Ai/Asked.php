<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Вопрос, с которым идут в поиск, вместе с его вектором.
 *
 * Заведён ради двух вещей, которых не давал голый текст.
 *
 * Вектор считается один раз на вопрос. Прежде за ним независимо ходили и поиск
 * по таблицам, и пересортировка фрагментов: один и тот же текст уходил на
 * сторону дважды, и сотрудник ждал два круга по сети вместо одного. Оба места
 * при этом считали один и тот же вектор — экономия здесь ничего не меняет в
 * выдаче, только во времени и в счёте.
 *
 * Отказ службы эмбеддингов становится состоянием, а не случайностью, которую
 * каждое место ловит само по себе. `isSemantic()` говорит, что вектор
 * действительно есть: настроенная модель этого не обещает — запрос за ним мог
 * не дойти. Пороги близости откалиброваны на косинусе, и путь, на котором его
 * нет, обязан знать о себе, что он запасной.
 */
final readonly class Asked
{
    /**
     * @param  list<float>  $vector  пусто — смыслового поиска в этот раз не будет
     */
    private function __construct(
        public string $text,
        public array $vector,
    ) {}

    public static function of(Embedder $embedder, string $question): self
    {
        $question = trim($question);

        // Не настроенная модель — не поломка: консультант работает и по словам,
        // просто хуже находит названное другими словами. Молчать об этом можно,
        // а об отказе ниже — нет.
        if ($question === '' || ! $embedder->isAvailable()) {
            return new self($question, []);
        }

        try {
            $vector = Vector::normalised($embedder->embed([$question])[0] ?? []);
        } catch (Throwable $exception) {
            Log::warning('Смысловой поиск недоступен, ищем по словам.', [
                'question' => $question,
                'exception' => $exception,
            ]);

            return new self($question, []);
        }

        return new self($question, $vector);
    }

    public function isSemantic(): bool
    {
        return $this->vector !== [];
    }
}
