<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Закладка сотрудника: материал, который он отложил себе сам.
 *
 * Чужого решения здесь нет ни в одном виде. План обучения назначают, запись на
 * курс заводится сама от первого открытого урока, отметка «ознакомлен» говорит
 * о долге — а избранное человек ведёт для себя и видит только сам. Поэтому у
 * строки нет ни «кто отложил, кроме владельца», ни состояния: звезда либо
 * стоит, либо нет.
 *
 * Отложить можно курс, урок и материал — документ или справочник
 * (см. App\Enums\MaterialKind). Связь полиморфная, имена видов — из карты в
 * App\Providers\AppServiceProvider: в базе стоит `course`, `lesson`,
 * `regulation`, а не полное имя класса.
 */
#[Fillable(['user_id', 'favouritable_type', 'favouritable_id'])]
class Favourite extends Model
{
    /**
     * Чья закладка.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Что отложено — курс, урок, документ или справочник.
     *
     * @return MorphTo<Model, $this>
     */
    public function favouritable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Отложенное последним — сверху.
     *
     * Это же порядок по умолчанию и на странице: к свежей закладке возвращаются
     * чаще, чем к прошлогодней. Номер в разрыве — чтобы две закладки, сделанные
     * в одну секунду, не менялись местами от запроса к запросу.
     *
     * @param  Builder<Favourite>  $query
     */
    public function scopeFreshestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }
}
