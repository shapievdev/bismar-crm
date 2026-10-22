<?php

declare(strict_types=1);

namespace App\Actions\Sales;

use App\Models\SalesPerson;
use App\Support\Sales\SellerDirectory;
use Illuminate\Support\Facades\DB;

/**
 * Принять все подсказки разом.
 *
 * Имён в выгрузке под две сотни, и половина из них разбирается без человека:
 * «ЛКМ Агабекова Света Шагабудиновна» — это Агабекова Света из отдела ЛКМ, и
 * нажимать это по одному двести раз незачем.
 *
 * Трогает только нетронутое. Отдел, проставленный руками, подсказка не
 * переписывает никогда — иначе одно нажатие отменяло бы всю прошлую работу, и
 * хуже того, молча: человека перевели в другой отдел, подсказка знает об этом
 * из структуры, а решение держать его выручку на прежнем месте было
 * осознанным.
 */
final readonly class AcceptSuggestedSellers
{
    public function __construct(private SellerDirectory $directory) {}

    /**
     * Сколько связей завелось.
     */
    public function __invoke(): int
    {
        $accepted = 0;

        DB::transaction(function () use (&$accepted): void {
            foreach ($this->directory->toArray()['sellers'] as $seller) {
                if ($seller['department_id'] !== null || $seller['user_id'] !== null) {
                    continue;
                }

                $suggestion = $seller['suggestion'];

                if ($suggestion['department_id'] === null && $suggestion['user_id'] === null) {
                    continue;
                }

                SalesPerson::query()->updateOrCreate(
                    ['external_name' => $seller['external_name']],
                    [
                        'department_id' => $suggestion['department_id'],
                        'user_id' => $suggestion['user_id'],
                    ],
                );

                $accepted++;
            }
        });

        return $accepted;
    }
}
