<?php

declare(strict_types=1);

namespace App\Support\Lms;

use App\Enums\ApprovalStatus;
use App\Models\Contracts\Approvable;
use App\Models\MaterialReview;
use App\Models\MaterialReviewDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Согласование материала — одно правило на всё приложение.
 *
 * Спрашивают о нём с четырёх концов, и держать ответы врозь значило бы однажды
 * расширить один и забыть остальные:
 *
 * - политика материала — можно ли этому человеку открыть страницу;
 * - доступ (RegulationAccess, CourseAccess) — существует ли для него закрытый
 *   материал;
 * - очередь — что ждёт его ответа;
 * - значок в полосе разделов — сколько именно ждёт.
 *
 * **Чтение по согласованию — временное и целевое.** Позванный в круг читает
 * материал, даже если он закрытый и человека там нет, — иначе согласовать нечего
 * (требование пользователя 2026-09-30). Но ровно пока круг идёт: согласовали,
 * вернули или отозвали — и материал закрывается снова, если человека не впустили
 * туда обычным порядком.
 *
 * **В каталог и в корпус консультанта это чтение не попадает намеренно.** Оно
 * даётся, чтобы посмотреть и ответить, а не чтобы материал завёлся у человека в
 * поиске: к странице он приходит из своей очереди или по уведомлению. Тем же
 * решается и вопрос посерьёзнее — консультант не должен отвечать по тексту,
 * который ещё не согласовали.
 */
final class MaterialApprovals
{
    /**
     * Идущий круг этого материала.
     *
     * Через модель, а не запросом: у прочитанного вместе с материалом круга
     * ответы уже на руках, и второй запрос был бы лишним.
     */
    public function open(Model $material): ?MaterialReview
    {
        return method_exists($material, 'openReview') ? $material->openReview() : null;
    }

    /**
     * Позван ли человек в идущий круг — любым ответом, в том числе уже данным.
     *
     * Это и есть право прочитать: согласовавший вправе перечитать материал, пока
     * круг не закрылся, — он ещё отвечает за своё «согласен».
     */
    public function participates(Model $material, ?User $person): bool
    {
        return $person !== null && $this->hasDecision($material, $person, onlyPending: false);
    }

    /**
     * Ждут ли от человека ответа именно сейчас.
     *
     * Этим решается, показывать ли кнопки «Согласовать» и «Вернуть»: уже
     * ответивший второй раз не отвечает.
     */
    public function awaits(Model $material, ?User $person): bool
    {
        return $person !== null && $this->hasDecision($material, $person, onlyPending: true);
    }

    /**
     * Сколько материалов ждёт ответа этого человека — для значка в полосе
     * разделов.
     */
    public function pendingFor(User $person): int
    {
        return $this->queueFor($person)
            ->where('material_review_decisions.status', ApprovalStatus::Pending)
            /*
             * …и круг ещё идёт.
             *
             * Нерешённый ответ переживает свой круг: автор отозвал отправку или
             * выложил материал сам, кто-то другой вернул его на исправление — и
             * строка человека навсегда остаётся «не ответил», потому что
             * отвечать уже не на что. Без этого условия значок считал такие
             * строки и звал разбирать очередь, в которой ничего нет.
             */
            ->whereHas('review', fn (Builder $query) => $query->open())
            ->count();
    }

    /**
     * Что сейчас с материалами, которые отправлял этот человек.
     *
     * Только последний круг каждого: прежние — история, и ответ на вопрос «что с
     * моим материалом» даёт именно последний. Только незакрытое дело: идущий круг
     * («ждём троих») и возврат («исправьте и отправьте снова»). Согласованное сюда
     * не попадает — оно вышло, и делать с ним нечего.
     *
     * @return Collection<int, MaterialReview>
     */
    public function mine(User $person): Collection
    {
        return MaterialReview::query()
            ->where('requested_by', $person->getKey())
            ->whereIn('status', [ApprovalStatus::Pending, ApprovalStatus::Returned])
            ->whereNotExists(fn (QueryBuilder $query) => $query
                ->selectRaw('1')
                ->from('material_reviews as newer')
                ->whereColumn('newer.reviewable_type', 'material_reviews.reviewable_type')
                ->whereColumn('newer.reviewable_id', 'material_reviews.reviewable_id')
                ->whereColumn('newer.round', '>', 'material_reviews.round'))
            ->with(['reviewable', 'decisions.user:id,last_name,first_name,middle_name'])
            ->orderByDesc('created_at')
            ->get()
            // Материал мог уехать в корзину: строка без материала ни о чём не
            // говорит и вести с неё некуда.
            ->filter(fn (MaterialReview $review): bool => $review->reviewable !== null)
            ->values();
    }

    /**
     * Сколько моих материалов вернули и ждут исправления — вторая половина
     * значка.
     *
     * Ответ проверяющего должен быть виден не только уведомлением на телефон:
     * телефон мог быть выключен, а уведомление — заменено следующим. Возврат — это
     * работа, которая вернулась к автору, и она обязана числиться за ним так же,
     * как чужой материал числится за согласующим.
     *
     * Выложенное руками не считается: круг остался возвращённым, но автор уже
     * ответил на него по-своему — выпустил материал, и напоминание про
     * исправление стало бы вечным.
     */
    public function returnedFor(User $person): int
    {
        return $this->mine($person)
            ->filter(fn (MaterialReview $review): bool => $review->status === ApprovalStatus::Returned
                && $review->reviewable instanceof Approvable
                && ! $review->reviewable->isPublished())
            ->count();
    }

    /** Отправлял ли человек что-нибудь на согласование — для показа раздела. */
    public function everSubmitted(User $person): bool
    {
        return MaterialReview::query()->where('requested_by', $person->getKey())->exists();
    }

    /**
     * Звали ли человека согласовывать хоть раз.
     *
     * От этого зависит, показывать ли ему раздел «Согласование»: по числу
     * ждущих это решать нельзя — вкладка исчезала бы, едва он разобрал очередь, а
     * с нею и путь к тому, что он уже решил. Та же ошибка была у аттестаций, и
     * её там уже исправляли.
     */
    public function everAsked(User $person): bool
    {
        return $this->queueFor($person)->exists();
    }

    /**
     * Очередь согласующего: его ответы во всех кругах, куда его звали.
     *
     * Отбор идёт по вошедшему, а не по тому, что попросил клиент, — чужую
     * очередь этим запросом не открыть.
     *
     * @return Builder<MaterialReviewDecision>
     */
    public function queueFor(User $person): Builder
    {
        return MaterialReviewDecision::query()
            ->where('material_review_decisions.user_id', $person->getKey());
    }

    /**
     * Есть ли у человека строка в идущем круге этого материала.
     *
     * Одним EXISTS по индексу: спрашивают об этом на каждом открытии страницы
     * закрытого материала, и вычитывать ради ответа круг с ответами незачем.
     */
    private function hasDecision(Model $material, User $person, bool $onlyPending): bool
    {
        return DB::table('material_review_decisions')
            ->join('material_reviews', 'material_reviews.id', '=', 'material_review_decisions.review_id')
            ->where('material_reviews.reviewable_type', $material->getMorphClass())
            ->where('material_reviews.reviewable_id', $material->getKey())
            ->where('material_reviews.status', ApprovalStatus::Pending->value)
            ->where('material_review_decisions.user_id', $person->getKey())
            ->when(
                $onlyPending,
                fn ($query) => $query->where('material_review_decisions.status', ApprovalStatus::Pending->value),
            )
            ->exists();
    }
}
