<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SyncRegulationPeople;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateCourseAccessRequest;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Два списка людей у регламента: кого пустили и кто отвечает.
 *
 * Один контроллер на оба, в отличие от курсов, где под них два: разница только
 * в праве и в том, какое отношение править. Проверка присланного взята у
 * курсов как есть (UpdateCourseAccessRequest) — её правила о списке чисел, а не
 * о курсе.
 */
final class RegulationPeopleController extends Controller
{
    /** Сколько человек показывает подсказка поиска. */
    private const CANDIDATES = 20;

    /* ---------- Допущенные: право авторское ---------- */

    public function members(Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('manageAccess', $regulation);

        return CoursePersonResource::collection($this->byName($regulation->members()));
    }

    public function updateMembers(
        UpdateCourseAccessRequest $request,
        Regulation $regulation,
        SyncRegulationPeople $people,
    ): AnonymousResourceCollection {
        Gate::authorize('manageAccess', $regulation);

        /** @var User $actor */
        $actor = $request->user();

        $people->admit($regulation, $request->members(), $actor);

        return CoursePersonResource::collection($this->byName($regulation->refresh()->members()));
    }

    /**
     * Кого ещё можно пустить. Поиском, а не списком целиком: сотрудников тысячи,
     * а нужен из них один. Автор и уже допущенные не предлагаются.
     */
    public function memberCandidates(Request $request, Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('manageAccess', $regulation);

        return CoursePersonResource::collection($this->candidates(
            $request,
            $regulation,
            'regulation_members',
            excludeAuthor: true,
        ));
    }

    /* ---------- Ответственные: право редакторское ---------- */

    public function experts(Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('update', $regulation);

        return CoursePersonResource::collection($this->byName($regulation->experts()));
    }

    public function updateExperts(
        UpdateCourseAccessRequest $request,
        Regulation $regulation,
        SyncRegulationPeople $people,
    ): AnonymousResourceCollection {
        Gate::authorize('update', $regulation);

        /** @var User $actor */
        $actor = $request->user();

        $people->appoint($regulation, $request->members(), $actor);

        return CoursePersonResource::collection($this->byName($regulation->refresh()->experts()));
    }

    public function expertCandidates(Request $request, Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('update', $regulation);

        return CoursePersonResource::collection($this->candidates(
            $request,
            $regulation,
            'regulation_experts',
            excludeAuthor: false,
        ));
    }

    /**
     * @param  BelongsToMany<User, Regulation>  $relation
     * @return Collection<int, User>
     */
    private function byName(BelongsToMany $relation): Collection
    {
        // По фамилии, как читают список людей, — и с учётом ICU, иначе «Ёлкин»
        // оказался бы после «Яковлева»: базы собраны с C-сортировкой.
        return $relation
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->get();
    }

    /**
     * @return Collection<int, User>
     */
    private function candidates(
        Request $request,
        Regulation $regulation,
        string $pivot,
        bool $excludeAuthor,
    ): Collection {
        $search = trim((string) $request->query('search'));

        return User::query()
            ->when($excludeAuthor, fn (Builder $query) => $query->whereKeyNot($regulation->author_id ?? 0))
            ->whereNotIn('id', fn ($query) => $query
                ->select('user_id')
                ->from($pivot)
                ->where('regulation_id', $regulation->getKey()))
            // Уволенных не предлагают: платформа для них закрыта, и допуск
            // ничего бы им не открыл.
            ->employed()
            ->when($search !== '', fn (Builder $query) => $query->matching($search))
            ->orderByRaw('COALESCE(last_name, first_name) COLLATE "und-x-icu"')
            ->orderByRaw('first_name COLLATE "und-x-icu"')
            ->limit(self::CANDIDATES)
            ->get();
    }
}
