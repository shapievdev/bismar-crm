<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SaveRegulation;
use App\Enums\MaterialKind;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveRegulationRequest;
use App\Http\Resources\Lms\RegulationResource;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationCategory;
use App\Models\User;
use App\Support\Lms\CatalogSearch;
use App\Support\Lms\MaterialVersions;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Регламенты — правила, по которым работают.
 *
 * Каталог устроен как курсовой: закрытое видно только допущенным, черновики —
 * только тем, кто вправе править. Разница в том, что внутри нет структуры:
 * регламент сам себе урок, и «пройден» у него означает «прочитан».
 */
final class RegulationController extends Controller
{
    public function __construct(
        private readonly CatalogSearch $search,
        private readonly MaterialVersions $versions,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $reader */
        $reader = $request->user();

        $regulations = Regulation::query()
            ->with('author', 'category')
            // Одним подзапросом, а не вопросом на каждую строку: в каталоге их
            // пятнадцать, и пятнадцать запросов ради галочки — это дорого.
            ->withExists(['acknowledgements as is_acknowledged' => fn (Builder $query) => $query
                ->where('user_id', $reader->getKey())])
            // Закрытое — только своё: чужое закрытое правило не должно попадать
            // в каталог даже названием.
            ->visibleTo($reader)
            // Каталог всегда внутри одного раздела: правила и справки не
            // перемешиваются ни в списке, ни в поиске по нему.
            ->ofKind(MaterialKind::of($request))
            // Слова ищет Meilisearch, доступ по-прежнему решает база: поисковик
            // возвращает только номера, и условия выше остаются как были.
            ->tap(fn (Builder $query) => $this->search->apply($query, $request->query('search')))
            ->when(
                $request->filled('category'),
                // Выбранная категория включает всё, что под ней, иначе
                // родительская выглядела бы пустой.
                fn (Builder $query) => $query->whereIn('category_id', $this->branchIdsFor((string) $request->query('category'))),
            )
            ->when(
                // Черновики от читателей скрыты. Право спрашивается у раздела,
                // в котором мы находимся: правящий справочники не редактор
                // документов и черновиков правил не видит.
                $reader->cannot(MaterialKind::of($request)->updatePermission()->value),
                fn (Builder $query) => $query->published(),
                fn (Builder $query) => $query->when(
                    $request->filled('status'),
                    fn (Builder $query) => $query->where('status', $request->query('status')),
                ),
            )
            // NULLS LAST: у черновика даты публикации нет, и Postgres на
            // убывающей сортировке поставил бы его во главе каталога.
            ->orderByRaw('published_at DESC NULLS LAST')
            ->orderByDesc('updated_at')
            ->paginate(15)
            ->withQueryString();

        return RegulationResource::collection($regulations);
    }

    public function show(Request $request, Regulation $regulation): RegulationResource
    {
        Gate::authorize('view', $regulation);

        /** @var User $reader */
        $reader = $request->user();

        // Проверяющий едет вместе с проверкой — как и у урока: «ждёт проверки»
        // без имени звучит как «ждёт неизвестно чего».
        $regulation->load(
            'author',
            'category',
            'attachments',
            'experts',
            'quiz.questions.options',
            'quiz.examiner:id,last_name,first_name,middle_name',
        );

        // Соседи — «рядом по теме». Отбираются под того, кто спрашивает: чужой
        // закрытый документ, черновик и целый раздел, который этому человеку
        // не открыт, из блока выпадают — иначе ссылка вела бы читателя в отказ,
        // а название закрытого правила выдавало бы его не хуже страницы.
        $regulation->load(['related' => fn (BelongsToMany $query) => $query
            ->with('category')
            ->readableBy($reader)]);

        // «Частые вопросы» — тем же отбором и по той же причине. Раздел здесь
        // не при чём: к правилу прикалывают и справочник, и строка ведёт туда,
        // где лежит ответ, — потому и отбор обязан спрашивать права обоих
        // разделов, а не того, в котором мы стоим.
        $regulation->load(['questions' => fn (BelongsToMany $query) => $query
            ->with('category')
            ->readableBy($reader)]);

        if ($reader->can('update', $regulation)) {
            $regulation->loadCount('acknowledgements', 'members');
        }

        $regulation->setAttribute('sends_content', true);

        // Версии и та из них, что открывается этому человеку первой.
        $this->attachVersions($regulation, $reader, $request->query('version'));

        return RegulationResource::make($this->attachOwnState($regulation, $reader));
    }

