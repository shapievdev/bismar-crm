<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Enums\MaterialKind;
use App\Models\Concerns\LenientlySearchable;
use App\Observers\RegulationObserver;
use App\Support\Lms\RegulationAccess;
use Database\Factories\RegulationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Правило, по которому работают.
 *
 * Сам себе урок: ни модулей, ни частей — статья, файлы и отметка «ознакомлен».
 * Состояние и приватность берут те же перечисления, что курс: читаются они
 * одинаково, и вторая пара названий для того же самого разошлась бы с первой на
 * первой же правке.
 */
#[ObservedBy(RegulationObserver::class)]
#[Fillable([
    'author_id', 'category_id', 'title', 'slug', 'summary',
    'content_json', 'status', 'visibility', 'published_at', 'keywords', 'kind',
])]
class Regulation extends Model
{
    /** @use HasFactory<RegulationFactory> */
    use HasFactory, LenientlySearchable, SoftDeletes;

    /**
     * Вид проставлен ещё до записи в базу.
     *
     * Умолчания колонки мало: Eloquent не перечитывает строку после вставки, и
     * у только что созданного материала вид остался бы пустым — а по нему
     * собирается адрес страницы (см. path()).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'kind' => MaterialKind::Document->value,
    ];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'content_json' => 'array',
            'keywords' => 'array',
            'kind' => MaterialKind::class,
            'status' => CourseStatus::class,
            'visibility' => CourseVisibility::class,
            'published_at' => 'datetime',
        ];
    }

    public function isPrivate(): bool
    {
        return $this->visibility->isPrivate();
    }

    /**
     * Адрес страницы — по виду: документ и справочник живут в разных разделах.
     *
     * Собирается здесь, а не по месту: ссылку на материал строит и консультант
     * в источниках ответа, и новость, и аттестация, и разойтись им нельзя.
     */
    public function path(): string
    {
        return '/lms/'.$this->kind->section().'/'.$this->slug;
    }

    public function isPublished(): bool
    {
        return $this->status->isOpenToLearners();
    }

