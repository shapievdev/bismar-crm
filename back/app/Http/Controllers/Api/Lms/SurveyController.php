<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Lms;

use App\Actions\Lms\EnrollLearner;
use App\Actions\Lms\SaveSurvey;
use App\Actions\Lms\SubmitSurvey;
use App\Exceptions\ConflictException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Lms\SaveSurveyRequest;
use App\Http\Requests\Lms\SubmitSurveyRequest;
use App\Http\Resources\Lms\SurveyResource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Support\Lms\SurveySummary;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Опрос при материале — один контроллер на урок, документ, справочник, версию и
 * новость.
 *
 * Не пять двойников, как у тестов: опрос устроен одинаково у всех владельцев, и
 * различает их ровно два места — у кого спрашивать право на правку и что значит
 * зачёт. Второе знает CreditMaterial, первое — `authorizeEditing()` ниже.
 *
 * Владелец берётся из адреса, а не из тела запроса: адреса всё равно нужны
 * раздельные — у курсов, документов, справочников и новостей свои права, и
 * стоят они на маршрутах. Проверка права повторяется и здесь: маршрут говорит,
 * кого пускать в раздел, а политика — чей это материал.
 */
final class SurveyController extends Controller
{
    public function save(SaveSurveyRequest $request, SaveSurvey $save): SurveyResource
    {
        $owner = $this->owner($request);

        $this->authorizeEditing($owner);

        /** @var array{title: string, description?: ?string, is_required?: bool, is_anonymous?: bool, closes_at?: ?string, thanks?: ?string, questions: array<int, array<string, mixed>>} $attributes */
        $attributes = $request->validated();

        return SurveyResource::make($save->handle($owner, $attributes));
    }

    public function destroy(Request $request): Response
    {
        $owner = $this->owner($request);

        $this->authorizeEditing($owner);

        /*
         * Ответы уходят вместе с опросом, и иначе быть не может: они разложены
         * по номерам его вопросов и без них не читаются. Предупредить об этом —
         * дело экрана, а не запроса.
         */
        $owner->survey()->delete();

        return response()->noContent();
    }

    /**
     * Пройти опрос — один раз.
     *
     * @throws ConflictException
     */
    public function submit(SubmitSurveyRequest $request, SubmitSurvey $submit): JsonResponse
    {
        $owner = $this->owner($request);

        $this->authorizeAnswering($owner);

        $survey = $owner->loadMissing('survey')->survey;

        abort_if($survey === null, HttpResponse::HTTP_NOT_FOUND);

        /** @var User $respondent */
        $respondent = $request->user();

        $response = $submit->handle(
            $survey,
            $respondent,
            $request->answers(),
            // Запись на курс нужна только уроку: зачёт урока пишется в неё.
            $owner instanceof Lesson ? $this->enrollmentFor($owner, $respondent) : null,
        );

        return response()->json([
            'data' => [
                'id' => $response->getKey(),
                'submitted_at' => $response->submitted_at?->toIso8601String(),

                // Слово после отправки — авторское, если он его написал.
                'thanks' => $survey->thanks,

                // Что стало с материалом: обязательный опрос мог быть последним,
                // чего он ждал, — и экран покажет зачёт, не спрашивая снова.
                'is_credited' => $this->isCredited($owner, $respondent),
            ],
        ], HttpResponse::HTTP_CREATED);
    }

    /**
     * Сводка ответов — тому, кто ведёт материал.
     *
     * Не отдельным экраном: она стоит там же, где статистика прохождения, и
     * закрыта тем же правом на правку материала.
     */
    public function summary(Request $request, SurveySummary $summary): JsonResponse
    {
        $owner = $this->owner($request);

        $this->authorizeEditing($owner);

        $survey = $owner->loadMissing('survey')->survey;

        abort_if($survey === null, HttpResponse::HTTP_NOT_FOUND);

        return response()->json(['data' => $summary->of($survey)]);
    }

    /**
     * Материал из адреса.
     *
     * Подставляется вручную, а не средствами фреймворка, и это вынужденно:
     * неявную подстановку Laravel делает по типизированному параметру метода, а
     * у этого контроллера владелец один на четыре вида — типизировать его нечем.
     * Объявлять подстановку глобально (`Route::model`) было бы хуже: те же имена
     * стоят в адресах корзины, где материал берут вместе с удалёнными, и общее
     * правило сломало бы восстановление.
     *
     * Через `resolveRouteBinding`, а не `find`: документ и новость в адресах
     * стоят слагом, а не номером, и знают об этом сами.
     *
     * Версия ищется раньше документа: в её адресе стоят оба, и опрос при версии —
     * не опрос при документе.
     */
    private function owner(Request $request): Lesson|Regulation|RegulationVersion|News
    {
        /** @var array<string, class-string<Lesson|Regulation|RegulationVersion|News>> $kinds */
        $kinds = [
            'version' => RegulationVersion::class,
            'lesson' => Lesson::class,
            'news' => News::class,
            'regulation' => Regulation::class,
        ];

        foreach ($kinds as $key => $class) {
            $value = $request->route($key);

            if ($value === null) {
                continue;
            }

            // Подставленную модель берём как есть: маршруты с типизированным
            // параметром когда-нибудь появятся, и переоткрывать её было бы лишним
            // запросом.
            $owner = $value instanceof Model ? $value : (new $class)->resolveRouteBinding($value);

            abort_if($owner === null, HttpResponse::HTTP_NOT_FOUND);

            if ($owner instanceof RegulationVersion) {
                $this->ensureVersionBelongs($request, $owner);
            }

            return $owner;
        }

        abort(HttpResponse::HTTP_NOT_FOUND);
    }

