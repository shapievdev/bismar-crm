<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Enums\AnswerPath;
use App\Enums\ConsultantOutcome;
use App\Models\ConsultantQuestion;
use App\Models\User;
use App\Support\Ai\CourseExpert;
use App\Support\Ai\ModelSettings;
use App\Support\Ai\Source;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Записывает заданный вопрос и его исход.
 *
 * Никогда не мешает ответу: журнал — вспомогательная вещь, и потерянная запись
 * стоит дешевле, чем ошибка у сотрудника из-за упавшей вставки в таблицу.
 * Поэтому записанное возвращается, но отсутствие записи — не ошибка.
 */
final readonly class RecordQuestion
{
    public function __construct(private ModelSettings $settings) {}

    /**
     * Записанный вопрос, или null — если журнал недоступен.
     *
     * Возвращается ради чата: оценку сотрудник ставит той самой строке, а до
     * перезагрузки страницы узнать её иначе неоткуда.
     */
    public function answered(string $question, Answer $answer, ?User $asker, float $seconds): ?ConsultantQuestion
    {
        return $this->write($question, $asker, $seconds, [
            'answer' => $answer->text,
            // Чем искали на самом деле, когда вопрос был продолжением разговора.
            // Без этого разбирающий журнал видит «а сколько это сохнет?» рядом с
            // источниками про краску и не понимает, откуда они взялись.
            'searched_as' => $answer->searchedAs,
            // Снимком, а не связью с уроками: ссылка должна вести туда, куда
            // вела в день ответа. Урок могли переписать, и подставлять его
            // сегодняшнее содержимое под вчерашний ответ — значит показывать
            // то, чего консультант не говорил.
            'sources' => array_map(
                static fn (Source $source): array => $source->citation()->toArray(),
                $answer->sources,
            ),
            // Тем же снимком и по той же причине: показанное сотруднику
            // «смотрите также» — часть того разговора, а не сегодняшняя выдача
            // поиска по тому же вопросу.
            'related' => array_map(
                static fn (Source $source): array => $source->citation()->toArray(),
                $answer->related,
            ),
            // Кого посоветовали спросить — тем же снимком: ответственные за
            // курс со временем меняются, а сотруднику было сказано написать
            // вот этому человеку.
            'experts' => array_map(
                static fn (CourseExpert $expert): array => $expert->toArray(),
                $answer->experts,
            ),
            // Из каких закрытых курсов он собран — по этому журнал и решает,
            // кому эту строку показывать.
            'private_course_ids' => $answer->privateCourseIds,
            'retrieved' => $answer->retrieved,
            'cited' => count($answer->sources),
            'outcome' => $this->outcome($answer),
            'answered_from' => $answer->path,
        ]);
    }

    public function failed(string $question, Throwable $exception, ?User $asker, float $seconds): void
    {
        $this->write($question, $asker, $seconds, [
            'answer' => $exception->getMessage(),
            'outcome' => ConsultantOutcome::Failed,
        ]);
    }

    /**
     * Ответ без ссылок при найденных фрагментах — не то же самое, что пустой
     * поиск: материал у модели был, и она им не воспользовалась. Разделять их
     * важно, потому что чинятся они по-разному.
     */
    private function outcome(Answer $answer): ConsultantOutcome
    {
        // Готовый ответ отделён от собранного моделью: иначе даровые попадания
        // неотличимы от платных, и по журналу нельзя судить о расходе.
        if ($answer->verbatim) {
            return ConsultantOutcome::Verbatim;
        }

        // Ответ, собранный из близкого, ответом не считается, хотя ссылки в нём
        // есть: сотруднику сказали, где смотреть дальше, а написанного ответа
        // на его вопрос в базе нет. Считай такие за ответы — и перечень дыр,
        // ради которого журнал заведён, перестанет их показывать.
        if ($answer->path === AnswerPath::Related) {
            return ConsultantOutcome::Suggested;
        }

        if ($answer->sources !== []) {
            return ConsultantOutcome::Answered;
        }

        return $answer->retrieved === 0
            ? ConsultantOutcome::NothingFound
            : ConsultantOutcome::Unused;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function write(string $question, ?User $asker, float $seconds, array $attributes): ?ConsultantQuestion
    {
        try {
            return ConsultantQuestion::query()->create([
                'user_id' => $asker?->getKey(),
                'question' => $question,
                'model' => $this->settings->model(),
                'duration_ms' => (int) round($seconds * 1000),
                ...$attributes,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Вопрос не записан в журнал.', ['exception' => $exception]);

            return null;
        }
    }
}
