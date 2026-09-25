<?php

declare(strict_types=1);

namespace App\Actions\Lms;

use App\Enums\AttestationStatus;
use App\Enums\QuestionType;
use App\Enums\QuizKind;
use App\Jobs\SendPush;
use App\Models\Lesson;
use App\Models\Quiz;
use App\Models\QuizAttempt;
use App\Models\QuizOption;
use App\Models\QuizQuestion;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Support\Lms\MaterialLink;
use App\Support\Lms\QuestionTable;
use App\Support\Push\PushMessage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class SaveQuiz
{
    public function __construct(private CreditAttempt $credit) {}

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
     * Смена вида теста разбирается здесь же и целиком: аттестация, ставшая
     * обычным тестом, не бросает сданное на полпути — см. waitingWork().
     *
     * @param  array{
     *     title: string,
     *     description?: ?string,
     *     max_attempts?: ?int,
     *     kind?: string,
     *     examiner_id?: ?int,
     *     questions: array<int, array{id?: ?int, text: string, type: string, points: int, expected_answer?: ?string, table?: ?array<string, mixed>, options?: array<int, array{id?: ?int, text: string, is_correct: bool}>}>
     * } $attributes
     */
    public function handle(Lesson|Regulation|RegulationVersion $owner, array $attributes): Quiz
    {
        $existing = Quiz::query()
            ->where('quizzable_type', $owner->getMorphClass())
            ->where('quizzable_id', $owner->getKey())
            ->first();

        [$kind, $examiner] = $this->reviewerOf($existing, $attributes);

        $stranded = $this->waitingWork($existing, $kind);

        $quiz = DB::transaction(function () use ($owner, $attributes, $kind, $examiner, $stranded): Quiz {
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

            $this->settle($quiz, $stranded);

            return $quiz->load('questions.options');
        });

        $this->tellThemItWasGraded($quiz, $stranded);

        return $quiz;
    }

    /**
     * Работы, оставшиеся без проверяющего: аттестацию сделали обычным тестом, а
     * их ещё не разобрали.
     *
     * Бросать их нельзя — именно так они и пропадали: очередь ищет работы по
     * `examiner_id`, и вместе с видом теста обнулялся он, а сданное оставалось
     * лежать в базе «на проверке» навсегда (боевой, 2026-09-25). Но и запрещать
     * перевод незачем: работа, которую некому больше читать, не пропала — её
     * просто оценивает теперь приложение, как всякую сдачу обычного теста.
     *
     * @return Collection<int, QuizAttempt>
     */
    private function waitingWork(?Quiz $existing, QuizKind $kind): Collection
    {
        if ($existing === null || $kind->isAttestation() || ! $existing->isAttestation()) {
            return new Collection;
        }

        return $existing->attempts()
            ->where('review_status', AttestationStatus::Pending)
            ->with('user')
            ->get();
    }

    /**
     * Приговор по тому, что приложение уже посчитало.
     *
     * Баллы у аттестации считаются в момент сдачи и лежат в попытке: решения по
     * ним приложение не выносило, но справку проверяющему готовило. Теперь по
     * этой же справке выносится и решение — пересчитывать нечего, да и не по
     * чему: вопросы к этой минуте уже переписаны, а ответы разложены по прежним.
     *
     * @param  Collection<int, QuizAttempt>  $stranded
     */
    private function settle(Quiz $quiz, Collection $stranded): void
    {
        foreach ($stranded as $attempt) {
            $attempt->fill([
                'review_status' => AttestationStatus::Auto,
                'passed' => $attempt->score >= $quiz->passing_score,
            ])->save();

            if ($attempt->passed) {
                $this->credit->handle($attempt);
            }
        }
    }

    /**
     * Говорит сдавшим, что ответа больше можно не ждать.
     *
     * Они отправили работу человеку и ждут его слова — иногда неделями. Сменить
     * правила молча значит оставить их ждать того, чего не будет: пусть узнают
     * тем же путём, каким узнали бы вердикт. См. ReviewAttestation.
     *
     * @param  Collection<int, QuizAttempt>  $stranded
     */
    private function tellThemItWasGraded(Quiz $quiz, Collection $stranded): void
    {
        if ($stranded->isEmpty()) {
            return;
        }

        $material = MaterialLink::for($quiz->loadMissing('quizzable')->quizzable);

        foreach ($stranded as $attempt) {
            $learner = $attempt->user;

            if ($learner === null || $learner->dismissed_at !== null) {
                continue;
            }

            SendPush::dispatch([(int) $learner->getKey()], new PushMessage(
                title: $attempt->passed ? 'Работа зачтена' : 'Работа не зачтена',
                body: PushMessage::shorten(sprintf(
                    'Проверку теперь делает приложение%s. Ваш результат: %d%%.',
                    $material === null ? '' : ' — '.$material->caption(),
                    $attempt->score,
                )),
                url: $material?->url ?? '/lms/plan',
                tag: sprintf('attestation-verdict-%d', $attempt->getKey()),
            ));
        }
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
