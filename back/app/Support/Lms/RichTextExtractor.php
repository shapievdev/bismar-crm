<?php

declare(strict_types=1);

namespace App\Support\Lms;

final readonly class RichTextExtractor
{
    /**
     * How deep the walker will follow a document before giving up.
     *
     * A document is a tree the client sends us; a malformed or hostile one
     * could nest far enough to exhaust the stack, so the depth is capped.
     */
    private const MAX_DEPTH = 100;

    /**
     * Flattens an editor document to plain text.
     *
     * The result is what full-text search runs against, so the reader can find
     * a lesson by words that only appear inside a table or a quote.
     *
     * @param  array<mixed>|null  $document
     */
    public function toPlainText(?array $document): string
    {
        if ($document === null) {
            return '';
        }

        $pieces = [];
        $this->walk($document, $pieces, 0);

        // Collapse the runs of whitespace that block boundaries leave behind.
        return trim((string) preg_replace('/\s+/u', ' ', implode(' ', $pieces)));
    }

    /**
     * @param  array<mixed>  $node
     * @param  list<string>  $pieces
     */
    private function walk(array $node, array &$pieces, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        if (isset($node['text']) && is_string($node['text'])) {
            $pieces[] = $node['text'];
        }

        // Raw HTML blocks are searchable by their visible words, not by their
        // markup, so tags are stripped rather than indexed.
        if (($node['type'] ?? null) === 'htmlBlock' && is_string($node['attrs']['html'] ?? null)) {
            $pieces[] = strip_tags($node['attrs']['html']);
        }

        foreach (['content'] as $key) {
            if (! is_array($node[$key] ?? null)) {
                continue;
            }

            foreach ($node[$key] as $child) {
                if (is_array($child)) {
                    $this->walk($child, $pieces, $depth + 1);
                }
            }
        }
    }
}
