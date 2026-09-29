<?php

declare(strict_types=1);

namespace App\Models;

use App\Observers\MaterialVersionObserver;
use App\Support\Lms\StoredFiles;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Storage;

/**
 * Версия материала — то же самое, написанное для своих людей.
 *
 * «Как считается зарплата» у розницы и у офиса, вводный урок для магазина и для
 * офиса: разный текст, разный бланк и разная проверка, но материал один —
 * заголовок, место в дереве, ответственные и допуск общие на всех. Версия
 * заменяет только тело.
 *
 * **Общей версии здесь нет**: она — сам материал. Строка заводится только на
 * версию сверх неё, поэтому материал без версий ни одним запросом об этой
 * таблице не спрашивает, а читатель, чьи группы ни с чем не совпали, читает
 * материал так же, как читал до всякого разделения.
 *
 * Владелец полиморфный: документ, справочник (2026-09-12) и урок курса
 * (2026-09-25). Второй такой же таблицы под уроки заводить не стали — вместе с
 * ней пришлось бы завести вторые группы, вторые отделы и второй свод правил
 * «кому какая версия видна», который однажды разошёлся бы с первым.
 *
 * Кому какая версия достаётся и кто какую видит, решает не модель, а
 * App\Support\Lms\MaterialVersions: вопрос этот задают с трёх концов, и держать
 * ответы врозь значило бы однажды расширить один и забыть остальные.
 */
#[ObservedBy(MaterialVersionObserver::class)]
#[Fillable(['versionable_type', 'versionable_id', 'name', 'is_private', 'position', 'content_json', 'video_url', 'video_path', 'video_disk', 'video_name', 'video_size'])]
class MaterialVersion extends Model
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
     * Материал, у которого эта версия: документ, справочник или урок.
     *
     * @return MorphTo<Model, $this>
     */
    public function versionable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Материал этой версии — документ, справочник или урок.
     *
     * Отдельным методом, а не обращением к связи: спрашивают о нём там, где
     * важно не подгрузить его вторым запросом, если он уже прочитан.
     */
    public function owner(): ?Model
    {
        return $this->loadMissing('versionable')->versionable;
    }

    /** Документ или справочник, если версия его. Null у версии урока. */
    public function regulation(): ?Regulation
    {
        $owner = $this->owner();

        return $owner instanceof Regulation ? $owner : null;
    }

    /** Урок, если версия его. Null у версии документа. */
    public function lesson(): ?Lesson
    {
        $owner = $this->owner();

        return $owner instanceof Lesson ? $owner : null;
    }

    /**
     * Этого ли материала версия.
     *
     * Спрашивается на каждом маршруте, где версия стоит рядом со своим
     * материалом: адресом своего документа чужую версию открыть нельзя, и это
     * тот же случай, что её отсутствие.
     */
    public function belongsToMaterial(Model $owner): bool
    {
        return $this->versionable_type === $owner->getMorphClass()
            && (int) $this->versionable_id === (int) $owner->getKey();
    }

    /** Своя ли это версия урока — от неё зависит, чьи файлы у версии. */
    public function isOfLesson(): bool
    {
        return $this->versionable_type === (new Lesson)->getMorphClass();
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
        return $this->belongsToMany(Group::class, 'material_version_groups', 'version_id')
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
        return $this->belongsToMany(Department::class, 'material_version_departments', 'version_id')
            ->withTimestamps();
    }

    /**
     * Файлы версии документа — свой бланк расчёта у каждой.
     *
     * @return HasMany<RegulationAttachment, $this>
     */
    public function regulationAttachments(): HasMany
    {
        return $this->hasMany(RegulationAttachment::class, 'version_id')->orderBy('id');
    }

    /**
     * Файлы версии урока. Таблица у них своя — у вложений урока и документа
     * разные поля и разные маршруты, — а связь одна на обе: чьи файлы
     * показывать, решает вид владельца, см. attachments().
     *
     * @return HasMany<LessonAttachment, $this>
     */
    public function lessonAttachments(): HasMany
    {
        return $this->hasMany(LessonAttachment::class, 'version_id')->orderBy('id');
    }

    /**
     * Файлы этой версии — те, что подходят её материалу.
     *
     * Связь выбирается по владельцу: у версии документа их держит одна таблица,
     * у версии урока другая, а спрашивающему (ресурс, экран, уборка за
     * удалённым) разница не нужна.
     *
     * @return HasMany<RegulationAttachment, $this>|HasMany<LessonAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->isOfLesson()
            ? $this->lessonAttachments()
            : $this->regulationAttachments();
    }

    /**
     * Уже прочитанные файлы версии — без второго запроса, если их загрузили
     * вместе с ней.
     *
     * @return Collection<int, RegulationAttachment|LessonAttachment>
     */
    public function files(): Collection
    {
        $relation = $this->isOfLesson() ? 'lessonAttachments' : 'regulationAttachments';

        return $this->relationLoaded($relation)
            ? $this->getRelation($relation)
            : $this->{$relation}()->get();
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
     * Опрос при версии — свой, как и проверка: у версии свой текст, и спросить
     * о нём тоже стоит своё.
     *
     * @return MorphOne<Survey, $this>
     */
    public function survey(): MorphOne
    {
        return $this->morphOne(Survey::class, 'surveyable');
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

    /** Есть ли у версии своя запись — загруженная или по ссылке. */
    public function hasVideo(): bool
    {
        return $this->video_path !== null || $this->video_url !== null;
    }

    /**
     * Убирает запись версии из хранилища. Та же уборка, что у урока: строка
     * уйдёт, а файл остался бы лежать навсегда.
     */
    public function deleteVideoFromStorage(): void
    {
        if ($this->video_disk !== null) {
            StoredFiles::discard($this->video_disk, $this->video_path);
        }
    }

    /**
     * Недолгий подписанный адрес загруженной записи — тот же, что у урока, и
     * по той же причине: файл лежит в закрытом хранилище, и постоянной ссылки
     * на него не бывает. Null у версии со ссылкой на YouTube и без записи
     * вовсе.
     */
    public function videoUrl(): ?string
    {
        if ($this->video_path === null || $this->video_disk === null) {
            return null;
        }

        return Storage::disk($this->video_disk)->temporaryUrl(
            $this->video_path,
            now()->addMinutes(config('lms.attachment_url_ttl_minutes')),
        );
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
