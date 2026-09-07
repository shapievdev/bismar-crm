<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\SaveRegulation;
use App\Enums\MaterialKind;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveRegulationRequest;
use App\Http\Resources\Lms\RegulationResource;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationCategory;
use App\Models\User;
use App\Support\Lms\CatalogSearch;
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
    public function __construct(private readonly CatalogSearch $search) {}

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
                // Черновики от читателей скрыты.
                $reader->cannot(Permission::UpdateCourses->value),
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
        // закрытый документ и неопубликованный черновик из блока выпадают,
        // иначе ссылка вела бы читателя в отказ, а название закрытого правила
        // выдавало бы его не хуже страницы.
        $regulation->load(['related' => fn (BelongsToMany $query) => $query
            ->with('category')
            ->visibleTo($reader)
            ->when(
                $reader->cannot(Permission::UpdateCourses->value),
                fn (Builder $query) => $query->published(),
            )]);

        // «Частые вопросы» — тем же отбором и по той же причине. Раздел здесь
        // не при чём: к правилу прикалывают и справочник, и строка ведёт туда,
        // где лежит ответ.
        $regulation->load(['questions' => fn (BelongsToMany $query) => $query
            ->with('category')
            ->visibleTo($reader)
            ->when(
                $reader->cannot(Permission::UpdateCourses->value),
                fn (Builder $query) => $query->published(),
            )]);

        if ($reader->can('update', $regulation)) {
            $regulation->loadCount('acknowledgements', 'members');
        }

        $regulation->setAttribute('sends_content', true);

        return RegulationResource::make($this->attachOwnState($regulation, $reader));
    }

    public function store(SaveRegulationRequest $request, SaveRegulation $saveRegulation): JsonResponse
    {
        Gate::authorize('create', Regulation::class);

        /** @var User $author */
        $author = $request->user();

        // Вид берётся из раздела, а не из присланного: заводя справочник,
        // клиент не выбирает, чем он окажется, — он уже в разделе справочников.
        $regulation = $saveRegulation->handle(
            [...$request->toAttributes(), 'kind' => MaterialKind::of($request)],
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

        // Свои прошлые попытки — чтобы экран показал историю и разбор. Десяти
        // довольно: дальше это уже не история, а архив.
        $attempts = $regulation->quiz === null ? [] : $regulation->quiz
            ->attempts()
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

        return $regulation
            ->setAttribute('own_attempts', $attempts)
            ->setAttribute('is_acknowledged', $acknowledgement !== null)
            ->setAttribute('acknowledged_at', $acknowledgement?->acknowledged_at?->toIso8601String());
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
