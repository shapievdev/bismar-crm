<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Sales;

use App\Actions\Sales\AcceptSuggestedSellers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sales\SellerRequest;
use App\Models\SalesPerson;
use App\Support\Sales\SellerDirectory;
use Illuminate\Http\JsonResponse;

/**
 * Сопоставление продавцов витрины с отделами и сотрудниками.
 *
 * Та самая связь, без которой выручку по отделам не разложить: в 1С продавец —
 * строка с приставкой, у нас — человек в структуре, и общего ключа у них нет.
 */
final class SellerController extends Controller
{
    public function index(SellerDirectory $directory): JsonResponse
    {
        return response()->json(['data' => $directory->toArray()]);
    }

    /**
     * Завести или поправить связь.
     *
     * Снятая целиком — удаляется, а не остаётся пустой строкой: строка без
     * отдела и без человека ничего не утверждает, но попадалась бы в списках и
     * выглядела бы сопоставлением.
     */
    public function update(SellerRequest $request): JsonResponse
    {
        $name = $request->externalName();
        $department = $request->departmentId();
        $user = $request->userId();

        if ($department === null && $user === null) {
            SalesPerson::query()->where('external_name', $name)->delete();

            return response()->json(['data' => null]);
        }

        $person = SalesPerson::query()->updateOrCreate(
            ['external_name' => $name],
            ['department_id' => $department, 'user_id' => $user],
        );

        return response()->json(['data' => [
            'external_name' => $person->external_name,
            'department_id' => $person->department_id,
            'user_id' => $person->user_id,
        ]]);
    }

    /**
     * Принять подсказки по всем, кого ещё не трогали.
     *
     * Отдельным действием, а не галочкой в списке: это правка сразу сотни
     * строк, и она должна быть нажата осознанно.
     */
    public function accept(AcceptSuggestedSellers $accept): JsonResponse
    {
        return response()->json(['data' => ['accepted' => $accept()]]);
    }
}
