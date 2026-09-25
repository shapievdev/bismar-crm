<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AttestationStatus;
use App\Enums\QuestionType;
use App\Enums\QuizKind;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Support\Lms\QuestionTable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class SaveQuiz
{
    /**
     * Сохраняет тест урока или документа целиком: редактор присылает его весь,
     * поэтому одно действие вместо пары «создать — изменить».
     *
     * Целиком — но не заново. Вопросы и варианты, присланные со своим номером,
     * правятся на месте, и это не бережливость, а условие того, чтобы разбор
     * прошлых попыток вообще работал: попытка хранит ответы, разложенные по
     * номерам вопросов и выбранных вариантов. Пересозданные вопросы получают
     * новые номера, и всё, что люди отвечали до правки, разом перестаёт с ними
     * сходиться — разбор показывает «без ответа» там, где человек отвечал, а
     * статистика вопроса считает его нетронутым.
     *
     * Убранное из присланного удаляется — вместе с попытками оно уносит и свою
     * часть разбора, но иначе снятый вопрос жил бы в тесте вечно.
     *
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     max_attempts?: ?int,
     *     kind?: string,
     *     examiner_id?: ?int,
     *     questions: array<int, array{id?: ?int, text: string, type: string, points: int, expected_answer?: ?string, table?: ?array<string, mixed>, options?: array<int, array{id?: ?int, text: string, is_correct: bool}>}>
     * } $attributes
     *
     * @throws ValidationException Когда аттестацию с непроверенными работами
     *                             пытаются сделать обычным тестом.
     */
    public function handle(Lesson|Regulation|RegulationVersion $owner, array $attributes): Quiz
    {
        $existing = Quiz::query()
            ->where('quizzable_type', $owner->getMorphClass())
            ->where('quizzable_id', $owner->getKey())
            ->first();

        [$kind, $examiner] = $this->reviewerOf($existing, $attributes);

        $this->guardAgainstStrandedWork($owner, $kind);

        return DB::transaction(function () use ($owner, $attributes, $kind, $examiner): Quiz {
            $quiz = Quiz::updateOrCreate(
                [
                    // Вид и номер вместе: урок №3 и документ №3 — разные вещи.
                    'quizzable_type' => $owner->getMorphClass(),
                    'quizzable_id' => $owner->getKey(),
                ],
                [
                    'title' => $attributes['title'],
                    'description' => $attributes['description'] ?? null,
                    // Планку ставит правило, а не присланное: урок зачитывается
                    // при всех верных ответах, и подделать это запросом нельзя.
                    'passing_score' => Quiz::PASSING_SCORE,
                    'max_attempts' => $attributes['max_attempts'] ?? null,

                    // Кто проверяет работы. Согласованность вида с вопросами и
                    // с проверяющим разобрана на входе — см. SaveQuizRequest.
                    'kind' => $kind,
                    'examiner_id' => $examiner,
                ],
            );

            $quiz->load('questions.options');

            $kept = [];

            foreach (array_values($attributes['questions']) as $position => $data) {
                $kept[] = $this->question($quiz, $data, $position)->getKey();
            }

            $quiz->questions()->whereNotIn('id', $kept)->delete();

            return $quiz->load('questions.options');
        });
    }

    /**
     * Кто будет проверять работы: вид теста и проверяющий вместе.
     *
     * Вместе — потому что порознь они бессмысленны: аттестация без адресата
     * никому не видна, а проверяющий при обычном тесте ждал бы работ, которые
     * к нему не придут.
     *
     * Не сказали про вид вовсе — оставляем как было. Прежде отсутствие поля
     * молча делало тест обычным, и вместе с видом обнулялся проверяющий: этого
     * хватало, чтобы сданные работы исчезли из очереди навсегда (боевой,
     * 2026-09-25). Правка вопросов не должна решать за автора, кто выносит
     * приговор, — о чём не сказали, то и не трогаем.
     *
     * @param  array{kind?: string, examiner_id?: ?int}  $attributes
     * @return array{0: QuizKind, 1: ?int}
     */
    private function reviewerOf(?Quiz $existing, array $attributes): array
    {
        if (! array_key_exists('kind', $attributes)) {
            return [$existing?->kind ?? QuizKind::Standard, $existing?->examiner_id];
        }

        $kind = QuizKind::from((string) $attributes['kind']);

        // У обычного теста проверяющего нет вовсе — даже если его прислали:
        // такую пару не принимает и проверка на входе.
        return [$kind, $kind->isAttestation() ? $attributes['examiner_id'] ?? null : null];
    }

    /**
     * Нельзя разжаловать аттестацию, пока по ней ждут ответа.
     *
     * Работы в очереди держатся на проверяющем: находит их запрос по
     * `quizzes.examiner_id`, и вместе с видом теста обнуляется он. Сданное
     * остаётся в базе, но не показывается больше никому — ни тому, кто
     * проверяет, ни тому, кто ждёт: у него работа так и висит «на проверке», а
     * урок не зачитывается никогда. Именно так на боевом 2026-09-25 повисли
     * четыре аттестации разом, и заметил это не отчёт, а человек.
     *
     * Смену проверяющего это не запрещает: очередь тогда не пропадает, а
     * переходит к новому — обычное дело, когда прежний в отпуске или уволился.
     *
     * @throws ValidationException
     */
    private function guardAgainstStrandedWork(Lesson|Regulation|RegulationVersion $owner, QuizKind $kind): void
    {
        if ($kind->isAttestation()) {
            return;
        }

        $waiting = QuizAttempt::query()
            ->where('review_status', AttestationStatus::Pending)
            ->whereHas('quiz', fn ($query) => $query
                ->where('quizzable_type', $owner->getMorphClass())
                ->where('quizzable_id', $owner->getKey())
                ->where('kind', QuizKind::Attestation))
            ->count();

        if ($waiting === 0) {
            return;
        }

        throw ValidationException::withMessages([
            'kind' => sprintf(
                'Сперва разберите очередь — сданных работ ждёт ответа: %d. Пока они не проверены, сделать аттестацию обычным тестом нельзя: работы пропадут из очереди, а материал у сдавших так и не зачтётся.',
                $waiting,
            ),
        ]);
    }

    /**
     * Вопрос: тот же, что был, или новый.
     *
     * Свой ли это вопрос, спрашивается у уже загруженного теста, а не у базы:
     * номер приходит из браузера, и чужой вопрос по нему подставить нельзя —
     * незнакомый номер просто заводит новый.
     *
     * @param  array{id?: ?int, text: string, type: string, points: int, expected_answer?: ?string, table?: ?array<string, mixed>, options?: array<int, array{id?: ?int, text: string, is_correct: bool}>}  $data
     */
    private function question(Quiz $quiz, array $data, int $position): QuizQuestion
    {
        $type = QuestionType::from($data['type']);

        $attributes = [
            'text' => $data['text'],
            'type' => $type,
            // Эталон только у письменного вопроса: у выбора он был бы вторым
            // ключом рядом с отмеченными вариантами.
            'expected_answer' => $type->isWritten()
                ? trim((string) ($data['expected_answer'] ?? ''))
                : null,
            // Устройство таблицы — только у таблицы; у прочих видов оно было бы
            // формой, которую никто не рисует.
            'table_definition' => $type->isTable()
                ? QuestionTable::normalise($data['table'] ?? [])
                : null,
            'points' => $data['points'],
            'position' => $position,
        ];

        $known = ($data['id'] ?? null) === null
            ? null
            : $quiz->questions->firstWhere('id', $data['id']);

        $question = $known instanceof QuizQuestion
            ? tap($known)->update($attributes)
            : $quiz->questions()->create($attributes);

        $this->options($question, $data['options'] ?? []);

        return $question;
    }

    /**
     * Варианты вопроса — по тем же правилам, что и сами вопросы: номер варианта
     * лежит в ответах попытки, и пересозданный вариант стирает из разбора то,
     * что человек выбрал.
     *
     * @param  array<int, array{id?: ?int, text: string, is_correct: bool}>  $options
     */
    private function options(QuizQuestion $question, array $options): void
    {
        $question->loadMissing('options');

        $kept = [];

        foreach (array_values($options) as $position => $data) {
            $attributes = [
                'text' => $data['text'],
                'is_correct' => $data['is_correct'],
                'position' => $position,
            ];

            $known = ($data['id'] ?? null) === null
                ? null
                : $question->options->firstWhere('id', $data['id']);

            $kept[] = $known instanceof QuizOption
                ? tap($known)->update($attributes)->getKey()
                : $question->options()->create($attributes)->getKey();
        }

        $question->options()->whereNotIn('id', $kept)->delete();
    }
}
