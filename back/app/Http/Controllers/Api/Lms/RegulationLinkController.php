<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\LinkRegulations;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateRegulationLinksRequest;
use App\Http\Resources\Lms\RegulationLinkResource;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Соседние документы — «рядом по теме».
 *
 * Ведёт список тот, кто правит документ; связь взаимная, поэтому список,
 * сохранённый здесь, меняет блок сразу на двух страницах — см. LinkRegulations.
 *
 * Всюду, где документ приходит номером или уезжает названием, он пропускается
 * через доступ читающего: закрытый документ выдаёт себя одним заголовком не
 * хуже, чем открытой страницей.
 */
final class RegulationLinkController extends Controller
{
    /** Сколько документов показывает подсказка поиска. */
    private const CANDIDATES = 20;

    public function index(Request $request, Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('update', $regulation);

        /** @var User $editor */
        $editor = $request->user();

        return RegulationLinkResource::collection($this->neighboursOf($regulation, $editor));
    }

    public function update(
        UpdateRegulationLinksRequest $request,
        Regulation $regulation,
        LinkRegulations $links,
    ): AnonymousResourceCollection {
        Gate::authorize('update', $regulation);

        /** @var User $editor */
        $editor = $request->user();

        // Только то, что этому редактору открыто: иначе чужой закрытый документ
        // можно было бы связать наугад по номеру и прочитать его название в
        // ответе. Соседи, которых он не видит, при этом остаются на месте — их
        // поставил тот, кому они видны, и его список не наш, чтобы его чистить.
        $links->set(
            $regulation,
            [
                ...$this->allowed($regulation, $request->documents(), $editor),
                ...$this->hiddenFrom($regulation, $editor),
            ],
            $editor,
        );

        return RegulationLinkResource::collection($this->neighboursOf($regulation->refresh(), $editor));
    }

    /**
     * Кого ещё можно поставить рядом. Поиском, а не списком целиком: документов
     * сотни, а нужен из них один.
     */
    public function candidates(Request $request, Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('update', $regulation);

        /** @var User $editor */
        $editor = $request->user();

        $found = Regulation::query()
            ->with('category')
            // Только из своего раздела: «рядом по теме» ведёт читателя дальше
            // по тому же разделу, а не перебрасывает из справок в правила.
            ->ofKind($regulation->kind)
            ->whereKeyNot($regulation->getKey())
            ->whereNotIn('id', fn ($query) => $query
                ->select('related_id')
                ->from('regulation_links')
                ->where('regulation_id', $regulation->getKey()))
            ->visibleTo($editor)
            ->matching($request->query('search'))
            ->orderByRaw('title COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();

        return RegulationLinkResource::collection($found);
    }

    /**
     * @return Collection<int, Regulation>
     */
    private function neighboursOf(Regulation $regulation, User $reader): Collection
    {
        return $regulation->related()->with('category')->visibleTo($reader)->get();
    }

    /**
     * Из присланных номеров — те материалы своего раздела, что этому человеку
     * открыты.
     *
     * @param  list<int>  $documents
     * @return list<int>
     */
    private function allowed(Regulation $regulation, array $documents, User $editor): array
    {
        if ($documents === []) {
            return [];
        }

        return Regulation::query()
            ->ofKind($regulation->kind)
            ->whereKey($documents)
            ->visibleTo($editor)
            ->pluck('id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Соседи, которых этот редактор не видит.
     *
     * Их нет на его экране, и «сохранить» с его стороны не должно означать
     * «убрать»: закрытый документ поставил рядом тот, кому он открыт.
     *
     * @return list<int>
     */
    private function hiddenFrom(Regulation $regulation, User $editor): array
    {
        $all = $regulation->related()->pluck('regulations.id')->map(intval(...))->all();
        $visible = $regulation->related()->visibleTo($editor)->pluck('regulations.id')->map(intval(...))->all();

        return array_values(array_diff($all, $visible));
    }
}
