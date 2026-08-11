<?php

declare(strict_types=1);

namespace App\Support\Ai;

use Anthropic\Client;
use App\Enums\AiAuthScheme;
use App\Models\AiSetting;

/**
 * Чем и куда отвечает консультант.
 *
 * Настройки из интерфейса перекрывают .env по каждому полю отдельно: у кого-то
 * заполнен только адрес прокси, ключ при этом остаётся в переменных окружения.
 * Пустое поле — это «не задано», а не «пусто»: иначе первое же сохранение формы
 * с незаполненным ключом обнулило бы рабочую конфигурацию.
 */
final readonly class ModelSettings
{
    public function __construct(private AiSetting $stored) {}

    public static function current(): self
    {
        return new self(AiSetting::current());
    }

    public function model(): string
    {
        return $this->value($this->stored->model) ?? (string) config('ai.model');
    }

    public function maxTokens(): int
    {
        return $this->stored->max_tokens ?? (int) config('ai.max_tokens');
    }

    /**
     * Модель для смыслового поиска, или null — тогда поиск остаётся словесным.
     *
     * Пустое значение не ошибка: консультант работает и без эмбеддингов, просто
     * хуже находит то, что названо в материале другими словами.
     */
    public function embeddingModel(): ?string
    {
        return $this->value($this->stored->embedding_model) ?? $this->value(config('ai.embedding_model'));
    }

    /** Адрес API, или null — тогда SDK возьмёт свой собственный. */
    public function baseUrl(): ?string
    {
        $endpoint = $this->value($this->stored->base_url) ?? $this->value(config('ai.base_url'));

        // SDK дописывает к адресу относительный `v1/messages`, поэтому лишний
        // конечный слэш дал бы `//v1/messages` и 404 на стороне прокси.
        return $endpoint === null ? null : rtrim($endpoint, '/');
    }

    public function authScheme(): AiAuthScheme
    {
        // Схема при незаданном ключе в базе следует за тем, какая из двух
        // переменных окружения заполнена.
        if ($this->key() === null || $this->value($this->stored->api_key) !== null) {
            return $this->stored->auth_scheme ?? AiAuthScheme::Bearer;
        }

        return $this->value(config('ai.auth_token')) !== null
            ? AiAuthScheme::Bearer
            : AiAuthScheme::Header;
    }

    public function key(): ?string
    {
        return $this->value($this->stored->api_key)
            ?? $this->value(config('ai.auth_token'))
            ?? $this->value(config('ai.api_key'));
    }

    public function isConfigured(): bool
    {
        return $this->key() !== null;
    }

    /**
     * Клиент, настроенный так, как просили.
     */
    public function client(): Client
    {
        $key = $this->key();
        $bearer = $this->authScheme() === AiAuthScheme::Bearer;

        return new Client(
            apiKey: $bearer ? null : $key,
            authToken: $bearer ? $key : null,
            baseUrl: $this->baseUrl(),
        );
    }

    private function value(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}
