<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class LessonContentPreservationTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_reordering_and_renaming_never_erase_the_article_or_video_metadata(): void
    {
        $lesson = Course::factory()->withLessons(1)->create()->modules->first()->lessons->first();
        $document = ['type' => 'doc', 'content' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Текст урока']]],
            ['type' => 'videoEmbed', 'attrs' => ['provider' => 'file', 'attachmentId' => 40]],
        ]];
        $lesson->update(['content_json' => $document, 'content' => 'Текст урока', 'video_url' => 'https://example.com/video.mp4', 'duration_minutes' => 15]);
        $document = $lesson->fresh()->content_json;
        $this->actingAs($this->author());
        foreach ([['position' => 3], ['position' => 2, 'content' => null], []] as $changes) {
            $this->putJson(route('lms.lessons.update', $lesson), ['title' => 'Название урока', ...$changes])->assertOk();
            $lesson->refresh();
            $this->assertSame($document, $lesson->content_json);
            $this->assertSame('Текст урока', $lesson->content);
            $this->assertSame('https://example.com/video.mp4', $lesson->video_url);
            $this->assertSame(15, $lesson->duration_minutes);
        }
    }

    public function test_explicit_article_edits_and_clearing_still_work(): void
    {
        $lesson = Course::factory()->withLessons(1)->create()->modules->first()->lessons->first();
        $this->actingAs($this->author())->putJson(route('lms.lessons.update', $lesson), [
            'title' => $lesson->title, 'content_json' => ['type' => 'doc', 'content' => [
                ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Новый текст']]],
            ]],
        ])->assertOk();
        $this->assertSame('Новый текст', $lesson->fresh()->content);
        $this->putJson(route('lms.lessons.update', $lesson), ['title' => $lesson->title, 'content_json' => null, 'content' => null])->assertOk();
        $this->assertNull($lesson->fresh()->content_json);
        $this->assertNull($lesson->fresh()->content);
    }
}
