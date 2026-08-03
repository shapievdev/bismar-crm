<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\Role as RoleEnum;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonAttachment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

/**
 * Attachments live on S3. These tests run against a faked disk, so they need no
 * bucket or credentials — but they do exercise the same disk name the
 * application writes to in production.
 */
final class LessonAttachmentTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function test_an_author_can_upload_a_file_to_s3(): void
    {
        $lesson = $this->lesson();

        $response = $this->actingAs($this->author())
            ->postJson(route('lms.attachments.store', $lesson), [
                'file' => UploadedFile::fake()->create('регламент.pdf', 120, 'application/pdf'),
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'регламент.pdf');

        $attachment = LessonAttachment::query()->sole();

        $this->assertSame('s3', $attachment->disk);
        Storage::disk('s3')->assertExists($attachment->path);

        // The stored key is generated, never taken from the client's filename.
        $this->assertStringNotContainsString('регламент', $attachment->path);
        $this->assertStringStartsWith("lessons/{$lesson->id}/", $attachment->path);
        $this->assertNotEmpty($response->json('data.url'));
    }

    public function test_an_oversized_file_is_rejected(): void
    {
        $maxKb = (int) config('lms.attachment_max_kb');

        $this->actingAs($this->author())
            ->postJson(route('lms.attachments.store', $this->lesson()), [
                'file' => UploadedFile::fake()->create('big.pdf', $maxKb + 1, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        $this->assertSame(0, LessonAttachment::query()->count());
    }

    public function test_a_file_type_outside_the_allow_list_is_rejected(): void
    {
        $this->actingAs($this->author())
            ->postJson(route('lms.attachments.store', $this->lesson()), [
                'file' => UploadedFile::fake()->create('shell.php', 4, 'application/x-php'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');

        Storage::disk('s3')->assertDirectoryEmpty('/');
    }

    public function test_deleting_an_attachment_removes_the_stored_object(): void
    {
        $lesson = $this->lesson();
        $author = $this->author();

        $this->actingAs($author)
            ->postJson(route('lms.attachments.store', $lesson), [
                'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertCreated();

        $attachment = LessonAttachment::query()->sole();

        $this->actingAs($author)
            ->deleteJson(route('lms.attachments.destroy', $attachment))
            ->assertNoContent();

        $this->assertSame(0, LessonAttachment::query()->count());
        Storage::disk('s3')->assertMissing($attachment->path);
    }

    public function test_a_learner_cannot_upload(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.attachments.store', $this->lesson()), [
                'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_a_learner_sees_attachments_on_the_lesson(): void
    {
        $lesson = $this->lesson();
        $course = $lesson->module->course;
        $learner = $this->learner();

        $this->actingAs($this->author())
            ->postJson(route('lms.attachments.store', $lesson), [
                'file' => UploadedFile::fake()->create('notes.pdf', 10, 'application/pdf'),
            ])->assertCreated();

        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->actingAs($learner)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonPath('data.attachments.0.name', 'notes.pdf');
    }

    public function test_an_author_can_upload_a_lesson_video(): void
    {
        $lesson = $this->lesson();

        $this->actingAs($this->author())
            ->postJson(route('lms.video.store', $lesson), [
                'video' => UploadedFile::fake()->create('урок.mp4', 2048, 'video/mp4'),
            ])
            ->assertOk()
            ->assertJsonPath('data.video_name', 'урок.mp4');

        $stored = $lesson->refresh();

        $this->assertSame('s3', $stored->video_disk);
        Storage::disk('s3')->assertExists($stored->video_path);
        // The object key is generated, never taken from the client's filename.
        $this->assertStringStartsWith("lessons/{$lesson->id}/video/", $stored->video_path);
    }

    public function test_replacing_a_video_removes_the_previous_object(): void
    {
        $lesson = $this->lesson();
        $author = $this->author();

        $this->actingAs($author)->postJson(route('lms.video.store', $lesson), [
            'video' => UploadedFile::fake()->create('first.mp4', 512, 'video/mp4'),
        ])->assertOk();

        $first = $lesson->refresh()->video_path;

        $this->actingAs($author)->postJson(route('lms.video.store', $lesson), [
            'video' => UploadedFile::fake()->create('second.mp4', 512, 'video/mp4'),
        ])->assertOk();

        $second = $lesson->refresh()->video_path;

        $this->assertNotSame($first, $second);
        Storage::disk('s3')->assertMissing($first);
        Storage::disk('s3')->assertExists($second);
    }

    public function test_a_non_video_file_is_refused_as_a_video(): void
    {
        $this->actingAs($this->author())
            ->postJson(route('lms.video.store', $this->lesson()), [
                'video' => UploadedFile::fake()->create('notes.pdf', 64, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('video');
    }

    public function test_deleting_a_video_removes_it_from_storage(): void
    {
        $lesson = $this->lesson();
        $author = $this->author();

        $this->actingAs($author)->postJson(route('lms.video.store', $lesson), [
            'video' => UploadedFile::fake()->create('clip.mp4', 512, 'video/mp4'),
        ])->assertOk();

        $path = $lesson->refresh()->video_path;

        $this->actingAs($author)->deleteJson(route('lms.video.destroy', $lesson))->assertOk();

        $this->assertNull($lesson->refresh()->video_path);
        Storage::disk('s3')->assertMissing($path);
    }

    public function test_a_learner_cannot_upload_a_video(): void
    {
        $this->actingAs($this->learner())
            ->postJson(route('lms.video.store', $this->lesson()), [
                'video' => UploadedFile::fake()->create('clip.mp4', 128, 'video/mp4'),
            ])
            ->assertForbidden();
    }

    public function test_documents_beyond_the_original_list_are_accepted(): void
    {
        $lesson = $this->lesson();
        $author = $this->author();

        foreach ([['note.rtf', 'application/rtf'], ['sheet.ods', 'application/vnd.oasis.opendocument.spreadsheet']] as [$name, $mime]) {
            $this->actingAs($author)
                ->postJson(route('lms.attachments.store', $lesson), [
                    'file' => UploadedFile::fake()->create($name, 32, $mime),
                ])
                ->assertCreated();
        }

        $this->assertSame(2, LessonAttachment::query()->count());
    }

    public function test_svg_is_refused_because_it_can_carry_script(): void
    {
        $this->actingAs($this->author())
            ->postJson(route('lms.attachments.store', $this->lesson()), [
                'file' => UploadedFile::fake()->create('logo.svg', 8, 'image/svg+xml'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('file');
    }

    private function lesson(): Lesson
    {
        return Course::factory()->withLessons(1)->create()->lessons()->firstOrFail();
    }

    private function learner(): User
    {
        return User::factory()->create()->assignRole(RoleEnum::Viewer->value);
    }

    private function author(): User
    {
        return User::factory()->create()->assignRole(RoleEnum::Manager->value);
    }
}
