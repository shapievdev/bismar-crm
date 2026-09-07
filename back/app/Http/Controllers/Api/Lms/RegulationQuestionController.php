<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\PinFrequentQuestions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\UpdateFrequentQuestionsRequest;
use App\Http\Resources\Lms\RegulationLinkResource;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * «Частые вопросы» — список материалов, приколотых к документу.
 *
 * Ведёт его тот, кто правит документ. От соседей отличается двумя вещами:
 * список односторонний (у приколотого материала свой) и раздел не важен — к
 * правилу прикалывают и справочник, потому что сотруднику нужен ответ, а не
 * раздел, в котором он лежит.
 *
 * Всюду, где материал приходит номером или уезжает названием, он пропускается
 * через доступ читающего: закрытый материал выдаёт себя одним заголовком не
 * хуже, чем открытой страницей.
 */
final class RegulationQuestionController extends Controller
{
    /** Сколько материалов показывает подсказка поиска. */
    private const CANDIDATES = 20;

    public function index(Request $request, Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('update', $regulation);

        /** @var User $editor */
        $editor = $request->user();

        return RegulationLinkResource::collection($this->questionsOf($regulation, $editor));
    }

    public function update(
        UpdateFrequentQuestionsRequest $request,
        Regulation $regulation,
        PinFrequentQuestions $questions,
    ): AnonymousResourceCollection {
        Gate::authorize('update', $regulation);

        /** @var User $editor */
        $editor = $request->user();

        // Только то, что этому редактору открыто: иначе чужой закрытый материал
        // можно было бы приколоть наугад по номеру и прочитать его название в
        // ответе. Порядок при этом остаётся тот, в каком его прислали.
        $questions->set($regulation, $this->allowed($request->documents(), $editor), $editor);

        return RegulationLinkResource::collection($this->questionsOf($regulation->refresh(), $editor));
    }

    /**
     * Что ещё можно приколоть. Поиском, а не списком целиком: материалов сотни,
     * а нужен из них один.
     */
    public function candidates(Request $request, Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('update', $regulation);

        /** @var User $editor */
        $editor = $request->user();

        $found = Regulation::query()
            ->with('category')
            // Оба раздела: «частый вопрос» ведёт к ответу, а не по своему
            // разделу, — этим список и отличается от «рядом по теме».
            ->whereKeyNot($regulation->getKey())
            ->whereNotIn('id', fn ($query) => $query
                ->select('linked_id')
                ->from('regulation_questions')
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
    private function questionsOf(Regulation $regulation, User $reader): Collection
    {
        return $regulation->questions()->with('category')->visibleTo($reader)->get();
    }

    /**
     * Из присланных номеров — те материалы, что этому человеку открыты, в том
     * же порядке.
     *
     * Невидимые редактору строки список теряет — в отличие от соседства, где
     * они остаются: там связь общая на двоих, а здесь она принадлежит этой
     * странице целиком, и «сохранить» с пустого места означает именно то, что
     * редактор видит перед собой.
     *
     * @param  list<int>  $documents
     * @return list<int>
     */
    private function allowed(array $documents, User $editor): array
    {
        if ($documents === []) {
            return [];
        }

        $open = Regulation::query()
            ->whereKey($documents)
            ->visibleTo($editor)
            ->pluck('id')
            ->map(intval(...))
            ->all();

        return array_values(array_filter(
            $documents,
            static fn (int $id): bool => in_array($id, $open, strict: true),
        ));
    }
}
