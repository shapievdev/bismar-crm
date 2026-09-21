<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\Permission;
use Database\Factories\SurveyFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Опрос при материале: что человек думает, а не что он понял.
 *
 * Стоит рядом с тестом и устроен нарочно иначе. У теста есть ключ, планка и
 * попытки; у опроса нет ни одного из трёх: правильного ответа не существует,
 * зачитывать нечего, а проходят его один раз — второго захода не бывает
 * (решение пользователя 2026-09-21).
 *
 * Один на всех четырёх владельцев — урок, документ, справочник и новость, — в
 * отличие от тестов, которые живут двумя стопками таблиц. Устройство опроса от
 * владельца не зависит вовсе: разное у них лишь то, что держит обязательный
 * опрос, и это знает не он, а MaterialDues.
 */
#[Fillable(['surveyable_type', 'surveyable_id', 'title', 'description', 'is_required', 'is_anonymous', 'closes_at', 'thanks'])]
class Survey extends Model
{
    /** @use HasFactory<SurveyFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'is_anonymous' => 'boolean',
            'closes_at' => 'datetime',
        ];
    }

    /**
     * Кому принадлежит опрос: уроку, документу, версии документа или новости.
     *
     * @return MorphTo<Model, $this>
     */
    public function surveyable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<SurveyQuestion, $this>
     */
    public function questions(): HasMany
    {
        return $this->hasMany(SurveyQuestion::class)->orderBy('position')->orderBy('id');
    }

    /**
     * Отправленное людьми. У анонимного опроса строки не знают, кто их написал.
     *
     * @return HasMany<SurveyResponse, $this>
     */
    public function responses(): HasMany
    {
        return $this->hasMany(SurveyResponse::class);
    }

    /**
     * Кто прошёл. Ведётся всегда, даже когда сами ответы анонимны: обязательный
     * опрос держит зачёт материала, и закрыть его иначе нечем.
     *
     * @return HasMany<SurveyCompletion, $this>
     */
    public function completions(): HasMany
    {
        return $this->hasMany(SurveyCompletion::class);
    }

    /** Принимает ли опрос ответы: срок мог выйти. */
    public function isOpen(): bool
    {
        return $this->closes_at === null || $this->closes_at->isFuture();
    }

    /**
     * Проходил ли этот человек опрос.
     *
     * Спрашивается у отметок, а не у ответов: у анонимного опроса ответы не
     * знают человека, а отметка знает — в этом её смысл.
     */
    public function isAnsweredBy(User $user): bool
    {
        return $this->completions()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Вправе ли этот человек править материал, при котором стоит опрос, — а
     * значит, заводить сам опрос и читать сводку ответов.
     *
     * Спрашивается у владельца, а не у прав на курсы: опрос при справочнике
     * правит тот, кто ведёт справочники, а при новости — тот, кто ведёт новости.
     * Устроено так же, как у теста, — см. Quiz::isEditableBy.
     */
    public function isEditableBy(User $user): bool
    {
        $owner = $this->surveyable;

        return match (true) {
            $owner instanceof Regulation => $user->can($owner->kind->updatePermission()->value),
            // Опрос при версии правится тем же правом, что и сам документ:
            // версия — его часть, а не отдельный материал.
            $owner instanceof RegulationVersion => $owner->loadMissing('regulation')->regulation !== null
                && $user->can($owner->regulation->kind->updatePermission()->value),
            $owner instanceof Lesson => $user->can(Permission::UpdateCourses->value),
            $owner instanceof News => $user->can(Permission::ManageNews->value),
            // Владельца не нашли — материал удалён вместе с ним, и править
            // нечего.
            default => false,
        };
    }
}
