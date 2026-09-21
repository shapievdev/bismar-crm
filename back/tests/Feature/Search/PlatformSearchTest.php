<?php

declare(strict_types=1);

namespace Tests\Feature\Search;

use App\Enums\CourseVisibility;
use App\Enums\NewsStatus;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\News;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Поиск из шапки — по всей платформе разом.
 *
 * Проверяется не то, что он что-то находит, а границы: раздел без права не даёт
 * ни находок, ни заголовка; закрытый курс, черновик и чужая новость не попадают
 * в подсказку даже названием. Подсказка — такой же способ прочитать материал,
 * как и его страница, и ошибка здесь открывает закрытое.
 */
final class PlatformSearchTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** @return array<int, array<string, mixed>> */
    private function found(User $reader, string $term): array
    {
        $response = $this->actingAs($reader)
            ->getJson(route('search', ['q' => $term]))
            ->assertOk();

        return $response->json('data');
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return list<string>
     */
    private function titlesOf(array $sections, string $kind): array
    {
        foreach ($sections as $section) {
            if ($section['kind'] === $kind) {
                return array_column($section['items'], 'title');
            }
        }

        return [];
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     * @return list<string>
     */
    private function sectionsOf(array $sections): array
    {
        return array_column($sections, 'kind');
    }

    public function test_one_question_answers_from_every_section(): void
    {
        $course = Course::factory()->published()->create(['title' => 'Возвраты в рознице']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        Lesson::factory()->create(['module_id' => $module->id, 'title' => 'Возврат без чека']);

        Regulation::factory()->published()->create(['title' => 'Правила возврата товара']);
        Regulation::factory()->published()->handbook()->create(['title' => 'Возврат: частые случаи']);

        News::factory()->create([
            'title' => 'Возвраты теперь оформляет старший смены',
            'status' => NewsStatus::Published,
            'published_at' => now(),
        ]);

        User::factory()->create(['last_name' => 'Возвратов', 'first_name' => 'Иван']);

        $reader = $this->userWith(
            Permission::ViewCourses,
            Permission::ViewDocuments,
            Permission::ViewHandbooks,
            Permission::ViewUsers,
        );

        $found = $this->found($reader, 'возврат');

        // Порядок разделов постоянный: человек привыкает, что курсы сверху, а
        // люди снизу.
        $this->assertSame(
            ['course', 'lesson', 'document', 'handbook', 'news', 'person'],
            $this->sectionsOf($found),
        );

        $this->assertSame(['Возвраты в рознице'], $this->titlesOf($found, 'course'));
        $this->assertSame(['Возврат без чека'], $this->titlesOf($found, 'lesson'));
        $this->assertSame(['Правила возврата товара'], $this->titlesOf($found, 'document'));
        $this->assertSame(['Возврат: частые случаи'], $this->titlesOf($found, 'handbook'));
        $this->assertSame(['Возвраты теперь оформляет старший смены'], $this->titlesOf($found, 'news'));
        $this->assertSame(['Возвратов Иван'], $this->titlesOf($found, 'person'));
    }

    /** Находка ведёт на страницу материала, а не в раздел. */
    public function test_every_hit_carries_the_address_of_its_page(): void
    {
        $course = Course::factory()->published()->create(['title' => 'Кассовая смена', 'slug' => 'kassovaya-smena']);
        $module = CourseModule::factory()->create(['course_id' => $course->id]);
        $lesson = Lesson::factory()->create(['module_id' => $module->id, 'title' => 'Кассовый отчёт']);
        $document = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина', 'slug' => 'kassovaya-disciplina']);

        $found = $this->found($this->learner(), 'кассов');

        $this->assertSame('/lms/kassovaya-smena', $found[0]['items'][0]['url']);
        $this->assertSame(
            '/lms/kassovaya-smena/lessons/'.$lesson->getKey(),
            $this->urlOf($found, 'lesson'),
        );
        $this->assertSame($document->path(), $this->urlOf($found, 'document'));
    }

    /**
     * @param  array<int, array<string, mixed>>  $sections
     */
    private function urlOf(array $sections, string $kind): ?string
    {
        foreach ($sections as $section) {
            if ($section['kind'] === $kind) {
                return $section['items'][0]['url'];
            }
        }

        return null;
    }

    /**
     * Раздел без права не даёт даже заголовка.
     *
     * Пустой раздел «Документы» сообщал бы человеку, что документы в платформе
     * есть и они не для него, — а права заведены как раз затем, чтобы разделов
     * этих для него не существовало.
     */
    public function test_a_closed_section_is_absent_rather_than_empty(): void
    {
        Course::factory()->published()->create(['title' => 'Возвраты в рознице']);
        Regulation::factory()->published()->create(['title' => 'Правила возврата']);
        Regulation::factory()->published()->handbook()->create(['title' => 'Возврат: случаи']);
        User::factory()->create(['last_name' => 'Возвратов']);

        // Открыты только справочники — и ничего больше.
        $found = $this->found($this->userWith(Permission::ViewHandbooks), 'возврат');

        $this->assertSame(['handbook'], $this->sectionsOf($found));
    }

    /** Пустой раздел не показывается: «ничего не нашлось» говорится один раз. */
    public function test_a_section_with_no_hits_is_left_out(): void
    {
        Course::factory()->published()->create(['title' => 'Работа с кассой']);

        $found = $this->found($this->learner(), 'касс');

        $this->assertSame(['course'], $this->sectionsOf($found));
    }

    public function test_a_private_course_stays_out_of_the_suggestion(): void
    {
        Course::factory()->published()->create([
            'title' => 'Закрытый про возвраты',
            'visibility' => CourseVisibility::Private,
        ]);

        $this->assertSame([], $this->found($this->learner(), 'возврат'));
    }

    /**
     * Черновик виден тому, кто его пишет, и никому больше: подсказка — такой же
     * способ прочитать название, как и каталог.
     */
    public function test_a_draft_is_shown_only_to_those_who_may_edit_it(): void
    {
        Course::factory()->create(['title' => 'Черновик про возвраты']);

        $this->assertSame([], $this->found($this->learner(), 'возврат'));
        $this->assertSame(
            ['Черновик про возвраты'],
            $this->titlesOf($this->found($this->editor(), 'возврат'), 'course'),
        );
    }

    /** Урок черновика — тоже черновик: доступ решает курс. */
    public function test_a_lesson_of_a_draft_course_is_hidden_too(): void
    {
        $draft = Course::factory()->create(['title' => 'Черновик']);
        $module = CourseModule::factory()->create(['course_id' => $draft->id]);
        Lesson::factory()->create(['module_id' => $module->id, 'title' => 'Возврат без чека']);

        $this->assertSame([], $this->found($this->learner(), 'возврат'));
    }

    /**
     * Новость ищется по тому же правилу, каким её отбирает лента: неопубликованная
     * не найдётся никому.
     */
    public function test_an_unpublished_news_item_is_not_found(): void
    {
        News::factory()->create([
            'title' => 'Черновик объявления про возвраты',
            'status' => NewsStatus::Draft,
            'published_at' => null,
        ]);

        $this->assertSame([], $this->found($this->learner(), 'возврат'));
    }

    /** Люди — только тому, кому открыт список людей. */
    public function test_people_are_found_only_by_those_who_may_see_them(): void
    {
        User::factory()->create(['last_name' => 'Возвратов', 'first_name' => 'Иван', 'middle_name' => null]);

        $this->assertSame([], $this->found($this->learner(), 'возвратов'));
        $this->assertSame(
            ['Возвратов Иван'],
            $this->titlesOf($this->found($this->userWith(Permission::ViewUsers), 'возвратов'), 'person'),
        );
    }

    /** Уволенный найдётся, но скажет о себе, что он уволен. */
    public function test_a_dismissed_person_says_so(): void
    {
        User::factory()->create([
            'last_name' => 'Возвратов',
            'job_title' => 'Продавец',
            'dismissed_at' => now()->subMonth(),
        ]);

        $found = $this->found($this->userWith(Permission::ViewUsers), 'возвратов');

        $this->assertSame('Уволен · Продавец', $found[0]['items'][0]['subtitle']);
    }

    /** Забытая раскладка работает и здесь — правило одно на весь поиск. */
    public function test_the_forgotten_layout_is_forgiven_here_too(): void
    {
        Course::factory()->published()->create(['title' => 'Документооборот на складе']);

        $this->assertSame(
            ['Документооборот на складе'],
            // «ljrevtyn» — это «документ».
            $this->titlesOf($this->found($this->learner(), 'ljrevtyn'), 'course'),
        );
    }

    /** Одна буква находит всё и потому ничего: подсказка молчит. */
    public function test_a_single_letter_searches_nothing(): void
    {
        Course::factory()->published()->create(['title' => 'Возвраты']);

        $this->assertSame([], $this->found($this->learner(), 'в'));
    }

    /** «Показать все» ведёт в каталог раздела с тем же словом. */
    public function test_a_section_points_to_its_own_catalogue(): void
    {
        Course::factory()->published()->create(['title' => 'Возвраты']);
        Regulation::factory()->published()->create(['title' => 'Правила возврата']);
        User::factory()->create(['last_name' => 'Возвратов']);

        $found = $this->found(
            $this->userWith(Permission::ViewCourses, Permission::ViewDocuments, Permission::ViewUsers),
            'возврат',
        );

        $urls = array_column($found, 'more_url', 'kind');

        $this->assertSame('/lms?search=%D0%B2%D0%BE%D0%B7%D0%B2%D1%80%D0%B0%D1%82', $urls['course']);
        $this->assertSame('/lms/documents?search=%D0%B2%D0%BE%D0%B7%D0%B2%D1%80%D0%B0%D1%82', $urls['document']);
        $this->assertSame('/staff?search=%D0%B2%D0%BE%D0%B7%D0%B2%D1%80%D0%B0%D1%82', $urls['person']);
    }

    /** Больше пяти в разделе не показывается: дальше — «показать все». */
    public function test_a_section_shows_at_most_five(): void
    {
        for ($i = 1; $i <= 7; $i++) {
            Course::factory()->published()->create(['title' => "Возвраты, часть {$i}"]);
        }

        $found = $this->found($this->learner(), 'возврат');

        $this->assertCount(5, $found[0]['items']);
    }

    public function test_a_guest_searches_nothing(): void
    {
        $this->getJson(route('search', ['q' => 'возврат']))->assertUnauthorized();
    }
}
