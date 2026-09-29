<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Http\Requests\Lms\SaveVersionRequest;
use App\Http\Resources\Lms\MaterialVersionResource;
use App\Models\MaterialVersion;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Ведение версий — одно на все материалы, у которых они бывают.
 *
 * Устройство версии от материала не зависит: название, круг адресатов,
 * закрытость, порядок и тело. Разного ровно два — чьим правом её правят и чьим
 * читают, — и это спрашивается у контроллера (authorizeManaging, authorizeRead),
 * а не решается здесь.
 *
 * Отдельным трейтом, а не двумя контроллерами со схожим кодом: правил тут
 * немало (порядок решает спор, закрытая чужая отвечает «не найдено», тело едет
 * только при чтении одной), и разойтись у документа с уроком им нельзя.
 */
trait ManagesMaterialVersions
{
    /**
     * Все версии — тому, кто материал ведёт.
     *
     * Без тел: панель показывает названия и круг групп, а статью правят на
     * своём экране.
     */
    protected function listFor(Model $material): AnonymousResourceCollection
    {
        $this->authorizeManaging($material);

        return MaterialVersionResource::collection($this->versionsOf($material));
    }

    /**
     * Одна версия целиком — читателю.
     *
     * Тело приходит только здесь: переключатель из пяти строк весил бы пятью
     * статьями, а читают за раз одну.
     */
    protected function showOne(Request $request, Model $material, MaterialVersion $version): MaterialVersionResource
    {
        $this->authorizeReading($material);

        /** @var User $reader */
        $reader = $request->user();

        $this->ensureBelongs($material, $version);

        // Закрытая чужая версия отвечает «не найдено», а не «нельзя»: её
        // название выдало бы её не хуже текста.
        abort_unless($this->versions->allows($version, $reader), HttpResponse::HTTP_NOT_FOUND);

        return MaterialVersionResource::make($this->withBody($material, $version, $reader));
    }

    protected function storeFor(SaveVersionRequest $request, Model $material): JsonResponse
    {
        $this->authorizeManaging($material);

        $version = DB::transaction(function () use ($request, $material): MaterialVersion {
            /** @var MaterialVersion $version */
            $version = $material->versions()->create([
                ...$request->toAttributes(),

                // В конец списка: порядок задаёт автор, и новая версия не
                // должна молча обгонять уже расставленные.
                'position' => (int) $material->versions()->max('position') + 1,
            ]);

            $version->groups()->sync($request->groups());
            $version->departments()->sync($request->departments());

            return $version;
        });

        return MaterialVersionResource::make($version->load(['groups:id,name', 'departments:id,name']))
            ->response()
            ->setStatusCode(HttpResponse::HTTP_CREATED);
    }

    protected function updateOne(
        SaveVersionRequest $request,
        Model $material,
        MaterialVersion $version,
    ): MaterialVersionResource {
        $this->authorizeManaging($material);

        $this->ensureBelongs($material, $version);

        $attributes = $request->toAttributes();

        // Имена блокам присваивает сервер — как в самом материале, и по той же
        // причине: по ним собирается нарезка для консультанта, а пришедшее от
        // клиента имя может оказаться выдуманным или чужим.
        if (array_key_exists('content_json', $attributes)) {
            $attributes['content_json'] = $this->blocks->assign($attributes['content_json']);
        }

        DB::transaction(function () use ($version, $attributes, $request): void {
            $version->fill($attributes)->save();
            $version->groups()->sync($request->groups());
            $version->departments()->sync($request->departments());
        });

        return MaterialVersionResource::make($version->load(['groups:id,name', 'departments:id,name']));
    }

    /**
     * Порядок версий — им же решается спор.
     *
     * Человек, попавший в две версии сразу, получает первую по этому списку
     * (решение пользователя 2026-09-12), поэтому порядок здесь не украшение.
     * Присылается целиком, как и всякий список на этих экранах: «сохранить»
     * означает «пусть будет вот так».
     */
    protected function reorderFor(Request $request, Model $material): AnonymousResourceCollection
    {
        $this->authorizeManaging($material);

        /** @var list<int|string> $order */
        $order = $request->input('versions', []);
        $own = $material->versions()->pluck('id')->map(intval(...))->all();

        DB::transaction(function () use ($order, $own): void {
            $position = 0;

            foreach ($order as $id) {
                // Чужие номера молча пропускаются: список порядка не место
                // добавлять версию в материал.
                if (in_array((int) $id, $own, strict: true)) {
                    MaterialVersion::query()->whereKey((int) $id)->update(['position' => ++$position]);
                }
            }
        });

        return MaterialVersionResource::collection($this->versionsOf($material));
    }

    /**
     * Убрать версию.
     *
     * Насовсем и сразу: за версией не стоит ничего, что потребовало бы корзины,
     * — отметки о прохождении остаются при материале и лишь теряют пометку о
     * том, по какой версии их поставили.
     */
    protected function destroyOne(Model $material, MaterialVersion $version): Response
    {
        $this->authorizeManaging($material);

        $this->ensureBelongs($material, $version);

        $version->delete();

        return response()->noContent();
    }

    /**
     * Версия чужого материала — тот же случай, что и её отсутствие: адресом
     * своего материала чужую версию не открыть.
     */
    protected function ensureBelongs(Model $material, MaterialVersion $version): void
    {
        abort_unless($version->belongsToMaterial($material), HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * @return Collection<int, MaterialVersion>
     */
    private function versionsOf(Model $material)
    {
        return $material->versions()->with(['groups:id,name', 'departments:id,name'])->get();
    }

    /**
     * Статья, файлы и проверка версии — вместе со своими прошлыми попытками.
     */
    private function withBody(Model $material, MaterialVersion $version, User $reader): MaterialVersion
    {
        $version->load([
            'groups:id,name', 'departments:id,name',
            'quiz.questions.options',
            'survey.questions.options',
            'quiz.examiner:id,last_name,first_name,middle_name',
            // Файлы лежат в разных таблицах у урока и документа; загружается та,
            // что подходит этой версии, — см. MaterialVersion::files().
            $version->isOfLesson() ? 'lessonAttachments' : 'regulationAttachments',
        ]);

        $version->setAttribute('sends_content', true);
        $version->setAttribute(
            'is_mine',
            $this->versions->defaultFor($material, $reader)?->getKey() === $version->getKey(),
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

    /** Кто вправе вести версии этого материала. */
    abstract protected function authorizeManaging(Model $material): void;

    /** Кому материал открыт на чтение — а значит, и его версии. */
    abstract protected function authorizeReading(Model $material): void;
}
