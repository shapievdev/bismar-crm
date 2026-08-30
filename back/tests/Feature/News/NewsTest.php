<?php

declare(strict_types=1);

namespace Tests\Feature\News;

use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\News;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class NewsTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Тот, кто ведёт новости. */
    private function editor(): User
    {
        return $this->userWith(Permission::ManageNews);
    }

    /** Обычный сотрудник: новости читает, потому что вошёл. */
    private function employee(): User
    {
        return User::factory()->create();
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Переезд склада',
            'excerpt' => 'С понедельника отгрузки с новой площадки.',
            'content_json' => ['type' => 'doc', 'content' => []],
            'status' => NewsStatus::Published->value,
            'audience' => NewsAudience::Everyone->value,
        ], $overrides);
    }

    /* ---------- Кто что видит ---------- */

    public function test_a_published_news_item_reaches_everyone(): void
    {
        News::factory()->published()->create(['title' => 'Переезд склада']);

        $this->actingAs($this->employee())
            ->getJson(route('news.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Переезд склада');
    }

    public function test_a_draft_stays_out_of_the_feed(): void
    {
        News::factory()->create(['title' => 'Ещё пишется']);

        $this->actingAs($this->employee())
            ->getJson(route('news.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Черновик закрыт и от адресата: пока новость не вышла, её ещё правят.
     */
    public function test_a_draft_cannot_be_opened_by_a_reader(): void
    {
        $draft = News::factory()->create();

        $this->actingAs($this->employee())
            ->getJson(route('news.show', $draft))
            ->assertForbidden();
    }

    public function test_an_addressed_news_item_reaches_only_the_people_named(): void
    {
        $chosen = $this->employee();
        $other = $this->employee();

        $news = News::factory()->published()->addressed()->create(['title' => 'Только кассирам']);
        $news->recipients()->attach($chosen);

        $this->actingAs($chosen)
            ->getJson(route('news.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($other)
            ->getJson(route('news.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->actingAs($other)
            ->getJson(route('news.show', $news))
            ->assertForbidden();
    }

    public function test_the_feed_puts_pinned_items_first(): void
    {
        News::factory()->published()->create(['title' => 'Обычная', 'published_at' => now()]);
        News::factory()->published()->pinned()->create(['title' => 'Закреплённая', 'published_at' => now()->subWeek()]);

        $this->actingAs($this->employee())
            ->getJson(route('news.index'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Закреплённая')
            ->assertJsonPath('data.1.title', 'Обычная');
    }

    /**
     * В ленте статьи нет: из двадцати новостей она весит больше всего
     * остального вместе взятого.
     */
    public function test_the_feed_leaves_the_article_out_and_the_card_carries_it(): void
    {
        $news = News::factory()->published()->create();

        $feed = $this->actingAs($this->employee())->getJson(route('news.index'))->assertOk();
        $this->assertArrayNotHasKey('content_json', $feed->json('data.0'));

        $this->actingAs($this->employee())
            ->getJson(route('news.show', $news))
            ->assertOk()
            ->assertJsonPath('data.content_json.type', 'doc');
    }

    /* ---------- Кто их ведёт ---------- */

    public function test_an_editor_writes_a_news_item(): void
    {
        $this->actingAs($this->editor())
            ->postJson(route('news.store'), $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.title', 'Переезд склада')
            ->assertJsonPath('data.slug', 'pereezd-sklada')
            ->assertJsonPath('data.is_published', true);

        $this->assertNotNull(News::query()->sole()->published_at);
    }

    public function test_an_ordinary_employee_cannot_write_news(): void
    {
        $this->actingAs($this->employee())
            ->postJson(route('news.store'), $this->payload())
            ->assertForbidden();

        $this->actingAs($this->employee())
            ->getJson(route('news.manage'))
            ->assertForbidden();
    }

    /**
     * Адрес заводится один раз: ссылку на новость могли уже отправить, и
     * опечатка в заголовке не повод её сломать.
     */
    public function test_renaming_a_news_item_keeps_its_address(): void
    {
        $editor = $this->editor();

        $created = $this->actingAs($editor)
            ->postJson(route('news.store'), $this->payload())
            ->assertCreated();

        $news = News::query()->sole();

        $this->actingAs($editor)
            ->putJson(route('news.update', $news), $this->payload(['title' => 'Переезд склада — уточнение']))
            ->assertOk()
            ->assertJsonPath('data.slug', $created->json('data.slug'));
    }

    /**
     * Правка опечатки не должна поднимать новость на верх ленты.
     */
    public function test_editing_does_not_move_the_publication_date(): void
    {
        $editor = $this->editor();
        $news = News::factory()->published()->create(['published_at' => now()->subMonth()]);
        $published = $news->published_at;

        $this->actingAs($editor)
            ->putJson(route('news.update', $news), $this->payload(['title' => 'Поправленный заголовок']))
            ->assertOk();

        $this->assertTrue($published->equalTo($news->refresh()->published_at));
    }

    public function test_an_addressed_news_item_needs_somebody_to_address_before_it_goes_out(): void
    {
        $this->actingAs($this->editor())
            ->postJson(route('news.store'), $this->payload([
                'audience' => NewsAudience::Selected->value,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipients');
    }

    /**
     * Новость заводят с решения «это не всем», а кого именно назвать —
     * выясняют, пока она черновик. Экран создания на это и рассчитан: людей
     * там не выбирают, их выбирают в редакторе.
     */
    public function test_a_draft_may_be_addressed_before_anyone_is_named(): void
    {
        $editor = $this->editor();

        $this->actingAs($editor)
            ->postJson(route('news.store'), $this->payload([
                'status' => NewsStatus::Draft->value,
                'audience' => NewsAudience::Selected->value,
            ]))
            ->assertCreated()
            ->assertJsonPath('data.audience', NewsAudience::Selected->value);

        $news = News::query()->sole();

        // А вот выпустить такую новость нельзя — её не увидит никто.
        $this->actingAs($editor)
            ->putJson(route('news.update', $news), $this->payload([
                'status' => NewsStatus::Published->value,
                'audience' => NewsAudience::Selected->value,
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipients');
    }

    /**
     * Оставленный список ожил бы при обратном переключении и адресовал бы
     * новость людям, которых сегодня никто не выбирал.
     */
    public function test_switching_back_to_everyone_forgets_the_named_list(): void
    {
        $editor = $this->editor();
        $person = $this->employee();

        $this->actingAs($editor)->postJson(route('news.store'), $this->payload([
            'audience' => NewsAudience::Selected->value,
            'recipients' => [$person->id],
        ]))->assertCreated();

        $news = News::query()->sole();
        $this->assertSame(1, $news->recipients()->count());

        $this->actingAs($editor)
            ->putJson(route('news.update', $news), $this->payload())
            ->assertOk();

        $this->assertSame(0, $news->recipients()->count());
    }

    public function test_the_editorial_list_shows_drafts(): void
    {
        News::factory()->create(['title' => 'Черновик']);
        News::factory()->published()->create(['title' => 'Вышедшая']);

        $this->actingAs($this->editor())
            ->getJson(route('news.manage'))
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    /* ---------- Куда сходить после новости ---------- */

    public function test_a_news_item_links_to_material_of_every_kind(): void
    {
        $course = Course::factory()->withLessons(2)->create(['title' => 'Работа с клиентом']);
        $lesson = $course->lessons()->first();
        $module = $course->modules()->first();
        $regulation = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);

        $this->actingAs($this->editor())
            ->postJson(route('news.store'), $this->payload([
                'links' => [
                    ['type' => 'regulation', 'id' => $regulation->id],
                    ['type' => 'course', 'id' => $course->id],
                    ['type' => 'module', 'id' => $module->id],
                    ['type' => 'lesson', 'id' => $lesson->id],
                ],
            ]))
            ->assertCreated()
            ->assertJsonCount(4, 'data.links')
            // Порядок присланного и есть порядок ссылок.
            ->assertJsonPath('data.links.0.kind', 'regulation')
            ->assertJsonPath('data.links.0.kind_label', 'Регламент')
            ->assertJsonPath('data.links.0.url', '/lms/regulations/'.$regulation->slug)
            ->assertJsonPath('data.links.1.url', '/lms/'.$course->slug)
            // У модуля своей страницы нет — он ведёт на курс, где виден целиком.
            ->assertJsonPath('data.links.2.kind_label', 'Модуль')
            ->assertJsonPath('data.links.2.url', '/lms/'.$course->slug)
            ->assertJsonPath('data.links.2.subtitle', 'Курс «Работа с клиентом»')
            ->assertJsonPath('data.links.3.url', '/lms/'.$course->slug.'/lessons/'.$lesson->id);
    }

    public function test_a_reader_sees_the_links_on_the_news_card(): void
    {
        $regulation = Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        $news = News::factory()->published()->create();
        $news->links()->create(['linkable_type' => 'regulation', 'linkable_id' => $regulation->id, 'position' => 0]);

        $this->actingAs($this->learner())
            ->getJson(route('news.show', $news))
            ->assertOk()
            ->assertJsonPath('data.links.0.title', 'Кассовая дисциплина')
            ->assertJsonPath('data.links.0.url', '/lms/regulations/'.$regulation->slug);
    }

    /**
     * Ссылка на закрытый курс — это его название, а название закрытого курса
     * читателю показывать нельзя.
     */
    public function test_a_link_the_reader_cannot_follow_is_left_out(): void
    {
        $open = Regulation::factory()->published()->create();
        $closed = Regulation::factory()->published()->closed()->create();

        $news = News::factory()->published()->create();
        $news->links()->createMany([
            ['linkable_type' => 'regulation', 'linkable_id' => $open->id, 'position' => 0],
            ['linkable_type' => 'regulation', 'linkable_id' => $closed->id, 'position' => 1],
        ]);

        $this->actingAs($this->learner())
            ->getJson(route('news.show', $news))
            ->assertOk()
            ->assertJsonCount(1, 'data.links')
            ->assertJsonPath('data.links.0.item_id', $open->id);
    }

    public function test_material_the_editor_cannot_see_cannot_be_linked(): void
    {
        $closed = Regulation::factory()->published()->closed()->create();

        $this->actingAs($this->editor())
            ->postJson(route('news.store'), $this->payload([
                'links' => [['type' => 'regulation', 'id' => $closed->id]],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('links.0.id');
    }

    public function test_the_same_material_cannot_be_linked_twice(): void
    {
        $regulation = Regulation::factory()->published()->create();

        $this->actingAs($this->editor())
            ->postJson(route('news.store'), $this->payload([
                'links' => [
                    ['type' => 'regulation', 'id' => $regulation->id],
                    ['type' => 'regulation', 'id' => $regulation->id],
                ],
            ]))
            ->assertCreated()
            ->assertJsonCount(1, 'data.links');
    }

    public function test_saving_replaces_the_links_rather_than_adding_to_them(): void
    {
        $editor = $this->editor();
        $first = Regulation::factory()->published()->create();
        $second = Regulation::factory()->published()->create();

        $this->actingAs($editor)->postJson(route('news.store'), $this->payload([
            'links' => [['type' => 'regulation', 'id' => $first->id]],
        ]))->assertCreated();

        $news = News::query()->sole();

        $this->actingAs($editor)
            ->putJson(route('news.update', $news), $this->payload([
                'links' => [['type' => 'regulation', 'id' => $second->id]],
            ]))
            ->assertOk()
            ->assertJsonCount(1, 'data.links')
            ->assertJsonPath('data.links.0.item_id', $second->id);
    }

    public function test_material_can_be_searched_across_every_kind(): void
    {
        Course::factory()->published()->create(['title' => 'Кассовая работа']);
        Regulation::factory()->published()->create(['title' => 'Кассовая дисциплина']);
        Regulation::factory()->published()->create(['title' => 'Охрана труда']);

        $response = $this->actingAs($this->editor())
            ->getJson(route('news.material', ['search' => 'кассовая']))
            ->assertOk();

        // Кириллица ищется без учёта регистра только через ICU: базы собраны с
        // C-сортировкой.
        $this->assertCount(2, $response->json('data'));
        $this->assertEqualsCanonicalizing(
            ['course', 'regulation'],
            array_column($response->json('data'), 'kind'),
        );
    }

    public function test_an_ordinary_employee_cannot_search_material_for_news(): void
    {
        $this->actingAs($this->employee())
            ->getJson(route('news.material', ['search' => 'что-нибудь']))
            ->assertForbidden();
    }

    /* ---------- Ознакомление ---------- */

    public function test_a_reader_confirms_they_have_read_it(): void
    {
        $reader = $this->employee();
        $news = News::factory()->published()->mustBeAcknowledged()->create();

        $this->actingAs($reader)
            ->postJson(route('news.acknowledge', $news))
            ->assertOk()
            ->assertJsonPath('data.is_acknowledged', true);

        $this->assertTrue($news->isAcknowledgedBy($reader));

        $this->actingAs($reader)
            ->getJson(route('news.show', $news))
            ->assertOk()
            ->assertJsonPath('data.is_acknowledged', true);
    }

    /**
     * Нажать дважды — не ошибка и не вторая строка: человек мог кликнуть два
     * раза, а два браузера — одновременно.
     */
    public function test_confirming_twice_leaves_one_record(): void
    {
        $reader = $this->employee();
        $news = News::factory()->published()->create();

        $this->actingAs($reader)->postJson(route('news.acknowledge', $news))->assertOk();
        $this->actingAs($reader)->postJson(route('news.acknowledge', $news))->assertOk();

        $this->assertSame(1, $news->acknowledgements()->count());
    }

    public function test_the_pending_count_only_counts_what_must_be_acknowledged(): void
    {
        $reader = $this->employee();

        News::factory()->published()->mustBeAcknowledged()->create();
        News::factory()->published()->create();
        $done = News::factory()->published()->mustBeAcknowledged()->create();

        $this->actingAs($reader)->postJson(route('news.acknowledge', $done))->assertOk();

        $this->actingAs($reader)
            ->getJson(route('news.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.count', 1);
    }

    /**
     * Новичка не встречают долгами: вышедшее до его прихода ознакомления от
     * него не требует, а появившееся при нём — требует.
     */
    public function test_what_came_out_before_a_newcomer_arrived_does_not_await_them(): void
    {
        $old = News::factory()->published()->mustBeAcknowledged()->create([
            'published_at' => now()->subMonth(),
        ]);

        $newcomer = $this->employee();

        $fresh = News::factory()->published()->mustBeAcknowledged()->create();

        $this->actingAs($newcomer)
            ->getJson(route('news.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.count', 1);

        $feed = collect($this->actingAs($newcomer)->getJson(route('news.index'))->json('data'))
            ->keyBy('id');

        $this->assertFalse($feed[$old->id]['awaits_acknowledgement']);
        $this->assertTrue($feed[$fresh->id]['awaits_acknowledgement']);

        // И на самой новости — тот же ответ.
        $this->actingAs($newcomer)
            ->getJson(route('news.show', $old))
            ->assertOk()
            ->assertJsonPath('data.awaits_acknowledgement', false)
            // Сама новость по-прежнему обязательная: это про неё, а не про него.
            ->assertJsonPath('data.requires_acknowledgement', true);
    }

    /**
     * Число, с которым автор сравнивает счётчик прочитавших, не растёт само от
     * того, что компания набрала людей после выхода новости.
     */
    public function test_a_newcomer_does_not_swell_the_number_the_editor_compares_with(): void
    {
        $editor = $this->editor();
        $news = News::factory()->published()->create(['published_at' => now()->subDay()]);

        $before = $this->actingAs($editor)
            ->getJson(route('news.show', $news))
            ->assertOk()
            ->json('data.audience_size');

        User::factory()->create();

        $after = $this->actingAs($editor)
            ->getJson(route('news.show', $news))
            ->assertOk()
            ->json('data.audience_size');

        $this->assertSame($before, $after);
    }

    public function test_the_editor_sees_who_has_read_it_and_who_has_not(): void
    {
        $read = User::factory()->create(['last_name' => 'Ёлкина', 'first_name' => 'Вера']);
        $unread = User::factory()->create(['last_name' => 'Яковлев', 'first_name' => 'Пётр']);
        $editor = $this->editor();

        $news = News::factory()->published()->addressed()->create();
        $news->recipients()->attach([$read->id, $unread->id]);

        $this->actingAs($read)->postJson(route('news.acknowledge', $news))->assertOk();

        $this->actingAs($editor)
            ->getJson(route('news.acknowledgements', $news))
            ->assertOk()
            ->assertJsonPath('data.acknowledged.0.name', 'Ёлкина Вера')
            ->assertJsonPath('data.acknowledged.0.acknowledged_via', 'Подтвердил')
            ->assertJsonPath('data.pending.0.name', 'Яковлев Пётр');
    }

    public function test_a_reader_cannot_see_who_else_has_read_it(): void
    {
        $news = News::factory()->published()->create();

        $this->actingAs($this->employee())
            ->getJson(route('news.acknowledgements', $news))
            ->assertForbidden();
    }

    /* ---------- Проверка ---------- */

    public function test_passing_the_quiz_counts_as_having_read_the_news(): void
    {
        $editor = $this->editor();
        $reader = $this->employee();
        $news = News::factory()->published()->mustBeAcknowledged()->create();

        $this->actingAs($editor)->putJson(route('news.quiz.save', $news), [
            'title' => 'Проверка',
            'passing_score' => 100,
            'questions' => [[
                'text' => 'С какого дня отгрузки с новой площадки?',
                'type' => 'single',
                'points' => 1,
                'options' => [
                    ['text' => 'С понедельника', 'is_correct' => true],
                    ['text' => 'С пятницы', 'is_correct' => false],
                ],
            ]],
        ])->assertSuccessful();

        $quiz = $news->refresh()->load('quiz.questions.options')->quiz;
        $question = $quiz->questions->first();
        $correct = $question->options->firstWhere('is_correct', true);

        $this->actingAs($reader)
            ->postJson(route('news.quiz.submit', $news), [
                'answers' => [$question->id => [$correct->id]],
            ])
            ->assertCreated()
            ->assertJsonPath('data.passed', true)
            ->assertJsonPath('data.is_acknowledged', true);

        $this->assertTrue($news->isAcknowledgedBy($reader));
        $this->assertSame('quiz', $news->acknowledgements()->sole()->source->value);
    }

    public function test_failing_the_quiz_does_not_count_as_having_read_it(): void
    {
        $editor = $this->editor();
        $reader = $this->employee();
        $news = News::factory()->published()->create();

        $this->actingAs($editor)->putJson(route('news.quiz.save', $news), [
            'title' => 'Проверка',
            'passing_score' => 100,
            'questions' => [[
                'text' => 'Вопрос',
                'type' => 'single',
                'points' => 1,
                'options' => [
                    ['text' => 'Верно', 'is_correct' => true],
                    ['text' => 'Неверно', 'is_correct' => false],
                ],
            ]],
        ])->assertSuccessful();

        $quiz = $news->refresh()->load('quiz.questions.options')->quiz;
        $question = $quiz->questions->first();
        $wrong = $question->options->firstWhere('is_correct', false);

        $this->actingAs($reader)
            ->postJson(route('news.quiz.submit', $news), ['answers' => [$question->id => [$wrong->id]]])
            ->assertCreated()
            ->assertJsonPath('data.passed', false)
            ->assertJsonPath('data.is_acknowledged', false);

        $this->assertFalse($news->isAcknowledgedBy($reader));
    }

    /**
     * При проверке кнопки нет вовсе: нажатие обесценивало бы её.
     */
    public function test_the_button_is_refused_while_a_quiz_is_attached(): void
    {
        $editor = $this->editor();
        $news = News::factory()->published()->create();

        $this->actingAs($editor)->putJson(route('news.quiz.save', $news), [
            'title' => 'Проверка',
            'passing_score' => 70,
            'questions' => [[
                'text' => 'Вопрос',
                'type' => 'single',
                'points' => 1,
                'options' => [
                    ['text' => 'Верно', 'is_correct' => true],
                    ['text' => 'Неверно', 'is_correct' => false],
                ],
            ]],
        ])->assertSuccessful();

        $this->actingAs($this->employee())
            ->postJson(route('news.acknowledge', $news->refresh()))
            ->assertConflict();
    }

    /**
     * Правильные ответы видны в теле ответа, что бы ни рисовал экран.
     */
    public function test_a_reader_is_not_told_which_option_is_correct(): void
    {
        $editor = $this->editor();
        $news = News::factory()->published()->create();

        $this->actingAs($editor)->putJson(route('news.quiz.save', $news), [
            'title' => 'Проверка',
            'passing_score' => 70,
            'questions' => [[
                'text' => 'Вопрос',
                'type' => 'single',
                'points' => 1,
                'options' => [
                    ['text' => 'Верно', 'is_correct' => true],
                    ['text' => 'Неверно', 'is_correct' => false],
                ],
            ]],
        ])->assertSuccessful();

        $reader = $this->actingAs($this->employee())
            ->getJson(route('news.show', $news->refresh()))
            ->assertOk();

        $this->assertArrayNotHasKey('is_correct', $reader->json('data.quiz.questions.0.options.0'));

        $writer = $this->actingAs($editor)->getJson(route('news.show', $news))->assertOk();

        $this->assertTrue($writer->json('data.quiz.questions.0.options.0.is_correct'));
    }

    /* ---------- Файлы ---------- */

    public function test_a_document_can_be_attached_to_a_news_item(): void
    {
        Storage::fake('s3');

        $news = News::factory()->published()->create();

        $this->actingAs($this->editor())
            ->postJson(route('news.attachments.store', $news), [
                'file' => UploadedFile::fake()->create('регламент.pdf', 64, 'application/pdf'),
                'description' => 'Регламент отгрузки',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'регламент.pdf')
            ->assertJsonPath('data.description', 'Регламент отгрузки');

        $this->assertSame(1, $news->attachments()->count());
        Storage::disk('s3')->assertExists($news->attachments()->sole()->path);
    }

    public function test_an_ordinary_employee_cannot_attach_files(): void
    {
        Storage::fake('s3');

        $news = News::factory()->published()->create();

        $this->actingAs($this->employee())
            ->postJson(route('news.attachments.store', $news), [
                'file' => UploadedFile::fake()->create('своё.pdf', 8, 'application/pdf'),
            ])
            ->assertForbidden();
    }

    public function test_a_guest_sees_no_news(): void
    {
        $this->getJson(route('news.index'))->assertUnauthorized();
    }
}
