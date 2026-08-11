<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Как ключ предъявляется эндпоинту.
 *
 * Два способа, потому что их два в жизни: Anthropic принимает ключ заголовком
 * X-Api-Key, почти все прокси и туннели перед ним — заголовком Authorization.
 * Неверно выбранный способ даёт 401 и выглядит как «неправильный ключ».
 */
enum AiAuthScheme: string
{
    case Bearer = 'bearer';
    case Header = 'header';

    public function label(): string
    {
        return match ($this) {
            self::Bearer => 'Authorization: Bearer — туннели и прокси',
            self::Header => 'X-Api-Key — напрямую в Anthropic',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (self $scheme): array => ['value' => $scheme->value, 'label' => $scheme->label()],
            self::cases(),
        );
    }
}
