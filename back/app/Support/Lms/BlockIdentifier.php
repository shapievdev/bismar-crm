<?php

declare(strict_types=1);

namespace App\Support\Lms;

use Illuminate\Support\Str;

/**
 * Даёт блокам статьи устойчивые имена, чтобы на них можно было сослаться.
 *
 * Строка таблицы урока указывает не на урок, а на место в нём, и для текста
 * этим местом служит блок. Ссылаться на него по номеру нельзя — вставленный
 * выше абзац сдвинет все последующие, и каждая ссылка станет указывать на
 * соседа. По заголовку тоже: заголовки переписывают и повторяют.
 *
 * Поэтому у блока заводится собственное имя, которое живёт, пока живёт блок.
 * Присваивает его сервер при сохранении: идентификатор, пришедший от клиента,
 * может оказаться выдуманным или чужим, а ссылаться на него будут потом годами.
 */
final readonly class BlockIdentifier
{
    /** Атрибут, под которым имя живёт в документе редактора. */
    public const ATTRIBUTE = 'blockId';

    /**
     * Насколько глубоко обходить документ.
     *
     * Та же причина, что и в RichTextExtractor: документ приходит от клиента, и
     * достаточно вложенное дерево исчерпало бы стек.
     */
    private const MAX_DEPTH = 100;

    /**
     * Документ, у каждого блока верхнего уровня которого есть имя.
     *
     * Именуются только блоки верхнего уровня. Вложенные — ячейки таблиц,
     * пункты списков — оставлены нарочно: ссылка ведёт читателя к месту на
     * экране, и прокрутка к таблице целиком полезнее прокрутки к её ячейке.
     *
     * Уже проставленные имена не трогаются никогда: в этом весь смысл — правка
     * соседнего абзаца не должна ломать ссылки на этот.
     *
     * @param  array<mixed>|null  $document
     * @return array<mixed>|null
     */
    public function assign(?array $document): ?array
    {
        if ($document === null || ! is_array($document['content'] ?? null)) {
            return $document;
        }

        // Имена разбираются по порядку, и занявший имя первым его сохраняет.
        // Скопированный блок приносит с собой чужое: два блока под одним
        // именем — та же сломанная ссылка, только молчаливая, она приведёт
        // читателя к одному из двух наугад. Новое имя достаётся копии, а не
        // оригиналу, иначе обычное копирование абзаца рвало бы ссылки на тот,
        // с которого копировали.
        $taken = [];

        foreach ($document['content'] as $index => $block) {
            if (! is_array($block)) {
                continue;
            }

            $existing = $block['attrs'][self::ATTRIBUTE] ?? null;

            // Пустая строка вместо имени и уже занятое имя — одно и то же:
            // сослаться на такой блок нельзя, значит имени у него нет.
            if (is_string($existing) && $existing !== '' && ! in_array($existing, $taken, strict: true)) {
                $taken[] = $existing;

                continue;
            }

            $fresh = (string) Str::ulid();
            $taken[] = $fresh;

            $document['content'][$index]['attrs'][self::ATTRIBUTE] = $fresh;
        }

        return $document;
    }

    /**
     * Имена блоков документа — то, на что строка таблицы вправе сослаться.
     *
     * @param  array<mixed>|null  $document
     * @return list<string>
     */
    public function identifiers(?array $document): array
    {
        return array_values(array_unique($this->all($document)));
    }

    /**
     * Имена как они есть в документе, вместе с повторами.
     *
     * @param  array<mixed>|null  $document
     * @return list<string>
     */
    private function all(?array $document): array
    {
        if ($document === null) {
            return [];
        }

        $found = [];
        $this->walk($document, $found, 0);

        return $found;
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $found
     */
    private function walk(array $node, array &$found, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $identifier = $node['attrs'][self::ATTRIBUTE] ?? null;

        if (is_string($identifier) && $identifier !== '') {
            $found[] = $identifier;
        }

        if (! is_array($node['content'] ?? null)) {
            return;
        }

        foreach ($node['content'] as $child) {
            if (is_array($child)) {
                $this->walk($child, $found, $depth + 1);
            }
        }
    }
}
