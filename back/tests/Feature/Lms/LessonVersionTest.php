<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\AttachmentSource;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Group;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\LessonCompletion;
use App\Models\MaterialVersion;
use App\Models\Quiz;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Версии урока курса (решение пользователя 2026-09-25).
 *
 * Тот же урок, рассказанный своим людям: у магазина своя запись и свой бланк, у
 * офиса свои, а курс, его модули и порядок уроков — общие. Общая версия — сам
 * урок: у кого группы ни с одной не совпали, тот смотрит его так же, как
 * смотрел до всякого разделения.
 *
 * Правила здесь те же, что у версий документа (см. MaterialVersionTest), и
 * проверяются они заново не из недоверия к ним, а потому, что у урока своё: он
 * зачитывается прохождением, а не ознакомлением, и права у него курса.
 */
final class LessonVersionTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Какая версия открывается ---------- */

    public function test_a_version_opens_first_for_the_group_it_was_written_for(): void
    {
        $lesson = $this->lesson();

        [$retail, $salesman] = $this->groupWithPerson('Розница');

        $version = $this->version($lesson, 'Для магазина', [$retail], text: 'Как принимать товар в зале');

        $response = $this->actingAs($salesman)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk();

        $this->assertSame($version->id, $response->json('data.version.id'));
        $this->assertSame('Для магазина', $response->json('data.version.name'));
        $this->assertTrue($response->json('data.version.is_mine'));
        $this->assertSame('Как принимать товар в зале', $this->textOf($response->json('data.version.content_json')));

        // Кому ни одна версия не адресована — смотрит общий урок, и «version»
        // ему не присылают вовсе.
        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonMissingPath('data.version')
            ->assertJsonCount(1, 'data.versions');
    }

    /** Закрытая версия не видна даже названием: её и в переключателе нет. */
    public function test_a_private_version_stays_out_of_a_strangers_switcher(): void
    {
        $lesson = $this->lesson();

        [$office] = $this->groupWithPerson('Офис');

        $this->version($lesson, 'Для офиса', [$office], private: true);

        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonCount(0, 'data.versions');
    }

    /** Открытую чужую версию посмотреть можно: иногда за этим и приходят. */
    public function test_an_open_version_stays_in_the_switcher_for_everyone(): void
    {
        $lesson = $this->lesson();

        [$office] = $this->groupWithPerson('Офис');

        $version = $this->version($lesson, 'Для офиса', [$office]);

        $stranger = $this->learner();

        $this->actingAs($stranger)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonCount(1, 'data.versions')
            ->assertJsonPath('data.versions.0.is_mine', false);

        $this->actingAs($stranger)
            ->getJson(route('lms.lessons.versions.show', [$lesson, $version]))
            ->assertOk()
            ->assertJsonPath('data.name', 'Для офиса');
    }

    /* ---------- Кто их ведёт ---------- */

    public function test_versions_are_kept_by_whoever_runs_the_course(): void
    {
        $lesson = $this->lesson();
        [$retail] = $this->groupWithPerson('Розница');

        $this->actingAs($this->author())
            ->postJson(route('lms.lessons.versions.store', $lesson), [
                'name' => 'Для магазина',
                'is_private' => false,
                'groups' => [$retail->id],
                'departments' => [],
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Для магазина');

        $this->actingAs($this->author())
            ->getJson(route('lms.lessons.versions.index', $lesson))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Версия без адресата ничья: она никому не откроется первой. */
    public function test_a_version_without_an_audience_is_refused(): void
    {
        $this->actingAs($this->author())
            ->postJson(route('lms.lessons.versions.store', $this->lesson()), [
                'name' => 'Ничья',
                'is_private' => false,
                'groups' => [],
                'departments' => [],
            ])
            ->assertJsonValidationErrorFor('groups');
    }

    /** Чужим уроком свою версию не открыть — тот же случай, что её отсутствие. */
    public function test_a_version_of_another_lesson_is_not_found(): void
    {
        $lesson = $this->lesson();
        $other = $this->lesson();

        [$retail] = $this->groupWithPerson('Розница');
        $version = $this->version($lesson, 'Для магазина', [$retail]);

        $this->actingAs($this->author())
            ->getJson(route('lms.lessons.versions.show', [$other, $version]))
            ->assertNotFound();
    }

    /* ---------- Что у версии своё ---------- */

    /**
     * Проверка при версии своя, и сдают её по своей.
     *
     * Урок при этом закрывается один раз: отметка одна на человека, а версия
     * остаётся на ней пометкой — см. CompleteLesson.
     */
    public function test_passing_the_version_quiz_completes_the_lesson(): void
    {
        $lesson = $this->lesson();

        [$retail, $salesman] = $this->groupWithPerson('Розница');

        $version = $this->version($lesson, 'Для магазина', [$retail]);
        $quiz = Quiz::factory()->withQuestions(1)->forVersion($version)->create();

        $this->actingAs($salesman)
            ->postJson(route('lms.lessons.versions.quiz.submit', [$lesson, $version]), [
                'answers' => $this->answers($quiz),
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true);

        $completion = LessonCompletion::query()->sole();

        $this->assertSame($lesson->id, (int) $completion->lesson_id);
        $this->assertSame(
            $version->id,
            (int) $completion->version_id,
            'Версия не отмечена на прохождении — непонятно, что именно человек прошёл.',
        );
    }

    /**
     * Общий тест урока не закрывает урок тому, у кого своя версия.
     *
     * Иначе «пройдено» означало бы «сдал не свой бланк»: спрашивать с розницы
     * по вопросам офиса и есть то, ради чего версии заводят.
     */
    public function test_the_common_quiz_does_not_settle_a_learner_with_their_own_version(): void
    {
        $lesson = $this->lesson();

        [$retail, $salesman] = $this->groupWithPerson('Розница');

        $version = $this->version($lesson, 'Для магазина', [$retail]);
        Quiz::factory()->withQuestions(1)->forVersion($version)->create();

        $enrollment = $this->enrol($salesman, $lesson);

        $this->actingAs($salesman)
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertConflict();

        $this->assertSame(0, $enrollment->completions()->count());
    }

    /** Закрытую чужую версию не сдать: текст, которого не показывали. */
    public function test_a_stranger_cannot_sit_the_quiz_of_a_private_version(): void
    {
        $lesson = $this->lesson();

        [$office] = $this->groupWithPerson('Офис');

        $version = $this->version($lesson, 'Для офиса', [$office], private: true);
        $quiz = Quiz::factory()->withQuestions(1)->forVersion($version)->create();

        $this->actingAs($this->learner())
            ->postJson(route('lms.lessons.versions.quiz.submit', [$lesson, $version]), [
                'answers' => $this->answers($quiz),
            ])
            ->assertNotFound();
    }

    /** Файл версии — свой у каждой; в общем списке урока его нет. */
    public function test_a_version_keeps_its_own_files(): void
    {
        $lesson = $this->lesson();
        [$retail, $salesman] = $this->groupWithPerson('Розница');

        $version = $this->version($lesson, 'Для магазина', [$retail]);

        // Файл с Google Диска: у него нет объекта в хранилище, и подписывать
        // адрес незачем — проверяется здесь не выдача файла, а то, чей он.
        $version->lessonAttachments()->create([
            'lesson_id' => $lesson->id,
            'name' => 'Бланк магазина.xlsx',
            'size' => 0,
            'source' => AttachmentSource::GoogleDrive,
            'external_id' => 'drive-file-1',
        ]);

        $this->actingAs($salesman)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            // Общий список файлов урока остаётся общим.
            ->assertJsonCount(0, 'data.attachments')
            ->assertJsonCount(1, 'data.version.attachments')
            ->assertJsonPath('data.version.attachments.0.name', 'Бланк магазина.xlsx');
    }

    /**
     * Файл, загруженный на адрес версии, ложится при версии, а не при уроке.
     *
     * Здесь и была поломка (2026-09-30): запрос приходил на адрес версии и
     * отвечал 201, а `version_id` терялся по дороге — его не было в списке
     * заполняемых полей LessonAttachment, и Eloquent выбрасывал его молча. Файл
     * ложился общим, и рознице был виден бланк офиса.
     *
     * Прежние тесты этого не ловили: они заводили файл версии связью
     * (`$version->lessonAttachments()->create(...)`), а связь проставляет ключ
     * сама, мимо массового заполнения. **Проверять такое надо маршрутом.**
     */
    public function test_a_file_uploaded_to_a_version_belongs_to_that_version(): void
    {
        Storage::fake('s3');

        $lesson = $this->lesson();
        [$retail, $salesman] = $this->groupWithPerson('Розница');

        $version = $this->version($lesson, 'Для магазина', [$retail]);

        $this->actingAs($this->author())
            ->postJson(route('lms.lessons.versions.attachments.store', [$lesson, $version]), [
                'file' => UploadedFile::fake()->create('бланк розницы.pdf', 40, 'application/pdf'),
            ])
            ->assertCreated();

        $attachment = LessonAttachment::query()->sole();

        $this->assertSame($version->id, $attachment->version_id);

        // И на экране он у версии, а не в общем списке урока.
        $this->actingAs($salesman)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonCount(0, 'data.attachments')
            ->assertJsonCount(1, 'data.version.attachments')
            ->assertJsonPath('data.version.attachments.0.name', 'бланк розницы.pdf');
    }

    /** Файл с Google Диска — тем же путём и с тем же ключом версии. */
    public function test_a_drive_file_attached_to_a_version_belongs_to_that_version(): void
    {
        $lesson = $this->lesson();
        $version = $this->version($lesson, 'Для офиса', []);

        $this->actingAs($this->author())
            ->postJson(route('lms.lessons.versions.attachments.drive', [$lesson, $version]), [
                'external_id' => 'drive-file-7',
                'name' => 'Бланк офиса.xlsx',
                'mime_type' => 'application/vnd.ms-excel',
            ])
            ->assertCreated();

        $this->assertSame($version->id, LessonAttachment::query()->sole()->version_id);
    }

    /* ---------- helpers ---------- */

    private function lesson(): Lesson
    {
        return Course::factory()->published()->withLessons(1)->create()->lessons()->firstOrFail();
    }

    /**
     * @return array{0: Group, 1: User}
     */
    private function groupWithPerson(string $name): array
    {
        $group = Group::factory()->create(['name' => $name]);
        $person = $this->learner();

        $group->members()->attach($person);

        return [$group, $person];
    }

    /**
     * @param  list<Group>  $groups
     */
    private function version(
        Lesson $lesson,
        string $name,
        array $groups,
        bool $private = false,
        ?string $text = null,
    ): MaterialVersion {
        /** @var MaterialVersion $version */
        $version = $lesson->versions()->create([
            'name' => $name,
            'is_private' => $private,
            'position' => $lesson->versions()->count() + 1,
            'content_json' => $text === null ? null : $this->article($text),
        ]);

        $version->groups()->sync(array_map(static fn (Group $group): int => (int) $group->id, $groups));

        return $version;
    }

    private function enrol(User $learner, Lesson $lesson): Enrollment
    {
        $course = $lesson->owningCourse();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        return $course->enrollments()->where('user_id', $learner->getKey())->sole();
    }

    /**
     * @return array<string, mixed>
     */
    private function article(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>|null  $document
     */
    private function textOf(?array $document): string
    {
        return (string) ($document['content'][0]['content'][0]['text'] ?? '');
    }

    /**
     * @return array<int, list<int>>
     */
    private function answers(Quiz $quiz): array
    {
        return $quiz->questions()->with('options')->get()
            ->mapWithKeys(fn ($question): array => [
                $question->id => [$question->options->firstWhere('is_correct', true)->id],
            ])->all();
    }
}
