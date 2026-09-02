<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Models\GoogleSetting;

/**
 * Чем открывается окно выбора файла на Google Диске.
 *
 * Настройки из интерфейса перекрывают .env по каждому полю отдельно — так же,
 * как у консультанта (см. Ai\ModelSettings): у кого-то ключи уже прописаны на
 * сервере, и пустое поле формы должно означать «не задано здесь», а не «стереть
 * то, что работает».
 *
 * Оба значения публичны по устройству и уезжают в браузер: окно Google
 * открывается там. Прячет их не тайна, а список разрешённых источников в Google
 * Cloud — с чужого домена они бесполезны.
 */
final readonly class GoogleSettings
{
    public function __construct(private GoogleSetting $stored) {}

    public static function current(): self
    {
        return new self(GoogleSetting::current());
    }

    /** Номер приложения в Google Cloud: им сотрудник входит и выдаёт доступ. */
    public function clientId(): ?string
    {
        return $this->value($this->stored->client_id) ?? $this->value(config('services.google.client_id'));
    }

    /** Ключ, которым открывается само окно выбора файла. */
    public function apiKey(): ?string
    {
        return $this->value($this->stored->api_key) ?? $this->value(config('services.google.api_key'));
    }

    /**
     * Настроена ли интеграция.
     *
     * Нужны оба значения: с одним окно не открыть, а наполовину настроенная
     * кнопка хуже отсутствующей — по ней нажимают и получают ошибку Google.
     */
    public function isConfigured(): bool
    {
        return $this->clientId() !== null && $this->apiKey() !== null;
    }

    /** Пустая строка — это «не задано», а не значение. */
    private function value(mixed $raw): ?string
    {
        $value = trim((string) $raw);

        return $value === '' ? null : $value;
    }
}
