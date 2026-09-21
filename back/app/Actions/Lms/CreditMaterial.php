<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Actions\News\AcknowledgeNews;
use App\Enums\NewsAcknowledgementSource;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\User;
use App\Support\Lms\MaterialDues;
use Illuminate\Database\Eloquent\Model;

/**
 * Зачитывает материал человеку — если материалу больше нечего от него ждать.
 *
 * Одно место на все четыре материала и на все пути, какими зачёт наступает: сдал
 * тест, прошёл обязательный опрос, нажал «ознакомлен». Что именно значит зачёт,
 * решает владелец: у урока это пройденный урок, у документа и справочника —
 * ознакомление, у новости — подтверждение.
 *
 * Заведено ради обязательного опроса, и без него было бы не нужно. Пока
 * требование у материала было одно — тест, — зачёт наступал там же, где тест
 * оценивался. С двумя требованиями так нельзя: сдавший тест и не ответивший на
 * опрос оставался бы незачтённым навсегда, потому что тест он уже сдал и второго
 * раза не будет. Поэтому зачёт ставит не то действие, которое было последним, а
 * это — и ставит, только когда сдано всё (см. MaterialDues).
 *
 * Молчит, когда сдано не всё: это не ошибка, а «ещё не время». Отказывать здесь
 * нечему — человек не просил зачёта, он ответил на вопросы.
 */
final readonly class CreditMaterial
{
    public function __construct(
        private AcknowledgeRegulation $acknowledgeRegulation,
        private AcknowledgeNews $acknowledgeNews,
        private CompleteLesson $completeLesson,
        private MaterialDues $dues,
    ) {}

    /**
     * @param  Enrollment|null  $enrollment  запись на курс — нужна только уроку
     */
    public function handle(?Model $owner, User $reader, ?Enrollment $enrollment = null): void
    {
        if ($owner === null || ! $this->dues->settled($owner, $reader)) {
            return;
        }

        if ($owner instanceof Regulation) {
            $this->acknowledgeRegulation->handle($owner, $reader);

            return;
        }

        /*
         * Сданное при версии засчитывает ознакомление с самим документом
         * (2026-09-12), а версия остаётся пометкой на отметке: версия у человека
         * одна, и прочитавший свою прочитал документ.
         */
        if ($owner instanceof RegulationVersion) {
            $regulation = $owner->loadMissing('regulation')->regulation;

            if ($regulation !== null) {
                $this->acknowledgeRegulation->handle($regulation, $reader, $owner);
            }

            return;
        }

        if ($owner instanceof News) {
            $this->acknowledgeNews->handle($owner, $reader, $this->newsSource($owner));

            return;
        }

        /*
         * Урок закрывается только тогда, когда до него дошла очередь: курс
         * проходят по порядку, и досдача последнего урока не должна зачитывать
         * курс целиком. Непройденные предыдущие попытку не отменяют — урок
         * зачтётся, как только очередь дойдёт до него.
         */
        if ($owner instanceof Lesson
            && $enrollment !== null
            && $this->completeLesson->blockedBy($enrollment, $owner) === null) {
            $this->completeLesson->handle($enrollment, $owner);
        }
    }

    /**
     * Чем человек подтвердил новость — тестом или опросом.
     *
     * Разбирающему журнал ознакомлений это не всё равно: «сдал тест» и «прошёл
     * опрос» — разные основания, и первое говорит о понимании, а второе о
     * мнении. Тест старше: если сдан он, им и объясняется отметка.
     */
    private function newsSource(News $news): NewsAcknowledgementSource
    {
        $quiz = $news->loadMissing('quiz')->quiz;

        return $quiz === null
            ? NewsAcknowledgementSource::Survey
            : NewsAcknowledgementSource::Quiz;
    }
}
