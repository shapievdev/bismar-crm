<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Regulation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Соседние документы: «рядом по теме».
 *
 * Связь взаимная, и держится она двумя строками — прямой и встречной. Заводить
 * и снимать их порознь нельзя: односторонняя строка означала бы блок, который
 * есть на одной странице и пропал на другой, а объяснить это читателю нечем.
 * Поэтому пара живёт только здесь и только внутри одной транзакции.
 *
 * Список задаётся целиком, как и списки людей (см. SyncRegulationPeople):
 * экран показывает его весь, и «сохранить» там означает «пусть будет вот так».
 */
final readonly class LinkRegulations
{
    /**
     * @param  list<int>  $relatedIds
     */
    public function set(Regulation $regulation, array $relatedIds, User $actor): Regulation
    {
        $id = (int) $regulation->getKey();

        // Сам себе соседом документ не бывает: ссылка вела бы на страницу,
        // которую читатель и так открыл. То же правило стоит в базе — на
        // случай, если сюда однажды придут в обход.
        $wanted = array_values(array_diff(
            array_unique(array_map(intval(...), $relatedIds)),
            [$id],
        ));

        DB::transaction(function () use ($regulation, $id, $wanted, $actor): void {
            $current = $regulation->related()->pluck('regulations.id')->map(intval(...))->all();

            $this->unlink($id, array_values(array_diff($current, $wanted)));
            $this->link($id, array_values(array_diff($wanted, $current)), $actor);
        });

        return $regulation->load('related');
    }

    /**
     * @param  list<int>  $others
     */
    private function link(int $id, array $others, User $actor): void
    {
        if ($others === []) {
            return;
        }

        $now = now();
        $rows = [];

        foreach ($others as $other) {
            $row = ['linked_by_id' => $actor->getKey(), 'created_at' => $now, 'updated_at' => $now];

            $rows[] = ['regulation_id' => $id, 'related_id' => $other, ...$row];
            $rows[] = ['regulation_id' => $other, 'related_id' => $id, ...$row];
        }

        // Молча пропуская уже существующее: встречная строка могла уцелеть от
        // прежней связи, и падать на ней — значит не дать сохранить список.
        DB::table('regulation_links')->insertOrIgnore($rows);
    }

    /**
     * @param  list<int>  $others
     */
    private function unlink(int $id, array $others): void
    {
        if ($others === []) {
            return;
        }

        DB::table('regulation_links')
            ->where(fn ($query) => $query->where('regulation_id', $id)->whereIn('related_id', $others))
            ->orWhere(fn ($query) => $query->where('related_id', $id)->whereIn('regulation_id', $others))
            ->delete();
    }
}
