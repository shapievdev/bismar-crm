<?php

declare(strict_types=1);

namespace App\Actions\Exams;

use App\Enums\ExamStatus;
use App\Models\Exam;
use App\Models\User;
use App\Support\SlugGenerator;

/**
 * Создаёт экзамен или правит его шапку: название, окно, таймер.
 *
 * Вопросы сюда не приходят — их ведёт SaveQuiz тем же путём, что у урока и
 * документа: редактор вопросов один на всё приложение, и экзамен не повод
 * заводить второй. Статус тоже не здесь: публикация — отдельное решение со
 * своими проверками, см. ChangeExamStatus.
 */
final readonly class SaveExam
{
    public function __construct(private SlugGenerator $slugs) {}

    /**
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     opens_at?: ?string,
     *     closes_at?: ?string,
     *     time_limit_minutes?: ?int,
     *     shuffle_questions?: bool,
     * } $attributes
     */
    public function handle(?Exam $exam, array $attributes, User $actor): Exam
    {
        $exam ??= new Exam([
            'status' => ExamStatus::Draft,
            'created_by' => $actor->getKey(),
        ]);

        $exam->fill([
            'title' => $attributes['title'],
            'description' => $attributes['description'] ?? null,
            'opens_at' => $attributes['opens_at'] ?? null,
            'closes_at' => $attributes['closes_at'] ?? null,
            'time_limit_minutes' => $attributes['time_limit_minutes'] ?? null,
            'shuffle_questions' => $attributes['shuffle_questions'] ?? false,
        ]);

        // Адрес даётся один раз: сменившееся название не рвёт ссылки из
        // уведомлений, которые людям уже разослали.
        if (! $exam->exists) {
            $exam->slug = $this->slugs->generate($attributes['title'], Exam::class);
        }

        $exam->save();

        return $exam;
    }
}
