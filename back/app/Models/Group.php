<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\GroupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * Группа сотрудников — список людей, собранный вручную.
 *
 * Отдел говорит, где человек работает; группа — кого зовут вместе. Дерева не
 * образует и прав не даёт: это адресат рассылки и новости.
 */
#[Fillable(['name', 'description'])]
class Group extends Model
{
    /** @use HasFactory<GroupFactory> */
    use HasFactory;

    /**
     * Люди группы.
     *
     * Уволенные отсеиваются, как и в отделе: группа отвечает на вопрос «кого
     * позвать», и ушедший в ней только сбивал бы счёт. Строка связи остаётся —
     * вернувшийся в строй возвращается в свои группы.
     *
     * @return BelongsToMany<User, $this>
     */
    public function people(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')
            ->withTimestamps()
            // Столбец назван таблицей: в запросе к связи их две, и без имени
            // Postgres не знает, чьё это поле.
            ->whereNull('users.dismissed_at')
            // Порядок — здесь, а не у каждого, кто спрашивает состав: список
            // читают по алфавиту, и другого порядка у него не бывает.
            ->orderByRaw('COALESCE(users.last_name, users.first_name) COLLATE "und-x-icu"');
    }

    /**
     * Все, кого когда-либо добавляли, — вместе с уволенными.
     *
     * Нужна тому, кто правит состав: изъятие уволенного из группы должно
     * доходить до базы, а через `people()` его не видно.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'group_members')->withTimestamps();
    }

    /**
     * Группы под строку поиска — по названию и по описанию.
     *
     * Сверяется с ICU: базы собраны с C-сортировкой, где ILIKE складывает
     * только латиницу, так что «наставники» иначе не нашли бы «Наставников».
     *
     * @param  Builder<$this>  $query
     */
    public function scopeMatching(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term).'%';

        $query->where(function (Builder $query) use ($pattern): void {
            foreach (['name', 'description'] as $column) {
                $query->orWhereRaw(sprintf('%s COLLATE "und-x-icu" ILIKE ?', $column), [$pattern]);
            }
        });
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderByRaw('name COLLATE "und-x-icu"');
    }
}