    public function store(SaveRegulationRequest $request, SaveRegulation $saveRegulation): JsonResponse
    {
        // Вид берётся из раздела, а не из присланного: заводя справочник,
        // клиент не выбирает, чем он окажется, — он уже в разделе справочников.
        // Им же спрашивается и право: заводить документы и заводить справочники
        // — разные решения.
        $kind = MaterialKind::of($request);

        Gate::authorize('create', [Regulation::class, $kind]);

        /** @var User $author */
        $author = $request->user();

        $regulation = $saveRegulation->handle(
            [...$request->toAttributes(), 'kind' => $kind],
            $author,
        );

        return RegulationResource::make($this->forEditor($regulation, $author))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(
        SaveRegulationRequest $request,
        Regulation $regulation,
        SaveRegulation $saveRegulation,
    ): RegulationResource {
        Gate::authorize('update', $regulation);

        /** @var User $author */
        $author = $request->user();

        return RegulationResource::make(
            $this->forEditor($saveRegulation->handle($request->toAttributes(), $author, $regulation), $author),
        );
    }

    public function destroy(Request $request, Regulation $regulation): Response
    {
        Gate::authorize('delete', $regulation);

        // Мягко: за регламентом стоят отметки об ознакомлении, и случайное
        // удаление не должно уносить их с собой. Кто выбросил — видно в
        // корзине, см. TrashController.
        $regulation->forceFill(['deleted_by' => $request->user()?->getKey()])->save();
        $regulation->delete();

        return response()->noContent();
    }

    private function forEditor(Regulation $regulation, User $reader): Regulation
    {
        $regulation->load('author', 'category', 'attachments', 'experts');
        $regulation->loadCount('acknowledgements', 'members');
        $regulation->setAttribute('sends_content', true);

        return $this->attachOwnState($regulation, $reader);
    }

    /**
     * Прочитал ли этот человек это правило.
     */
    private function attachOwnState(Regulation $regulation, User $reader): Regulation
    {
        $acknowledgement = $regulation->acknowledgements()->where('user_id', $reader->getKey())->first();

        return $regulation
            ->setAttribute('own_attempts', $this->ownAttempts($regulation->quiz, $reader))
            ->setAttribute('is_acknowledged', $acknowledgement !== null)
            ->setAttribute('acknowledged_at', $acknowledgement?->acknowledged_at?->toIso8601String())

            // По какой версии отметились. Пометка, а не вторая отметка: версия
            // у человека одна, и ознакомиться с документом значит прочитать
            // свою версию, а не все.
            ->setAttribute('acknowledged_version_id', $acknowledgement?->version_id);
    }

    /**
     * Свои прошлые попытки — чтобы экран показал историю и разбор.
     *
     * Десяти довольно: дальше это уже не история, а архив. Один способ на
     * проверку документа и на проверку версии: устройство у них общее, и
     * второй такой же разбор разошёлся бы с первым на первой же правке.
     *
     * @return list<array<string, mixed>>
     */
    private function ownAttempts(?Quiz $quiz, User $reader): array
    {
        if ($quiz === null) {
            return [];
        }

        return $quiz->attempts()
            ->where('user_id', $reader->getKey())
            ->with('reviewer:id,last_name,first_name,middle_name')
            ->latest('completed_at')
            ->limit(10)
            ->get()
            ->map(fn (QuizAttempt $attempt): array => [
                'id' => $attempt->getKey(),
                'score' => $attempt->score,
                'passed' => $attempt->passed,
                'completed_at' => $attempt->completed_at?->toIso8601String(),

                // Состояние аттестации — то же, что у урока, см. LearningController.
                'review_status' => $attempt->review_status->value,
                'review_status_label' => $attempt->review_status->label(),
                'review_comment' => $attempt->review_comment,
                'reviewed_at' => $attempt->reviewed_at?->toIso8601String(),
                'reviewed_by' => $attempt->reviewer?->name,
            ])->all();
    }

    /**
     * Версии документа и та из них, что открывается этому человеку первой.
     *
     * Переключатель приходит названиями, а тело — только у открытой: пять
     * версий весили бы пятью статьями, а читают за раз одну. Какую именно
     * открыть, можно попросить прямо (`?version=`) — так работает сам
     * переключатель и так же приходят по ссылке из ответа консультанта.
     */
    private function attachVersions(Regulation $regulation, User $reader, ?string $asked): void
    {
        $available = $this->versions->visibleTo($regulation, $reader);
        $mine = $this->versions->mineAmong($available, $reader);

        // Для кого версия написана — только тому, кто документ ведёт. Читателю
        // список групп ни о чём не говорит: ему важно, какая версия его.
        $forEditor = $reader->can('update', $regulation);

        foreach ($available as $version) {
            $version->setAttribute('is_mine', $mine?->getKey() === $version->getKey());

            if (! $forEditor) {
                $version->unsetRelation('groups');
            }
        }

        $regulation->setAttribute('available_versions', $available);

        // Общую просят пустой строкой: «открой мне не мою версию, а исходную».
        // Отличить это от «не просили ничего» иначе нечем.
        // Строка берётся из того же списка, а не спрашивается заново: на ней
        // уже стоит признак «моя», и двойник ушёл бы на экран без него.
        $shown = $asked === null ? $mine : $available->firstWhere('id', (int) $asked);

        if ($shown === null) {
            return;
        }

        $shown->load(['attachments', 'quiz.questions.options', 'quiz.examiner:id,last_name,first_name,middle_name']);
        $shown->setAttribute('sends_content', true);
        $shown->setAttribute('own_attempts', $this->ownAttempts($shown->quiz, $reader));

        $regulation->setAttribute('shown_version', $shown);
    }

    /**
     * Категория и всё, что под ней.
     *
     * @return list<int>
     */
    private function branchIdsFor(string $slug): array
    {
        $category = RegulationCategory::query()->where('slug', $slug)->with('children')->first();

        return $category === null ? [] : $category->branchIds();
    }
}
