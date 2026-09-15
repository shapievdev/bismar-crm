<?php

declare(strict_types=1);

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Внешняя ссылка на отчёт: цифры без фамилий, по токену и на срок.
 *
 * Нужна ровно для одного разговора — показать движение персонала тому, у кого
 * нет учётной записи: собственнику, консультанту, аудитору. Поэтому хранится не
 * отчёт, а срез, по которому он пересчитывается на каждое открытие: замороженная
 * выборка через месяц врала бы, а список людей в ней не должен оказаться даже
 * случайно.
 *
 * Срок обязателен. Ссылка уходит наружу и живёт в переписках дольше, чем повод,
 * ради которого её выдали, — бессрочная означала бы «навсегда».
 */
#[Fillable(['created_by_id', 'filters', 'expires_at'])]
class StaffReportLink extends Model
{
    /** Сколько знаков в токене: 32 байта случайности в шестнадцатеричном виде. */
    private const TOKEN_BYTES = 32;

    protected static function booted(): void
    {
        // Токен рождается вместе с записью, а не назначается снаружи: ссылка
        // без токена — запись, на которую никто не сможет зайти, а токен,
        // выбранный человеком, — это токен, который можно угадать.
        static::creating(static function (self $link): void {
            $link->token ??= bin2hex(random_bytes(self::TOKEN_BYTES / 2));
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'expires_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    /** Открывается ли она прямо сейчас. */
    public function isLive(): bool
    {
        return $this->revoked_at === null && $this->expires_at->isFuture();
    }

    /**
     * Почему не открывается — то, что показывают открывшему.
     *
     * Отозванная и просроченная различаются: «срок истёк» подсказывает
     * попросить новую ссылку, «отозвана» — что просить её больше не нужно.
     */
    public function refusal(): ?string
    {
        return match (true) {
            $this->revoked_at !== null => 'Ссылка отозвана.',
            $this->expires_at->isPast() => 'Срок действия ссылки истёк.',
            default => null,
        };
    }

    /**
     * Живые ссылки — те, по которым отчёт ещё отдаётся.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNull('revoked_at')->where('expires_at', '>', CarbonImmutable::now());
    }

    /**
     * Кусочек токена для списка.
     *
     * Целиком он в списке не нужен: ссылку выдают один раз при создании, а
     * дальше её узнают по хвосту — как карту по последним четырём цифрам.
     */
    public function hint(): string
    {
        return '…'.Str::substr($this->token, -6);
    }
}
