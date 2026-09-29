<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Concerns\ManagesMaterialVersions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveVersionRequest;
use App\Http\Resources\Lms\MaterialVersionResource;
use App\Models\MaterialVersion;
use App\Models\Regulation;
use App\Support\Lms\BlockIdentifier;
use App\Support\Lms\MaterialVersions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * Версии документа — то же правило, написанное для своих людей.
 *
 * Ведёт их тот, кто правит материал: версия — его часть, а не отдельный
 * документ, и отдельного права у неё нет. Читает — всякий, кому версия открыта:
 * закрытая только своим группам, открытая всем, кому открыт сам документ.
 *
 * Общей версии здесь нет ни одним маршрутом: она — сам документ, и читается
 * теми же адресами, что и до всякого разделения.
 *
 * Сами действия лежат в ManagesMaterialVersions: у версии урока они те же, и
 * разного между ними ровно два права — чьим её правят и чьим читают.
 */
final class RegulationVersionController extends Controller
{
    use ManagesMaterialVersions;

    public function __construct(
        private readonly MaterialVersions $versions,
        private readonly BlockIdentifier $blocks,
    ) {}

    public function index(Regulation $regulation): AnonymousResourceCollection
    {
        return $this->listFor($regulation);
    }

    public function show(Request $request, Regulation $regulation, MaterialVersion $version): MaterialVersionResource
    {
        return $this->showOne($request, $regulation, $version);
    }

    public function store(SaveVersionRequest $request, Regulation $regulation): JsonResponse
    {
        return $this->storeFor($request, $regulation);
    }

    public function update(
        SaveVersionRequest $request,
        Regulation $regulation,
        MaterialVersion $version,
    ): MaterialVersionResource {
        return $this->updateOne($request, $regulation, $version);
    }

    public function reorder(Request $request, Regulation $regulation): AnonymousResourceCollection
    {
        return $this->reorderFor($request, $regulation);
    }

    public function destroy(Regulation $regulation, MaterialVersion $version): Response
    {
        return $this->destroyOne($regulation, $version);
    }

    protected function authorizeManaging(Model $material): void
    {
        Gate::authorize('update', $material);
    }

    protected function authorizeReading(Model $material): void
    {
        Gate::authorize('view', $material);
    }
}
