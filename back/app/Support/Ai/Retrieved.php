<?php

declare(strict_types=1);

namespace App\Support\Ai;

/**
 * Найденное по вопросу, разделённое надвое: чем отвечают и что предлагают рядом.
 *
 * Прежде поиск отдавал один список, а всё, что не дотянуло до порога, молча
 * выбрасывал. Сотрудник получал «в материалах об этом ничего нет» и в том
 * случае, когда рядом лежал разбор соседнего случая, — база знала больше, чем
 * говорила. Теперь отсеянное не пропадает: им не отвечают, его показывают.
 *
 * Модели близкое уходит только тогда, когда точного нет вовсе. Подмешанное к
 * верному, оно уводит слабую модель с верного — та же причина, по которой заведён
 * ai.answers_relative_floor.
 */
final readonly class Retrieved
{
    /**
     * @param  list<Source>  $exact  то, чем можно ответить
     * @param  list<Source>  $related  то, что можно предложить рядом
     */
    public function __construct(
        public array $exact = [],
        public array $related = [],
    ) {}

    public function isEmpty(): bool
    {
        return $this->exact === [] && $this->related === [];
    }

    /**
     * Что уходит модели: точное, если оно есть, иначе близкое.
     *
     * @return list<Source>
     */
    public function forPrompt(): array
    {
        return $this->exact !== [] ? $this->exact : $this->related;
    }

    /**
     * То же самое плюс ещё близкое — без повторов и не длиннее предела.
     *
     * Повтор считается по уроку, а не по источнику. Две карточки «смотрите
     * также» на один урок — это один и тот же совет, повторённый дважды: у
     * читателя они ведут в одно место, и второй ему сказать нечего.
     *
     * @param  list<Source>  $sources
     */
    public function plusRelated(array $sources, int $limit): self
    {
        $related = $this->related;
        $lessons = array_map(
            static fn (Source $source): int => $source->citation()->lessonId,
            [...$this->exact, ...$related],
        );

        foreach ($sources as $source) {
            if (count($related) >= $limit) {
                break;
            }

            $lesson = $source->citation()->lessonId;

            if (in_array($lesson, $lessons, strict: true)) {
                continue;
            }

            $lessons[] = $lesson;
            $related[] = $source;
        }

        return new self($this->exact, $related);
    }

    /**
     * То же самое без источников, на которые ответ уже сослался.
     *
     * Процитированное показано читателю под ответом и снабжено номером. Оно же
     * вторым списком — «смотрите также» на то, что он только что прочёл.
     *
     * @param  list<Source>  $cited
     */
    public function withoutCited(array $cited): self
    {
        $keys = array_map(static fn (Source $source): string => $source->key(), $cited);
        $lessons = array_map(static fn (Source $source): int => $source->citation()->lessonId, $cited);

        return new self($this->exact, array_values(array_filter(
            $this->related,
            static fn (Source $source): bool => ! in_array($source->key(), $keys, strict: true)
                && ! in_array($source->citation()->lessonId, $lessons, strict: true),
        )));
    }

    /**
     * Всё найденное одним списком.
     *
     * По нему считается, из каких закрытых курсов собран ответ: показанное
     * карточкой выдаёт курс не хуже, чем пересказанное в тексте.
     *
     * @return list<Source>
     */
    public function all(): array
    {
        return [...$this->exact, ...$this->related];
    }
}
