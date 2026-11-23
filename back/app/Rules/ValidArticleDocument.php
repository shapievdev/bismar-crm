<?php

declare(strict_types=1);

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/** Reject text nodes that ProseMirror would replace with an empty article. */
final class ValidArticleDocument implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return; // The array/nullable rules handle this.
        }

        if (($value['type'] ?? null) !== 'doc' || ! $this->validNode($value)) {
            $fail('Содержимое статьи повреждено. Обновите страницу перед сохранением.');
        }
    }

    private function validNode(mixed $node, int $depth = 0): bool
    {
        if (! is_array($node) || $depth > 100) {
            return false;
        }

        if (($node['type'] ?? null) === 'text'
            && (! is_string($node['text'] ?? null) || $node['text'] === '')) {
            return false;
        }

        if (isset($node['content']) && ! is_array($node['content'])) {
            return false;
        }

        foreach ($node['content'] ?? [] as $child) {
            if (! $this->validNode($child, $depth + 1)) {
                return false;
            }
        }

        return true;
    }
}
