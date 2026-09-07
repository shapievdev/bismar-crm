<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Regulation;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * «Частые вопросы» — материалы, приколотые к документу.
 *
 * Список задаётся целиком и в том порядке, в каком пришёл: экран показывает его
 * весь, «сохранить» там означает «пусть будет вот так», а порядок — это и есть
 * ответ на вопрос «с чем приходят чаще».
 *
 * Связь односторонняя, поэтому и переписывается одна сторона: у приколотого
 * материала свой список, и он не наш, чтобы его трогать. Этим она и отличается
 * от соседства — см. LinkRegulations, где пара строк живёт и умирает вместе.
 */
final readonly class PinFrequentQuestions
{
    /**
     * @param  list<int>  $questionIds  в том порядке, в каком их показывать
     */
    public function set(Regulation $regulation, array $questionIds, User $actor): Regulation
    {
        $id = (int) $regulation->getKey();

        // Сам на себя материал не ссылается: строка вела бы на страницу,
        // которую читатель и так открыл. То же правило стоит в базе — на
        // случай, если сюда однажды придут в обход.
        $wanted = array_values(array_diff(
            array_unique(array_map(intval(...), $questionIds)),
            [$id],
        ));

        DB::transaction(function () use ($regulation, $wanted, $actor): void {
            /*
             * Список переписывается целиком, а не сверяется построчно: порядок
             * задаёт номер в строке, и перестановка двух материалов местами —
             * это правка каждой строки между ними. Разбирать такие переносы
             * дороже, чем записать десяток строк заново.
             */
            $regulation->questions()->detach();

            $regulation->questions()->attach(
                collect($wanted)
                    ->mapWithKeys(fn (int $questionId, int $index): array => [
                        $questionId => ['position' => $index + 1, 'pinned_by_id' => $actor->getKey()],
                    ])
                    ->all(),
            );
        });

        return $regulation->load('questions');
    }
}
