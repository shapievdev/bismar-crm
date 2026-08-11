<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Ai;

use App\Actions\Ai\ResolveQuestion;
use App\Enums\ConsultantOutcome;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ai\ResolveQuestionRequest;
use App\Http\Resources\Ai\QuestionResource;
use App\Models\ConsultantQuestion;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Журнал вопросов — список того, чего базе знаний не хватает.
 *
 * Открыт тем, кто пишет материал: чинить пробел всё равно им, а держать список
 * дыр у одного суперадминистратора значит, что до авторов он не дойдёт.
 *
 * Но не весь: ответ, собранный из закрытого курса, виден только тем, кому
 * открыт сам курс. Иначе приватность обходилась бы чтением журнала.
 */
final class QuestionLogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): AnonymousResourceCollection
    {
        /** @var User $reader */
        $reader = $request->user();

        $questions = ConsultantQuestion::query()
            ->with([
                'asker:id,first_name,last_name,middle_name',
                'resolutionLesson:id,title',
                'resolvedBy:id,first_name,last_name,middle_name',
            ])
            ->drawnFromWhatIsOpenTo($reader)
            ->when(
                $request->string('outcome')->isNotEmpty(),
                fn ($query) => $query->where('outcome', $request->string('outcome')->value()),
            )
            ->when(
                $request->boolean('unanswered'),
                fn ($query) => $query->unanswered(),
            )
            // Заявки — то, с чего разбор журнала начинают: здесь не догадка о
            // пробеле, а просьба живого человека, оставшегося без ответа.
            ->when(
                $request->boolean('requested'),
                fn ($query) => $query->awaitingAnswer(),
            )
            ->when(
                $request->string('search')->isNotEmpty(),
                // Коллация ICU: под C-коллацией базы ILIKE не сворачивает
                // кириллицу, и поиск по «Краска» не найдёт «краска».
                fn ($query) => $query->whereRaw(
                    'question COLLATE "und-x-icu" ILIKE ?',
                    ['%'.$request->string('search')->value().'%'],
                ),
            )
            ->latest('created_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return QuestionResource::collection($questions)->additional([
            'meta' => [
                'outcomes' => ConsultantOutcome::options(),
                'summary' => $this->summary($reader),
            ],
        ]);
    }

    /**
     * Заносит ответ в урок и возвращает его тому, кто спрашивал.
     *
     * Ради этого журнал и заводился: он собирает, чего базе не хватает, — а
     * пути обратно к материалу до сих пор не было, и автор переносил вопросы
     * руками, открывая нужный урок в соседней вкладке.
     */
    public function resolve(
        ResolveQuestionRequest $request,
        ConsultantQuestion $question,
        ResolveQuestion $resolve,
    ): QuestionResource {
        /** @var User $author */
        $author = $request->user();

        $this->authoriseReading($author, $question);

        /** @var Lesson $lesson */
        $lesson = Lesson::query()
            ->with('module.course')
            ->findOrFail($request->integer('lesson_id'));

        // Право на урок — это право на его курс: чужой курс не дополняют даже
        // из журнала, где вопрос виден.
        Gate::authorize('update', $lesson->module->course);

        return QuestionResource::make($resolve->handle(
            $question,
            $lesson,
            (string) $request->validated('question'),
            (string) $request->validated('answer'),
            $author,
        ));
    }

    /**
     * Убирает вопрос из журнала совсем.
     *
     * Для случайных нажатий, проверок «а что ты умеешь» и прочего, чего в
     * перечне пробелов быть не должно, — иначе он тонет в шуме и его перестают
     * открывать.
     *
     * Удаление настоящее, и вместе со строкой вопрос пропадает из переписки
     * того, кто его задал. Так и задумано: строка одна на двоих, и держать её
     * ради чата, признав ненужной, значит оставить сотруднику ответ, который
     * автор счёл мусором. Обратного хода нет — сотрудник у себя переписку лишь
     * прячет (см. forget), а здесь именно удаление.
     */
    public function destroy(Request $request, ConsultantQuestion $question): Response
    {
        /** @var User $author */
        $author = $request->user();

        $this->authoriseReading($author, $question);

        $question->delete();

        return response()->noContent();
    }

    /**
     * Виден ли вопрос этому автору вообще.
     *
     * Та же граница, что и в списке: ответ, собранный из закрытого курса, виден
     * только тем, кому открыт сам курс. Без этой проверки строку, которой не
     * видно в списке, можно было бы удалить или закрыть по прямой ссылке.
     */
    private function authoriseReading(User $author, ConsultantQuestion $question): void
    {
        abort_unless(
            ConsultantQuestion::query()
                ->whereKey($question->getKey())
                ->drawnFromWhatIsOpenTo($author)
                ->exists(),
            HttpResponse::HTTP_FORBIDDEN,
        );
    }

    /**
     * Сколько чего за последние две недели — чтобы было видно, растёт ли
     * число вопросов без ответа или уже нет.
     *
     * @return array<string, int>
     */
    private function summary(User $reader): array
    {
        $counts = ConsultantQuestion::query()
            ->drawnFromWhatIsOpenTo($reader)
            ->where('created_at', '>=', now()->subDays(14))
            ->selectRaw('outcome, count(*) AS total')
            ->groupBy('outcome')
            ->pluck('total', 'outcome');

        $summary = [];

        foreach (ConsultantOutcome::cases() as $outcome) {
            $summary[$outcome->value] = (int) $counts->get($outcome->value, 0);
        }

        // Заявки — без срока давности: просьба, поданная три недели назад, ждёт
        // ответа ровно так же, как вчерашняя, и прятать её за окном в две
        // недели значит потерять именно то, что нужнее всего.
        $summary['requests'] = ConsultantQuestion::query()
            ->drawnFromWhatIsOpenTo($reader)
            ->awaitingAnswer()
            ->count();

        return $summary;
    }
}
