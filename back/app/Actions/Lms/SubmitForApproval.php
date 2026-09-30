<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\ApprovalStatus;
use App\Exceptions\ConflictException;
use App\Jobs\SendPush;
use App\Models\Contracts\Approvable;
use App\Models\MaterialReview;
use App\Models\User;
use App\Support\Lms\MaterialApprovals;
use App\Support\Push\PushMessage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Отправить материал на согласование.
 *
 * Автор выбирает, выложить материал самому или показать его сперва одному
 * человеку либо нескольким (решение пользователя 2026-09-30). Отправка — новый
 * круг: список согласующих, ждущие ответы и уведомление каждому.
 *
 * Действием, а не строчкой в контроллере: правил тут четыре, и спрашивают их и
 * первая отправка, и повторная после возврата.
 */
final readonly class SubmitForApproval
{
    public function __construct(private MaterialApprovals $approvals) {}

    /**
     * @param  list<int>  $approvers  кого просят согласовать
     */
    public function handle(Model&Approvable $material, User $author, array $approvers): MaterialReview
    {
        if ($this->approvals->open($material) !== null) {
            throw new ConflictException('Материал уже на согласовании.');
        }

        $people = $this->people($approvers, $author);

        $review = DB::transaction(function () use ($material, $author, $people): MaterialReview {
            /** @var MaterialReview $review */
            $review = $material->reviews()->create([
                // Номер следующего круга. Возврат заканчивает круг, и
                // исправленный материал уходит новым — см. DecideMaterialApproval.
                'round' => (int) $material->reviews()->max('round') + 1,
                'status' => ApprovalStatus::Pending,
                'requested_by' => $author->getKey(),
            ]);

            foreach ($people as $person) {
                $review->decisions()->create([
                    'user_id' => $person,
                    'status' => ApprovalStatus::Pending,
                ]);
            }

            return $review;
        });

        $this->tell($material, $author, $people);

        return $review->load(['decisions.user:id,last_name,first_name,middle_name', 'requester:id,last_name,first_name,middle_name']);
    }

    /**
     * Кого правда позовут.
     *
     * Автор из списка вычитается: согласовывать у самого себя нечего, а строка
     * «ждёт вашего ответа» на своей же отправке читалась бы поломкой. Уволенные
     * — тоже: платформа для них закрыта, и круг ждал бы ответа вечно.
     *
     * @param  list<int>  $approvers
     * @return list<int>
     */
    private function people(array $approvers, User $author): array
    {
        $people = User::query()
            ->employed()
            ->whereIn('id', $approvers)
            ->whereKeyNot($author->getKey())
            ->pluck('id')
            ->map(intval(...))
            ->all();

        if ($people === []) {
            throw new ConflictException('Выберите, кто согласует материал.');
        }

        return $people;
    }

    /**
     * Уведомить согласующих.
     *
     * Очередью, как и все уведомления на телефон: чужая служба доставки не
     * должна задерживать ответ экрану — см. Jobs\SendPush.
     *
     * @param  list<int>  $people
     */
    private function tell(Model&Approvable $material, User $author, array $people): void
    {
        SendPush::dispatch($people, new PushMessage(
            title: 'Просят согласовать',
            body: sprintf(
                '%s: «%s» — отправил %s',
                $material->approvalLabel(),
                PushMessage::shorten($material->approvalTitle(), 60),
                $author->name,
            ),
            url: $material->approvalPath(),

            // Имя своё у каждого материала: два разных материала, присланных
            // подряд, — это два уведомления, а не одно поверх другого.
            tag: 'approval-'.$material->getMorphClass().'-'.$material->getKey(),
        ));
    }
}
