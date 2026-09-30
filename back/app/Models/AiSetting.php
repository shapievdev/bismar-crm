<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AiAuthScheme;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Log;

/**
 * Одна строка настроек консультанта.
 *
 * Единственная — таблица заведена ради одной записи, потому что настройки
 * общие для всей системы. Отсутствие записи означает «всё из .env».
 */
#[Fillable(['model', 'embedding_model', 'base_url', 'api_key', 'auth_scheme', 'max_tokens', 'updated_by'])]
class AiSetting extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            // Ключ лежит в базе шифрованным: дамп базы уходит на ноутбуки и в
            // бэкапы чаще, чем .env.
            'api_key' => 'encrypted',
            'auth_scheme' => AiAuthScheme::class,
        ];
    }

    /**
     * Настройки системы, существующие или пустые.
     *
     * Никогда не null, чтобы вызывающему не приходилось помнить о случае
     * «ещё ни разу не настраивали».
     */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? new self;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * Ключ из базы — или null, если прочитать его нечем.
     *
     * Строка зашифрована `APP_KEY` той системы, где ключ вводили, а дамп базы
     * как раз и уезжает на ноутбуки и на чужие стенды — там `Crypt` отвечает
     * «The MAC is invalid». **Нечитаемый ключ — это «не задан»**, ровно как если
     * бы его не вводили вовсе: спрашивают о нём не только у консультанта, а на
     * каждом сохранении материала и его версии (MaterialVersionObserver →
     * EmbedRegulation::dispatchIfConfigured), и исключение валило запрос, к
     * консультанту отношения не имеющий, — «не удалось завести версию» без
     * единого слова о том, почему.
     *
     * Тихо возвращать null нельзя: вместе с ключом молча отваливается смысловой
     * поиск, и причину в интерфейсе видно только по этой записи в журнале — и по
     * оговорке на экране настроек (`key_unreadable`).
     */
    public function readableKey(): ?string
    {
        try {
            $key = trim((string) $this->api_key);
        } catch (DecryptException $exception) {
            Log::warning('Ключ консультанта в базе не читается этим APP_KEY — считаем незаданным.', [
                'setting' => $this->getKey(),
                'exception' => $exception,
            ]);

            return null;
        }

        return $key === '' ? null : $key;
    }

    /**
     * Ключ сохранён, но этим `APP_KEY` не расшифровывается.
     *
     * Отличается от «ключ не задан» тем, что вводить его заново обязательно:
     * пустое поле человек оставил сам, а нечитаемое досталось ему вместе с
     * чужим дампом.
     */
    public function hasUnreadableKey(): bool
    {
        // Строка берётся сырой, до расшифровки: спросить у самого свойства
        // значило бы задать тот же вопрос тем же исключением.
        return ($this->getAttributes()['api_key'] ?? null) !== null && $this->readableKey() === null;
    }

    /** Последние знаки ключа — чтобы человек узнал свой, не видя чужого. */
    public function keyHint(): ?string
    {
        $key = $this->readableKey();

        return $key === null ? null : '…'.mb_substr($key, -4);
    }
}
