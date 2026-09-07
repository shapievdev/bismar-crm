<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\MaterialKind;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\Regulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Документы и справочники, приложенные к уроку.
 *
 * Урок объясняет, как делать, и в конце отправляет читателя к правилу — «а
 * теперь то же самое в кассовой дисциплине». Проверяется то, что из кода не
 * следует само собой: список односторонний, держит заданный порядок, не знает
 * границы разделов и не выдаёт читателю ни черновик, ни чужое закрытое правило.
 */
final class LessonMaterialsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function lessonOf(Course $course): Lesson
    {
        return $course->lessons()->firstOrFail();
    }

    public function test_an_attached_document_shows_up_in_the_lesson(): void
    {
        $author = $this->author();
        $course = Course::factory()->withLessons(1)->create(['author_id' => $author->getKey()]);
        $lesson = $this->lessonOf($course);
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        $this->actingAs($author)
            ->putJson(route('lms.materials.update', $lesson), ['documents' => [$document->getKey()]])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Кассовая дисциплина');

        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonPath('data.materials.0.title', 'Кассовая дисциплина')
            ->assertJsonPath('data.materials.0.path', '/lms/documents/'.$document->slug);
    }

    /**
     * Раздел не важен: урок отправляет к ответу, а не по своему разделу.
     */
    public function test_a_handbook_may_be_attached_too(): void
    {
        $author = $this->author();
        $course = Course::factory()->withLessons(1)->create(['author_id' => $author->getKey()]);
        $lesson = $this->lessonOf($course);
        $handbook = Regulation::factory()->published()->create([
            'kind' => MaterialKind::Handbook,
            'title' => 'Где поесть в смену',
        ]);

        $this->actingAs($author)
            ->putJson(route('lms.materials.update', $lesson), ['documents' => [$handbook->getKey()]])
            ->assertOk();

        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonPath('data.materials.0.kind', 'handbook')
            ->assertJsonPath('data.materials.0.path', '/lms/handbooks/'.$handbook->slug);
    }

    /** Порядок задают руками: его читают сверху вниз под статьёй. */
    public function test_the_list_keeps_the_order_it_was_given(): void
    {
        $author = $this->author();
        $course = Course::factory()->withLessons(1)->create(['author_id' => $author->getKey()]);
        $lesson = $this->lessonOf($course);
        [$first, $second, $third] = Regulation::factory()->count(3)->published()->create()->all();

        $this->actingAs($author)
            ->putJson(route('lms.materials.update', $lesson), [
                'documents' => [$third->getKey(), $first->getKey(), $second->getKey()],
            ])
            ->assertOk()
            ->assertJsonPath('data.0.id', $third->id)
            ->assertJsonPath('data.2.id', $second->id);

        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonPath('data.materials.0.id', $third->id)
            ->assertJsonPath('data.materials.2.id', $second->id);
    }

    /**
     * Связь односторонняя: у приложенного документа от этого ничего не
     * появляется — ни в соседях, ни в частых вопросах.
     */
    public function test_attaching_does_not_answer_back(): void
    {
        $author = $this->author();
        $course = Course::factory()->withLessons(1)->create(['author_id' => $author->getKey()]);
        $document = Regulation::factory()->published()->create();

        $this->actingAs($author)
            ->putJson(route('lms.materials.update', $this->lessonOf($course)), [
                'documents' => [$document->getKey()],
            ])
            ->assertOk();

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonCount(0, 'data.related')
            ->assertJsonCount(0, 'data.questions');
    }

    /** Черновик читателю не показывают: ссылка вела бы туда, куда не пустят. */
    public function test_a_draft_stays_out_of_the_readers_list(): void
    {
        $author = $this->author();
        $course = Course::factory()->withLessons(1)->create(['author_id' => $author->getKey()]);
        $lesson = $this->lessonOf($course);
        $draft = Regulation::factory()->create(['title' => 'Ещё пишется']);
        $published = Regulation::factory()->published()->create(['title' => 'Готовое']);

        $this->actingAs($author)
            ->putJson(route('lms.materials.update', $lesson), [
                'documents' => [$draft->getKey(), $published->getKey()],
            ])
            ->assertOk()
            // Редактор видит оба: черновик в его списке стоять может.
            ->assertJsonCount(2, 'data');

        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonCount(1, 'data.materials')
            ->assertJsonPath('data.materials.0.title', 'Готовое');
    }

    /** Подсказка предлагает оба раздела и не предлагает уже приложенное. */
    public function test_candidates_come_from_both_sections(): void
    {
        $author = $this->author();
        $course = Course::factory()->withLessons(1)->create(['author_id' => $author->getKey()]);
        $lesson = $this->lessonOf($course);

        $handbook = Regulation::factory()->published()->create([
            'kind' => MaterialKind::Handbook,
            'title' => 'Нужна фирменная футболка',
        ]);
        $attached = Regulation::factory()->published()->create(['title' => 'Нужна печать']);

        $this->actingAs($author)
            ->putJson(route('lms.materials.update', $lesson), ['documents' => [$attached->getKey()]])
            ->assertOk();

        $found = $this->actingAs($author)
            ->getJson(route('lms.materials.candidates', [$lesson, 'search' => 'нужна']))
            ->assertOk()
            ->json('data');

        $this->assertSame([$handbook->id], array_column($found, 'id'));
    }

    /** Список ведёт тот, кто правит курс. */
    public function test_a_reader_cannot_attach_anything(): void
    {
        $course = Course::factory()->withLessons(1)->create();

        $this->actingAs($this->learner())
            ->putJson(route('lms.materials.update', $this->lessonOf($course)), [
                'documents' => [Regulation::factory()->published()->create()->getKey()],
            ])
            ->assertForbidden();
    }

    /** Пустой список — это «убрать все», а не «поле не прислали». */
    public function test_an_empty_list_clears_the_block(): void
    {
        $author = $this->author();
        $course = Course::factory()->withLessons(1)->create(['author_id' => $author->getKey()]);
        $lesson = $this->lessonOf($course);

        $this->actingAs($author)
            ->putJson(route('lms.materials.update', $lesson), [
                'documents' => [Regulation::factory()->published()->create()->getKey()],
            ])
            ->assertOk();

        $this->actingAs($author)
            ->putJson(route('lms.materials.update', $lesson), ['documents' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }
}
