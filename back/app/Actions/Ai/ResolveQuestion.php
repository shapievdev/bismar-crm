<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Enums\AnswerSource;
use App\Jobs\EmbedLesson;
use App\Models\ConsultantQuestion;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Закрывает вопрос из журнала: заносит ответ в урок и возвращает его спросившему.
 *
 * Тем и отличается от обычной правки таблицы урока, что делает два дела разом.
 * Строка в уроке — чтобы следующий, кто спросит о том же, получил выверенный
 * ответ от самого консультанта. Отметка на вопросе — чтобы тот, кто спрашивал
 * и не дождался, узнал об этом в том же разговоре, а не проверял базу знаний
 * время от времени сам.
 *
 * Ровно на это журнал и заводился: он собирает, чего базе не хватает, — а вот
 * пути от такой записи обратно к материалу до сих пор не было, и автор
 * переносил вопросы руками, открывая нужный урок в соседней вкладке.
 */
final readonly class ResolveQuestion
{
    public function handle(
        ConsultantQuestion $question,
        Lesson $lesson,
        string $asked,
        string $answer,
        User $author,
    ): ConsultantQuestion {
        $asked = trim($asked);
        $answer = trim($answer);

        DB::transaction(function () use ($question, $lesson, $asked, $answer, $author): void {
            $lesson->answers()->create([
                // В конец таблицы: место строки в ней — порядок, выбранный
                // автором урока, и вклиниваться в него незачем.
                'position' => (int) $lesson->answers()->max('position') + 1,
                'question' => $asked,
                'answer' => $answer,
                // Текст урока: где именно это написано, автор укажет в самой
                // таблице, если захочет. Требовать место здесь значит требовать
                // его в ту минуту, когда человек отвечает на заявку.
                'source_kind' => AnswerSource::Text,
            ]);

            $question->forceFill([
                // Снимком, а не ссылкой на созданную строку: строку могут
                // переписать или удалить, а сотруднику было сказано то, что
                // было сказано.
                'resolution' => $answer,
                'resolution_lesson_id' => $lesson->getKey(),
                'resolved_by_id' => $author->getKey(),
                'resolved_at' => now(),
                'resolution_seen_at' => null,
            ])->save();
        });

        // После записи, а не внутри неё: считает векторы сторонний сервис, и
        // держать ради него открытую транзакцию незачем.
        EmbedLesson::dispatchIfConfigured((int) $lesson->getKey());

        return $question->refresh()->load(['resolutionLesson:id,title', 'resolvedBy:id,first_name,last_name,middle_name']);
    }
}
