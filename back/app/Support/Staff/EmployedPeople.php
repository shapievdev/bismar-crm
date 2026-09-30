<?php

declare(strict_types=1);

namespace App\Support\Staff;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Подсказка «кого выбрать» — работающие сотрудники по набранному.
 *
 * Одно место на все поручения, которые дают человеку, а не роли: проверять
 * аттестацию, согласовать материал. Поручение — не право с галочкой, поэтому
 * годится любой работающий сотрудник, а кто именно разбирается в вопросе, знает
 * тот, кто поручает.
 *
 * Класс завёлся, когда поручений стало два (2026-09-30): тот же запрос со
 * сортировкой по ICU стоял в двух контроллерах и разошёлся бы на первой правке —
 * например когда уволенных решат показывать серым, а не скрывать.
 */
final class EmployedPeople
{
    /** Сколько человек показывать в подсказке: список читают глазами, а не мотают. */
    public const SUGGESTIONS = 20;

    /**
     * @return Collection<int, User>
     */
    public function suggest(?string $search, int $limit = self::SUGGESTIONS): Collection
    {
        $term = trim((string) $search);

        return User::query()
            ->employed()
            ->when($term !== '', fn ($query) => $query->matching($term))
            // По фамилии и с учётом ICU, иначе «Ёлкин» окажется после
            // «Яковлева»: база собрана с C-сортировкой.
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->limit($limit)
            ->get();
    }
}
