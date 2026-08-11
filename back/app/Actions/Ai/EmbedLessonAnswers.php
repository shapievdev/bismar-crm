<?php

declare(strict_types=1);

namespace App\Actions\Ai;

use App\Models\LessonAnswer;
use App\Support\Ai\Embedder;
use App\Support\Ai\ModelSettings;
use App\Support\Ai\Vector;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Считает и сохраняет векторы строк таблиц.
 *
 * По два на строку — вопрос и ответ отдельно. Один общий вектор на пару был бы
 * дешевле, но размывал бы обоих: вопрос «сколько сохнет второй слой» и ответ
 * «не менее четырёх часов при 20 °C» указывают в разные стороны, и склеенный
 * из них вектор не совпадает толком ни с одной формулировкой сотрудника.
 *
 * Считается ровно то, что написано в строке, — без названий курса и урока.
 * Сравнивают с ним вопрос сотрудника, а тот никаких названий не несёт: сложи
 * их в вектор строки, и сравнение пойдёт между неравным. Беда тем больше, чем
 * строка короче: у строки «Мямаев Хасбулла» на два слова приходилось две
 * строки заголовков, вектор получался про оформление реализации в 1С, и вопрос
 * «кто такой Мямаев Хасбулла» давал с ней 0.36 против порога 0.45 — то есть не
 * находился вовсе. Без заголовков та же пара даёт 0.86.
 *
 * Фрагменты расшифровок заголовки, наоборот, сохраняют: кусок из середины
 * записи сам по себе не говорит, о чём он, они длинные, и название на их фоне
 * почти ничего не весит. К тому же их порог не отсекает — по векторам они лишь
 * пересортировываются.
 *
 * Пересчитывает только те, у которых вектора нет или он посчитан другой
 * моделью: векторы разных моделей несравнимы, и смешивать их — значит молча
 * испортить ранжирование. Принудительно — при force: текст, по которому
 * считают, меняется правкой кода, и модель об этом ничего не знает.
 */
final readonly class EmbedLessonAnswers
{
    public function __construct(
        private Embedder $embedder,
        private ModelSettings $settings,
    ) {}

    /**
     * @return int сколько строк получили векторы
     */
    public function handle(?int $lessonId = null, bool $force = false): int
    {
        if (! $this->embedder->isAvailable()) {
            return 0;
        }

        $model = (string) $this->settings->embeddingModel();
        $done = 0;

        $this->stale($model, $lessonId, $force)
            ->chunkById(32, function (Collection $answers) use ($model, &$done): void {
                // Вопросы и ответы уходят одним запросом, а не двумя: сервис
                // берёт список, и делить его надвое значит платить дважды за
                // ту же задержку.
                $texts = [];

                foreach ($answers as $answer) {
                    $texts[] = $answer->question;
                    $texts[] = $answer->answer;
                }

                $vectors = $this->embedder->embed($texts);

                foreach ($answers->values() as $index => $answer) {
                    $question = $vectors[$index * 2] ?? null;
                    $reply = $vectors[$index * 2 + 1] ?? null;

                    if ($question === null || $reply === null) {
                        continue;
                    }

                    $answer->forceFill([
                        'question_embedding' => Vector::pack($question),
                        'answer_embedding' => Vector::pack($reply),
                        'embedding_model' => $model,
                    ])->save();

                    $done++;
                }
            });

        return $done;
    }

    /**
     * @return Builder<LessonAnswer>
     */
    private function stale(string $model, ?int $lessonId, bool $force): Builder
    {
        return LessonAnswer::query()
            ->when($lessonId !== null, static fn (Builder $query) => $query->where('lesson_id', $lessonId))
            ->unless($force, static fn (Builder $query) => $query->where(
                static function (Builder $query) use ($model): void {
                    $query->whereNull('question_embedding')
                        ->orWhereNull('answer_embedding')
                        ->orWhere('embedding_model', '!=', $model);
                },
            ));
    }
}
