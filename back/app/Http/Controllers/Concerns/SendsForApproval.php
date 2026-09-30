<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Actions\Lms\CancelApproval;
use App\Actions\Lms\SubmitForApproval;
use App\Http\Requests\Lms\SubmitForApprovalRequest;
use App\Http\Resources\Lms\MaterialReviewResource;
use App\Models\Contracts\Approvable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Отправить материал на согласование и отозвать отправку.
 *
 * Одно на все материалы, у которых бывает согласование: у документа, справочника
 * и курса это слово в слово одно и то же, а разное — право на правку — спрашивает
 * сам контроллер, как и у версий (см. ManagesMaterialVersions).
 *
 * Кто вправе отправлять: тот, кто вправе править материал. Отдельного права нет
 * намеренно — отправка на согласование это часть работы над материалом, а не
 * должность.
 */
trait SendsForApproval
{
    protected function sendForApproval(
        SubmitForApprovalRequest $request,
        Model&Approvable $material,
        SubmitForApproval $submit,
    ): MaterialReviewResource {
        /** @var User $author */
        $author = $request->user();

        return MaterialReviewResource::make(
            $submit->handle($material, $author, $request->approvers()),
        );
    }

    /**
     * Отозвать отправку — автор передумал, пока никто не ответил.
     *
     * Молчаливо соглашается с тем, что круга нет: «отозвать нечего» — не ошибка,
     * а обычный итог двойного нажатия.
     */
    protected function withdrawFromApproval(
        Request $request,
        Model&Approvable $material,
        CancelApproval $cancel,
    ): Response {
        /** @var User $author */
        $author = $request->user();

        $cancel->handle($material, $author);

        return response()->noContent();
    }
}
