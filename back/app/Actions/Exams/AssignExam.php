<?php

declare(strict_types=1);

namespace App\Actions\Exams;

use App\Jobs\SendPush;
use App\Models\Exam;
use App\Models\ExamAssignment;
use App\Models\User;
use App\Support\Push\PushMessage;
use App\Support\Structure\DepartmentReach;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Назначает экзамен людям — списком целиком, как доступ к курсу и план обучения.
 *
 * Выбирают людей трояко — поимённо, отделом (вместе с вложенными, см.
 * DepartmentReach) и группой, — но хранится по строке на человека: «отдел» —
 * способ выбрать, а не субъект сдачи. Назначение отделу разворачивается в
 * момент сохранения: пришедший в отдел завтра экзамена не получит, и это
 * намеренно — обязанность раздали этим людям, а не должностям; новичка
 * назначают следующим сохранением.
 *
 * Присланное — полный список: убранный из него человек освобождается от
 * обязанности, а его сданные работы остаются в статистике. Срок один на всех
 * присланных — «отделу до пятнадцатого» и есть то, как назначают; личные сроки
 * получаются двумя сохранениями с разными списками.
 */
final readonly class AssignExam
{
    public function __construct(private DepartmentReach $reach) {}

    /**
     * @param  array{users?: list<int>, departments?: list<int>, groups?: list<int>, due_at?: ?string}  $attributes
     * @return Collection<int, ExamAssignment>
     */
    public function handle(Exam $exam, array $attributes, User $actor): Collection
    {
        $people = $this->resolve($attributes);
        $dueAt = $attributes['due_at'] ?? null;

        $added = [];

        DB::transaction(function () use ($exam, $people, $dueAt, $actor, &$added): void {
            $exam->assignments()->whereNotIn('user_id', $people)->delete();

            foreach ($people as $personId) {
                $assignment = ExamAssignment::firstOrNew([
                    'exam_id' => $exam->getKey(),
                    'user_id' => $personId,
                ]);

                if (! $assignment->exists) {
                    $assignment->assigned_by = $actor->getKey();
                    $added[] = $personId;
                }

                // Срок правится и у старых строк: пересохранили с новой датой —
                // значит, передвинули её всем. Окно начатой попытки не трогаем:
                // человек, сидящий над работой прямо сейчас, здесь ни при чём.
                $assignment->due_at = $dueAt;
                $assignment->save();
            }
        });

        $this->notify($exam, array_values(array_diff($added, [$actor->getKey()])), $dueAt);

        return $exam->assignments()->with('user')->get();
    }

    /**
     * Кого назвали — в номера людей. Уволенные отсеиваются: обязанность сдать
     * не переживает увольнения, а отдел и группу берут всегда целиком и живыми.
     *
     * @param  array{users?: list<int>, departments?: list<int>, groups?: list<int>}  $attributes
     * @return list<int>
     */
    private function resolve(array $attributes): array
    {
        $named = array_map(intval(...), $attributes['users'] ?? []);
        $branch = $this->reach->branch(array_map(intval(...), $attributes['departments'] ?? []));
        $groups = array_map(intval(...), $attributes['groups'] ?? []);

        // Пустой выбор — пустой список, а не «все»: экзамен без единого
        // адресата не назначен никому, как и новость.
        return User::query()
            ->whereNull('dismissed_at')
            ->where(fn (Builder $query): Builder => $query
                ->whereIn('users.id', $named)
                ->orWhereHas('departments', fn (Builder $inner) => $inner->whereIn('departments.id', $branch))
                ->orWhereHas('groups', fn (Builder $inner) => $inner->whereIn('groups.id', $groups)))
            ->pluck('users.id')
            ->map(intval(...))
            ->all();
    }

    /**
     * Говорит новым назначенным, что их ждёт экзамен.
     *
     * Только новым: передвинутый срок у всех остальных — правка, о которой
     * звонить каждому телефоном незачем, у них экзамен и так в списке.
     *
     * @param  list<int>  $added
     */
    private function notify(Exam $exam, array $added, ?string $dueAt): void
    {
        if ($added === []) {
            return;
        }

        $due = $dueAt === null ? null : Date::parse($dueAt)->translatedFormat('j F');

        SendPush::dispatch($added, new PushMessage(
            title: 'Назначен экзамен',
            body: PushMessage::shorten($exam->title.($due === null ? '' : ' — сдать до '.$due)),
            url: '/exams/'.$exam->slug,
            tag: 'exam-'.$exam->getKey(),
        ));
    }
}
