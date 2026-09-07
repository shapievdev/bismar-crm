<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseStatus;
use App\Enums\CourseVisibility;
use App\Models\Category;
use App\Models\Course;
use App\Models\Regulation;
use App\Support\Lms\Keywords;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Ключевые слова: их пишут ради тех, кто ищет не теми словами, какими написан
 * материал.
 *
 * Поисковика в тестах нет — см. phpunit.xml. Здесь проверяется всё, что от него
 * не зависит: как слова хранятся, как приводятся к порядку и как по ним ищет
 * запасной путь, который работает и в жизни, когда Meilisearch не поднят.
 * Обращение к самому поисковику — в CatalogSearchTest.
 */
final class KeywordSearchTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Курс ---------- */

    public function test_a_course_is_found_by_a_word_that_is_not_in_its_text(): void
    {
        Course::factory()->published()->create([
            'title' => 'Работа с кассой',
            'summary' => 'Как принимать оплату.',
            'description' => 'Смена, инкассация, отчёт.',
            'keywords' => ['ККМ', 'пересорт'],
        ]);

        Course::factory()->published()->create(['title' => 'Онбординг', 'keywords' => []]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'ккм']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Работа с кассой');
    }

    public function test_keywords_are_saved_and_come_back_with_the_course(): void
    {
        $author = $this->author();

        $response = $this->actingAs($author)
            ->postJson(route('lms.courses.store'), [
                'title' => 'Работа с кассой',
                'category_id' => Category::factory()->create()->id,
                'status' => CourseStatus::Draft->value,
                'keywords' => ['ККМ', 'касса'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.keywords', ['ККМ', 'касса']);

        $course = Course::query()->findOrFail($response->json('data.id'));

        $this->assertSame(['ККМ', 'касса'], $course->keywords);

        // Пустой список — это «слов больше нет», а не «поля не прислали».
        $this->actingAs($author)
            ->putJson(route('lms.courses.update', $course), [
                'title' => 'Работа с кассой',
                'category_id' => $course->category_id,
                'status' => CourseStatus::Draft->value,
                'keywords' => [],
            ])
            ->assertOk()
            ->assertJsonPath('data.keywords', []);
    }

    /**
     * Форма курса — не единственный, кто его сохраняет: поле может и не прийти.
     */
    public function test_saving_without_the_field_leaves_the_words_alone(): void
    {
        $author = $this->author();
        $course = Course::factory()->create(['author_id' => $author->getKey(), 'keywords' => ['ККМ']]);

        $this->actingAs($author)
            ->putJson(route('lms.courses.update', $course), [
                'title' => $course->title,
                'category_id' => $course->category_id,
                'status' => $course->status->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.keywords', ['ККМ']);
    }

    public function test_a_closed_course_is_not_findable_by_its_keyword_from_outside(): void
    {
        Course::factory()->published()->create([
            'title' => 'Наценка по сегментам',
            'keywords' => ['скидка'],
            'visibility' => CourseVisibility::Private,
        ]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'скидка']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ---------- Документ ---------- */

    public function test_a_document_is_found_by_a_keyword(): void
    {
        $author = $this->author();

        $document = Regulation::factory()->published()->create([
            'title' => 'Возврат товара',
            'author_id' => $author->getKey(),
        ]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.update', $document), [
                'title' => 'Возврат товара',
                'category_id' => $document->category_id,
                'status' => CourseStatus::Published->value,
                'visibility' => CourseVisibility::Public->value,
                'keywords' => ['пересорт', 'брак'],
            ])
            ->assertOk()
            ->assertJsonPath('data.keywords', ['пересорт', 'брак']);

        $this->actingAs($this->learner())
            ->getJson(route('lms.documents.index', ['search' => 'ПЕРЕСОРТ']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Возврат товара');
    }

    public function test_a_document_keeps_its_words_when_the_field_is_absent(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create([
            'author_id' => $author->getKey(),
            'keywords' => ['пересорт'],
        ]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.update', $document), [
                'title' => $document->title,
                'category_id' => $document->category_id,
                'status' => $document->status->value,
                'visibility' => $document->visibility->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.keywords', ['пересорт']);
    }

    /* ---------- Приведение к порядку ---------- */

    public function test_the_list_is_trimmed_deduplicated_and_capped(): void
    {
        $clean = Keywords::clean([
            '  касса  ',
            'Касса',
            '',
            'холодная   вода',
            123,
            str_repeat('я', Keywords::MAX_LENGTH + 1),
        ]);

        $this->assertSame(['касса', 'холодная вода'], $clean);
        $this->assertCount(Keywords::LIMIT, Keywords::clean(
            array_map(static fn (int $n): string => 'слово '.$n, range(1, Keywords::LIMIT + 5)),
        ));
    }

    public function test_the_server_refuses_a_list_that_is_too_long(): void
    {
        $this->actingAs($this->author())
            ->postJson(route('lms.courses.store'), [
                'title' => 'Работа с кассой',
                'category_id' => Category::factory()->create()->id,
                'status' => CourseStatus::Draft->value,
                'keywords' => array_map(static fn (int $n): string => 'слово '.$n, range(1, Keywords::LIMIT + 1)),
            ])
            ->assertJsonValidationErrors('keywords');
    }
}
