<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveVersionRequest;
use App\Http\Resources\Lms\RegulationVersionResource;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Support\Lms\BlockIdentifier;
use App\Support\Lms\MaterialVersions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Версии документа — то же правило, написанное для своих людей.
 *
 * Ведёт их тот, кто правит материал: версия — его часть, а не отдельный
 * документ, и отдельного права у неё нет. Читает — всякий, кому версия открыта:
 * закрытая только своим группам, открытая всем, кому открыт сам документ.
 *
 * Общей версии здесь нет ни одним маршрутом: она — сам документ, и читается
 * теми же адресами, что и до всякого разделения.
 */
final class RegulationVersionController extends Controller
{
    public function __construct(
        private readonly MaterialVersions $versions,
        private readonly BlockIdentifier $blocks,
    ) {}

    /**
     * Все версии — тому, кто документ ведёт.
     *
     * Без тел: панель показывает названия и круг групп, а статью правят на
     * своём экране.
     */
    public function index(Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('update', $regulation);

        return RegulationVersionResource::collection(
            $regulation->versions()->with('groups:id,name')->get(),
        );
    }

    /**
     * Одна версия целиком — читателю.
     *
     * Тело приходит только здесь: переключатель из пяти строк весил бы пятью
     * статьями, а читают за раз одну.
     */
    public function show(Request $request, Regulation $regulation, RegulationVersion $version): RegulationVersionResource
    {
        Gate::authorize('view', $regulation);

        /** @var User $reader */
        $reader = $request->user();

        $this->ensureBelongs($regulation, $version);

        // Закрытая чужая версия отвечает «не найдено», а не «нельзя»: её
        // название выдало бы её не хуже текста.
        abort_unless($this->versions->allows($version, $reader), HttpResponse::HTTP_NOT_FOUND);

        return RegulationVersionResource::make($this->withBody($regulation, $version, $reader));
    }

    public function store(SaveVersionRequest $request, Regulation $regulation): JsonResponse
    {
        Gate::authorize('update', $regulation);

        $version = DB::transaction(function () use ($request, $regulation): RegulationVersion {
            /** @var RegulationVersion $version */
            $version = $regulation->versions()->create([
                ...$request->toAttributes(),

                // В конец списка: порядок задаёт автор, и новая версия не
                // должна молча обгонять уже расставленные.
                'position' => (int) $regulation->versions()->max('position') + 1,
            ]);

            $version->groups()->sync($request->groups());

            return $version;
        });

        return RegulationVersionResource::make($version->load('groups:id,name'))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    public function update(
        SaveVersionRequest $request,
        Regulation $regulation,
        RegulationVersion $version,
    ): RegulationVersionResource {
        Gate::authorize('update', $regulation);

        $this->ensureBelongs($regulation, $version);

        $attributes = $request->toAttributes();

        // Имена блокам присваивает сервер — как в самом документе, и по той же
        // причине: по ним собирается нарезка для консультанта, а пришедшее от
        // клиента имя может оказаться выдуманным или чужим.
        if (array_key_exists('content_json', $attributes)) {
            $attributes['content_json'] = $this->blocks->assign($attributes['content_json']);
        }

        DB::transaction(function () use ($version, $attributes, $request): void {
            $version->fill($attributes)->save();
            $version->groups()->sync($request->groups());
        });

        return RegulationVersionResource::make($version->load('groups:id,name'));
    }

    /**
     * Порядок версий — им же решается спор.
     *
     * Человек, попавший в две версии сразу, получает первую по этому списку
     * (решение пользователя 2026-09-12), поэтому порядок здесь не украшение.
     * Присылается целиком, как и всякий список на этих экранах: «сохранить»
     * означает «пусть будет вот так».
     */
    public function reorder(Request $request, Regulation $regulation): AnonymousResourceCollection
    {
        Gate::authorize('update', $regulation);

        /** @var list<int|string> $order */
        $order = $request->input('versions', []);
        $own = $regulation->versions()->pluck('id')->map(intval(...))->all();

        DB::transaction(function () use ($order, $own): void {
            $position = 0;

            foreach ($order as $id) {
                // Чужие номера молча пропускаются: список порядка не место
                // добавлять версию в документ.
                if (in_array((int) $id, $own, strict: true)) {
                    RegulationVersion::query()->whereKey((int) $id)->update(['position' => ++$position]);
                }
            }
        });

        return RegulationVersionResource::collection(
            $regulation->versions()->with('groups:id,name')->get(),
        );
    }

    /**
     * Убрать версию.
     *
     * Насовсем и сразу: за версией не стоит ничего, что потребовало бы
     * корзины, — отметки об ознакомлении остаются при документе и лишь теряют
     * пометку о том, по какой версии их поставили.
     */
    public function destroy(Regulation $regulation, RegulationVersion $version): Response
    {
        Gate::authorize('update', $regulation);

        $this->ensureBelongs($regulation, $version);

        $version->delete();

        return response()->noContent();
    }

    /**
     * Статья, файлы и проверка версии — вместе со своими прошлыми попытками.
     */
    private function withBody(Regulation $regulation, RegulationVersion $version, User $reader): RegulationVersion
    {
        $version->load([
            'groups:id,name',
            'attachments',
            'quiz.questions.options',
            'quiz.examiner:id,last_name,first_name,middle_name',
        ]);

        $version->setAttribute('sends_content', true);
        $version->setAttribute(
            'is_mine',
            $this->versions->defaultFor($regulation, $reader)?->getKey() === $version->getKey(),
        );

        $version->setAttribute('own_attempts', $this->ownAttempts($version->quiz, $reader));

        return $version;
    }

    /**
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
                'review_status' => $attempt->review_status->value,
                'review_status_label' => $attempt->review_status->label(),
                'review_comment' => $attempt->review_comment,
                'reviewed_at' => $attempt->reviewed_at?->toIso8601String(),
                'reviewed_by' => $attempt->reviewer?->name,
            ])->all();
    }

    /**
     * Версия чужого документа — тот же случай, что и её отсутствие: адресом
     * своего материала чужой не открыть.
     */
    private function ensureBelongs(Regulation $regulation, RegulationVersion $version): void
    {
        abort_if($version->regulation_id !== $regulation->getKey(), HttpResponse::HTTP_NOT_FOUND);
    }
}
