<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Models\Lesson;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Документы и справочники, приложенные к уроку.
 *
 * Список задаётся целиком и в том порядке, в каком пришёл: экран показывает его
 * весь, «сохранить» там означает «пусть будет вот так», а порядок — то, в каком
 * их читают после статьи.
 *
 * Связь односторонняя, поэтому переписывается одна сторона: у приложенного
 * документа своя жизнь, и появляться в нём урок не должен — см.
 * PinFrequentQuestions, где то же правило и по той же причине.
 */
final readonly class AttachLessonMaterials
{
    /**
     * @param  list<int>  $materialIds  в том порядке, в каком их показывать
     */
    public function set(Lesson $lesson, array $materialIds, User $actor): Lesson
    {
        $wanted = array_values(array_unique(array_map(intval(...), $materialIds)));

        DB::transaction(function () use ($lesson, $wanted, $actor): void {
            // Целиком, а не построчно: порядок задаёт номер в строке, и
            // перестановка двух материалов местами — правка каждой строки
            // между ними. Разбирать такие переносы дороже, чем записать
            // десяток строк заново.
            $lesson->materials()->detach();

            $lesson->materials()->attach(
                collect($wanted)
                    ->mapWithKeys(fn (int $materialId, int $index): array => [
                        $materialId => ['position' => $index + 1, 'attached_by_id' => $actor->getKey()],
                    ])
                    ->all(),
            );
        });

        return $lesson->load('materials');
    }
}
