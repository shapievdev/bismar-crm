<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\AppealToAuthor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SendAppealRequest;
use App\Http\Resources\Lms\CoursePersonResource;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Regulation;
use App\Models\User;
use App\Support\Lms\MaterialAppeal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * «Ответа не хватило» и «здесь написано неверно» — с курса, урока, документа
 * и справочника.
 *
 * Четыре маршрута, а не один с видом материала в теле: доступ к материалу
 * решают middleware группы по параметру маршрута — и закрытый курс, и курс,
 * до которого не дошла очередь плана, отсекаются раньше контроллера. Опиши
 * материал телом запроса — обе проверки пришлось бы повторять здесь, и однажды
 * одну из них забыли бы.
 *
 * Кому можно написать, экран знает и сам: автор и ответственные приходят
 * вместе с материалом. Список всё равно отдаётся отдельно — экран урока
 * материала целиком не грузит, а проверять присланного адресата сервер обязан
 * в любом случае.
 */
final class AppealController extends Controller
{
    public function __construct(private readonly AppealToAuthor $appeal) {}

    public function courseRecipients(Request $request, Course $course): JsonResponse
    {
        $this->seeing($request, $course);

        return $this->people(MaterialAppeal::forCourse($course->load('author', 'experts')));
    }

    public function lessonRecipients(Request $request, Lesson $lesson): JsonResponse
    {
        $this->seeing($request, $lesson->owningCourse());

        return $this->people(MaterialAppeal::forLesson($lesson));
    }

    public function materialRecipients(Request $request, Regulation $regulation): JsonResponse
    {
        $this->seeing($request, $regulation);

        return $this->people(MaterialAppeal::forMaterial($regulation->load('author', 'experts')));
    }

    public function fromCourse(SendAppealRequest $request, Course $course): JsonResponse
    {
        $this->seeing($request, $course);

        return $this->send($request, MaterialAppeal::forCourse($course->load('author', 'experts')));
    }

    public function fromLesson(SendAppealRequest $request, Lesson $lesson): JsonResponse
    {
        $this->seeing($request, $lesson->owningCourse());

        return $this->send($request, MaterialAppeal::forLesson($lesson));
    }

    public function fromMaterial(SendAppealRequest $request, Regulation $regulation): JsonResponse
    {
        $this->seeing($request, $regulation);

        return $this->send($request, MaterialAppeal::forMaterial($regulation->load('author', 'experts')));
    }

    /**
     * Написать можно только о том, что человеку открыто.
     *
     * Приватность и очередь плана проверены на входе в группу; здесь остаётся
     * то, чего маршрут не знает, — черновик виден лишь тому, кто его правит.
     */
    private function seeing(Request $request, Course|Regulation|null $material): void
    {
        if ($material === null || Gate::denies('view', $material)) {
            abort(HttpResponse::HTTP_NOT_FOUND);
        }
    }

    private function people(MaterialAppeal $appeal): JsonResponse
    {
        return response()->json(['data' => CoursePersonResource::collection($appeal->recipients())]);
    }

    private function send(SendAppealRequest $request, MaterialAppeal $appeal): JsonResponse
    {
        /** @var User $reader */
        $reader = $request->user();

        /** @var User $recipient */
        $recipient = User::query()->findOrFail($request->integer('recipient_id'));

        // За материал отвечают названные люди, и только они: письмо «поправьте
        // курс» постороннему коллеге — не обращение, а рассылка.
        abort_unless($appeal->allows($recipient), HttpResponse::HTTP_FORBIDDEN, 'Этот человек за материал не отвечает.');

        // Самому себе замечаний не пишут: автор правит материал, а не заводит
        // с собой переписку об этом.
        abort_if(
            (int) $recipient->getKey() === (int) $reader->getKey(),
            HttpResponse::HTTP_FORBIDDEN,
            'Это ваш материал — его можно поправить сразу.',
        );

        $message = $this->appeal->handle(
            reader: $reader,
            recipient: $recipient,
            appeal: $appeal,
            reason: $request->reason(),
            body: (string) $request->input('body'),
        );

        // Номер переписки, чтобы экран предложил перейти туда, где теперь ждут
        // ответа, — а не оставил человека гадать, дошло ли.
        return response()->json([
            'data' => ['conversation_id' => $message->conversation_id],
        ], HttpResponse::HTTP_CREATED);
    }
}