    /**
     * Версия — из того документа, в адресе которого она стоит.
     *
     * Иначе правом на свой документ правился бы опрос при чужой версии: номер
     * версии приходит из адреса, и проверять его происхождение обязаны мы.
     */
    private function ensureVersionBelongs(Request $request, RegulationVersion $version): void
    {
        $value = $request->route('regulation');

        $regulation = $value instanceof Regulation
            ? $value
            : (new Regulation)->resolveRouteBinding($value);

        abort_if(
            $regulation === null || (int) $version->regulation_id !== (int) $regulation->getKey(),
            HttpResponse::HTTP_NOT_FOUND,
        );
    }

    /**
     * Вправе ли этот человек править материал — а значит, и его опрос.
     *
     * У каждого раздела своё право (2026-09-11): опрос при справочнике заводит
     * тот, кто ведёт справочники, при новости — тот, кто ведёт новости.
     */
    private function authorizeEditing(Model $owner): void
    {
        match (true) {
            $owner instanceof Lesson => Gate::authorize('update', $this->courseOf($owner)),
            $owner instanceof Regulation => Gate::authorize('update', $owner),
            $owner instanceof RegulationVersion => Gate::authorize('update', $this->regulationOf($owner)),
            $owner instanceof News => Gate::authorize('update', $owner),
            default => abort(HttpResponse::HTTP_NOT_FOUND),
        };
    }

    /**
     * Вправе ли этот человек отвечать.
     *
     * Правило то же, по какому он отмечается ознакомленным: опрос при материале —
     * часть чтения материала, а не отдельный разговор. У урока это решает доступ
     * к курсу и очередь плана — оба стоят на маршруте (см. routes/api.php).
     */
    private function authorizeAnswering(Model $owner): void
    {
        match (true) {
            $owner instanceof Lesson => Gate::authorize('view', $this->courseOf($owner)),
            $owner instanceof Regulation => Gate::authorize('acknowledge', $owner),
            $owner instanceof RegulationVersion => Gate::authorize('acknowledge', $this->regulationOf($owner)),
            $owner instanceof News => Gate::authorize('acknowledge', $owner),
            default => abort(HttpResponse::HTTP_NOT_FOUND),
        };
    }

    /**
     * Зачтён ли материал этому человеку после отправки опроса.
     *
     * Спрашивается у самих отметок, а не у догадки: зачёт мог наступить только
     * что — или не наступить вовсе, если материал ждёт ещё и теста.
     */
    private function isCredited(Model $owner, User $reader): bool
    {
        if ($owner instanceof Regulation) {
            return $owner->acknowledgements()->where('user_id', $reader->getKey())->exists();
        }

        if ($owner instanceof RegulationVersion) {
            return $this->regulationOf($owner)
                ->acknowledgements()
                ->where('user_id', $reader->getKey())
                ->exists();
        }

        if ($owner instanceof News) {
            return $owner->acknowledgements()->where('user_id', $reader->getKey())->exists();
        }

        if ($owner instanceof Lesson) {
            $enrollment = $this->enrollmentFor($owner, $reader);

            return $enrollment !== null && $enrollment->completions()
                ->where('lesson_id', $owner->getKey())
                ->exists();
        }

        return false;
    }

    /**
     * Запись на курс, к которому относится урок, — та же, в которую пишется
     * прогресс (см. LearningController).
     *
     * Черновик записи не заводит: чтение редактором — не прогресс.
     */
    private function enrollmentFor(Lesson $lesson, User $reader): ?Enrollment
    {
        $course = $this->courseOf($lesson);

        $existing = Enrollment::query()
            ->where('course_id', $course->getKey())
            ->where('user_id', $reader->getKey())
            ->first();

        if ($existing !== null || ! $course->status->isOpenToLearners()) {
            return $existing;
        }

        return app(EnrollLearner::class)->handle($course, $reader, deliberate: false);
    }

    private function courseOf(Lesson $lesson): Course
    {
        $course = $lesson->loadMissing('module.course')->module?->course;

        // Урок без курса — испорченные данные, а не плохой запрос.
        abort_if($course === null, HttpResponse::HTTP_NOT_FOUND);

        return $course;
    }

    private function regulationOf(RegulationVersion $version): Regulation
    {
        $regulation = $version->loadMissing('regulation')->regulation;

        abort_if($regulation === null, HttpResponse::HTTP_NOT_FOUND);

        return $regulation;
    }
}
