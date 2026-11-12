<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/**
 * Достижение — значок с картинкой: «Первый выход в зал», «Сдал на кассу».
 *
 * Справочник, а не перечисление в коде: завести новый значок через выкат
 * значит не завести его никогда. Вешается на шаг адаптации — на этап целиком
 * или на отдельное дело внутри него, — и выдаётся сам, когда шаг закрыт.
 *
 * Удаляется мягко, в отличие от тега сотрудника. Тег — ярлык, и снятый с
 * каталога снимается со всех; достижение — заслуга, и выкинуть его из
 * каталога не значит отобрать у тех, кто его заработал. Спрятанное пропадает
 * из выбора, но остаётся в их профилях.
 */
#[Fillable(['title', 'description', 'position', 'created_by', 'image_path'])]
class Achievement extends Model
{
    use SoftDeletes;

    private const DISK = 's3';

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Кому выдано.
     *
     * @return BelongsToMany<User, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'achievement_user')
            ->withPivot(['awarded_at', 'reason', 'source_type', 'source_id'])
            ->withTimestamps();
    }

    /**
     * @return HasMany<AchievementAward, $this>
     */
    public function awards(): HasMany
    {
        return $this->hasMany(AchievementAward::class);
    }

    /**
     * Ссылка на картинку — короткоживущая и подписанная, как у аватарки.
     * Считается локально, поэтому её не жалко на каждый значок в списке.
     */
    public function imageUrl(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        return Storage::disk(self::DISK)->temporaryUrl(
            $this->image_path,
            now()->addMinutes(config('lms.attachment_url_ttl_minutes')),
        );
    }

    /**
     * Порядок каталога: сначала выставленный руками, потом по названию.
     *
     * Сортировка баз — `C`, и `order by title` поставил бы «Эксперт» перед
     * «Ас»: русские буквы в ней сравниваются по кодам (см. README).
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderByRaw('title collate "und-x-icu"');
    }
}
