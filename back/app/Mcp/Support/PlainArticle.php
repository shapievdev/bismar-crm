<?php

declare(strict_types=1);

namespace App\Mcp\Support;

/**
 * Текст, разложенный в документ редактора.
 *
 * Обратное к RichTextExtractor, и нужное по той же причине: статьи в базе лежат
 * деревом блоков, а коннектор присылает текст — ничего другого у модели и нет.
 * Пустые строки делят его на абзацы, как в любом редакторе.
 *
 * Большего здесь нет намеренно: ни заголовков, ни списков, ни таблиц. Разметку
 * пришлось бы угадывать по тексту, а угаданный заголовок посреди правила
 * компании хуже его отсутствия. Кому нужна разметка — правит статью на экране,
 * где редактор и живёт.
 */
final class PlainArticle
{
    /**
     * @return array<string, mixed>|null пусто, когда текста нет вовсе
     */
    public static function toDocument(?string $text): ?array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        // Переводы строки приводятся к одному виду: присланное из разных мест
        // приезжает и с \r\n, и с \r.
        $normalised = str_replace(["\r\n", "\r"], "\n", $text);

        $paragraphs = array_values(array_filter(
            array_map(trim(...), preg_split('/\n\s*\n/', $normalised) ?: []),
            static fn (string $paragraph): bool => $paragraph !== '',
        ));

        return [
            'type' => 'doc',
            'content' => array_map(static fn (string $paragraph): array => [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $paragraph]],
            ], $paragraphs),
        ];
    }
}