    /**
     * Кто выбросил материал в корзину. Null у живого — и у того, кого вернули.
     *
     * @return BelongsTo<User, $this>
     */
    public function remover(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /**
     * Проверка при документе.
     *
     * Есть — значит ознакомление засчитывается сдачей, а не нажатием кнопки
     * (решение пользователя 2026-09-01). Устройство теста то же, что у урока:
     * одни вопросы, одни попытки, один разбор.
     *
     * @return MorphOne<Quiz, $this>
     */
    public function quiz(): MorphOne
    {
        return $this->morphOne(Quiz::class, 'quizzable');
    }

    /**
     * @return BelongsTo<RegulationCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(RegulationCategory::class, 'category_id');
    }

    /**
     * Кого пустили в закрытый регламент. Автора здесь нет: его доступ следует
     * из авторства, и строка о нём означала бы, что доступ можно снять.
     *
     * @return BelongsToMany<User, $this>
     */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'regulation_members')->withTimestamps();
    }

    /**
     * Группы, которым открыт закрытый материал (2026-09-12).
     *
     * То же рассуждение, что и у курса (см. Course::memberGroups): списки
     * складываются, состав группы читается живьём. Само правило — в
     * RegulationAccess.
     *
     * @return BelongsToMany<Group, $this>
     */
    public function memberGroups(): BelongsToMany
    {
        return $this->belongsToMany(Group::class, 'regulation_member_groups')
            ->withPivot('granted_by_id')
            ->withTimestamps();
    }

    /**
     * Отделы, которым открыт закрытый материал (2026-09-12).
     *
     * То же рассуждение, что и у курса (см. Course::memberDepartments):
     * подотделы разворачиваются при проверке, а не переписываются в список.
     *
     * @return BelongsToMany<Department, $this>
     */
    public function memberDepartments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'regulation_member_departments')
            ->withPivot('granted_by_id')
            ->withTimestamps();
    }

    /**
     * Кому писать, если написанного не хватило.
     *
     * @return BelongsToMany<User, $this>
     */
    public function experts(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'regulation_experts')->withTimestamps();
    }

    /**
     * Соседние документы — «рядом по теме».
     *
     * Связь взаимная и хранится двумя строками, поэтому здесь достаточно
     * обычной сводной таблицы: соседи документа — это всё, на что он указывает.
     * Заводит и снимает пару строк LinkRegulations, руками отношение не трогают.
     *
     * Порядок — тот, в котором связывали: список читают сверху вниз, и
     * переставлять его по алфавиту значит перемешать заведомо осмысленный
     * порядок автора.
     *
     * @return BelongsToMany<Regulation, $this>
     */
    public function related(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'regulation_links', 'regulation_id', 'related_id')
            ->withTimestamps()
            ->orderBy('regulation_links.created_at')
            ->orderBy('regulation_links.id');
    }

    /**
     * «Частые вопросы» — материалы, приколотые к этой странице.
     *
     * Не то же, что соседи: те отвечают «что ещё читать про то же самое», а эти
     * — «с чем сюда приходят чаще всего». Связь односторонняя и порядок в ней
     * задан руками: список читают сверху вниз, и первым стоит самое частое.
     * Раздел не важен — к правилу прикалывают и справочник.
     *
     * @return BelongsToMany<Regulation, $this>
     */
    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'regulation_questions', 'regulation_id', 'linked_id')
            ->withPivot('position')
            ->withTimestamps()
            ->orderBy('regulation_questions.position')
            ->orderBy('regulation_questions.id');
    }

    /**
     * Изложение документа для машины — то же, что у урока.
     *
     * Консультант читает не статью, а её нарезку: так найденное указывает не на
     * документ целиком, а на абзац в нём.
     *
     * @return HasMany<LessonTranscript, $this>
     */
    public function transcripts(): HasMany
    {
        return $this->hasMany(LessonTranscript::class);
    }

    /**
     * @return HasMany<TranscriptSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(TranscriptSegment::class);
    }

    /**
     * Файлы общей версии.
     *
     * Версии сюда не попадают намеренно (2026-09-12): у каждой свой бланк, и
     * общий список означал бы, что розница видит расчёт офиса просто потому,
     * что файл лежит при том же документе. Всё вместе — только для уборки,
     * см. allAttachments().
     *
     * @return HasMany<RegulationAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(RegulationAttachment::class)->whereNull('version_id')->orderBy('id');
    }

    /**
     * Все файлы документа, включая версии, — для уборки за удалённым.
     *
     * Показывать их вместе нельзя, а стирать с диска нужно все: строки уйдут
     * каскадом, а файлы остались бы лежать в хранилище навсегда.
     *
     * @return HasMany<RegulationAttachment, $this>
     */
    public function allAttachments(): HasMany
    {
        return $this->hasMany(RegulationAttachment::class)->orderBy('id');
    }

    /**
     * Версии — то же правило, написанное для своих людей (2026-09-12).
     *
     * Общей версии среди них нет: она — сам документ. Кому какая достаётся,
     * решает App\Support\Lms\MaterialVersions.
     *
     * @return HasMany<RegulationVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(RegulationVersion::class)->ordered();
    }

    /**
     * @return HasMany<RegulationAcknowledgement, $this>
     */
    public function acknowledgements(): HasMany
    {
        return $this->hasMany(RegulationAcknowledgement::class);
    }

    public function isAcknowledgedBy(User $user): bool
    {
        return $this->acknowledgements()->where('user_id', $user->getKey())->exists();
    }

    /**
     * Один вид материала: документы отдельно, справочники отдельно.
     *
     * @param  Builder<Regulation>  $query
     */
    public function scopeOfKind(Builder $query, MaterialKind $kind): void
    {
        $query->where('kind', $kind);
    }

    /**
     * Опубликованное — то, что читают, а не пишут.
     *
     * @param  Builder<Regulation>  $query
     */
    public function scopePublished(Builder $query): void
    {
        $query->where('status', CourseStatus::Published);
    }

    /**
     * Оставляет только то, что этому человеку открыто. Про состояние ничего не
     * говорит: черновик виден редактору, и складывать это в одно условие
     * значит однажды показать черновик всем.
     *
     * @param  Builder<Regulation>  $query
     */
    public function scopeVisibleTo(Builder $query, User $user): void
    {
        RegulationAccess::of($user)->applyTo($query);
    }

    /**
     * Всё, что этот человек вправе открыть: и закрытость, и раздел, и
     * состояние разом.
     *
     * Три условия ходят вместе всюду, где материалы двух разделов лежат
     * вперемешку и отобрать их маршрутом нельзя, — соседи «рядом по теме»,
     * частые вопросы, приложенное к уроку. Раньше они и были расписаны по
     * местам тройкой строк, одинаковой до запятой; стоило правам разделиться,
     * и каждое такое место пришлось бы править отдельно, а пропущенное
     * показывало бы справочник тому, кому справочники закрыты.
     *
     * Читателю видно опубликованное в разделах, открытых ему на чтение;
     * редактору — вдобавок черновики тех разделов, которые он правит. Ни одного
     * открытого раздела — не видно ничего, и это честный ответ, а не пустой
     * фильтр.
     *
     * @param  Builder<Regulation>  $query
     */
    public function scopeReadableBy(Builder $query, User $reader): void
    {
        $readable = MaterialKind::valuesOf(MaterialKind::viewableBy($reader));
        $editable = MaterialKind::valuesOf(MaterialKind::editableBy($reader));

        $query->visibleTo($reader)->where(function (Builder $query) use ($readable, $editable): void {
            $query
                ->where(fn (Builder $published) => $published->published()->whereIn('kind', $readable))
                ->orWhereIn('kind', $editable);
        });
    }

    /**
     * Поиск по названию и краткому описанию.
     *
     * Сверяется с ICU: базы собраны с C-сортировкой, где lower() и ILIKE
     * складывают только латиницу, — иначе «касса» не нашла бы «Кассовую».
     *
     * @param  Builder<Regulation>  $query
     */
    public function scopeMatching(Builder $query, ?string $term): void
    {
        $term = trim((string) $term);

        if ($term === '') {
            return;
        }

        $pattern = '%'.$term.'%';

        $query->where(function (Builder $query) use ($pattern): void {
            $query->whereRaw('title COLLATE "und-x-icu" ILIKE ?', [$pattern])
                ->orWhereRaw('summary COLLATE "und-x-icu" ILIKE ?', [$pattern])
                // Ключевые слова — целиком, как хранятся: их пишут ради тех,
                // кто ищет не теми словами, и запасной поиск видит их тоже.
                ->orWhereRaw('keywords::text COLLATE "und-x-icu" ILIKE ?', [$pattern]);
        });
    }

    /**
     * Что уходит в поисковый индекс.
     *
     * Только то, по чему ищут: доступ и состояние остаются базе, и в индексе им
     * делать нечего — см. CatalogSearch. Статья не идёт вовсе: по её тексту
     * ищет консультант, у которого своя нарезка с точным местом, а каталогу
     * довольно названия, описания и ключевых слов.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'summary' => $this->summary,
            'keywords' => $this->keywords ?? [],
        ];
    }
}
