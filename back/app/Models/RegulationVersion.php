<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\RegulationVersionObserver;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/**
 * Версия документа или справочника — то же правило, написанное для своих людей.
 *
 * «Как считается зарплата» у розницы и у офиса — разный текст, разный бланк и
 * разная проверка, но документ один: заголовок, категория, ответственные,
 * соседи и допуск общие на всех. Версия заменяет только тело — статью, файлы и
 * проверку.
 *
 * **Общей версии здесь нет**: она — сам документ. Строка заводится только на
 * версию сверх неё, поэтому материал без версий ни одним запросом об этой
 * таблице не спрашивает, а читатель, чьи группы ни с чем не совпали, читает
 * документ так же, как читал до всякого разделения.
 *
 * Кому какая версия достаётся и кто какую видит, решает не модель, а
 * App\Support\Lms\MaterialVersions: вопрос этот задают с двух концов — «моя
 * версия этого документа» и «какие версии показать в переключателе», — и
 * держать ответы врозь значило бы однажды расширить один и забыть второй.
 */
#[ObservedBy(RegulationVersionObserver::class)]
#[Fillable(['regulation_id', 'name', 'is_private', 'position', 'content_json'])]
class RegulationVersion extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private' => 'boolean',
            'position' => 'integer',
            'content_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Regulation, $this>
     */
    public function regulation(): BelongsTo
    {
        return $this->belongsTo(Regulation::class);
    }

    /**
     * Кому эта версия предназначена.
     *
     * У открытой версии группы решают лишь то, кому она откроется первой;
     * у закрытой — ещё и то, кому она вообще видна.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'regulation_version_groups', 'version_id')
            ->withTimestamps();
    }

    /**
     * Отделы, которым эта версия предназначена (2026-09-12).
     *
     * Складываются с группами: «розница плюс отдел доставки» — обычная просьба.
     * Отдел охватывает и свои подотделы, см. MaterialVersions.
     *
     * @return BelongsToMany<Department, $this>
     */
    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'regulation_version_departments', 'version_id')
            ->withTimestamps();
    }

    /**
     * Файлы версии — свой бланк расчёта у каждой.
     *
     * @return HasMany<RegulationAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(RegulationAttachment::class, 'version_id')->orderBy('id');
    }

    /**
     * Проверка при версии.
     *
     * Связь та же полиморфная, что у урока и у документа: устройство теста от
     * владельца не зависит, и третий владелец добавляется именем в карте
     * морфов, а не третьей таблицей попыток.
     *
     * @return MorphOne<Quiz, $this>
     */
    public function quiz(): MorphOne
    {
        return $this->morphOne(Quiz::class, 'quizzable');
    }

    /**
     * Нарезка текста версии — то, что находит консультант.
     *
     * @return HasMany<TranscriptSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class, 'version_id');
    }

    /**
     * @return HasMany<LessonTranscript, $this>
     */
    public function transcripts(): HasMany
    {
        return $this->hasMany(LessonTranscript::class, 'version_id');
    }

    /**
     * Порядок, который задал автор: он же решает спор, когда человек попал в
     * две версии сразу.
     *
     * @param  Builder<$this>  $query
     */
    public function scopeOrdered(Builder $query): void
    {
        $query->orderBy('position')->orderBy('id');
    }
}
