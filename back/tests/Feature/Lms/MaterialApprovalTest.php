<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\ApprovalStatus;
use App\Enums\CourseStatus;
use App\Jobs\SendPush;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\MaterialReview;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Согласование материала перед публикацией (решение пользователя 2026-09-30).
 *
 * Автор либо выкладывает материал сам, либо отправляет его одному человеку или
 * нескольким. Согласовали все — материал выходит сам; кому-то не понравилось —
 * он пишет причину, и круг заканчивается возвратом автору.
 */
final class MaterialApprovalTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /* ---------- Отправка ---------- */

    public function test_an_author_sends_a_draft_to_two_people(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);

        [$first, $second] = [$this->learner(), $this->learner()];

        $response = $this->actingAs($author)
            ->postJson(route('lms.documents.approval.store', $document), [
                'approvers' => [$first->id, $second->id],
            ])
            // 201: отправка заводит круг, а не правит существующий.
            ->assertCreated()
            ->assertJsonPath('data.status', ApprovalStatus::Pending->value)
            ->assertJsonPath('data.round', 1)
            ->assertJsonCount(2, 'data.decisions');

        $this->assertSame(
            [ApprovalStatus::Pending->value, ApprovalStatus::Pending->value],
            array_column((array) $response->json('data.decisions'), 'status'),
        );

        // Материал остался черновиком: согласование — не публикация.
        $this->assertFalse($document->refresh()->isPublished());
    }

    /** Отправка без адресатов — это «выложить сам», и для этого есть публикация. */
    public function test_an_empty_list_is_refused(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);

        $this->actingAs($author)
            ->postJson(route('lms.documents.approval.store', $document), ['approvers' => []])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('approvers');
    }

    /** Себя в согласующие не ставят: согласовывать у самого себя нечего. */
    public function test_the_author_alone_is_not_a_round(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);

        $this->actingAs($author)
            ->postJson(route('lms.documents.approval.store', $document), ['approvers' => [$author->id]])
            ->assertStatus(409);

        $this->assertDatabaseCount('material_reviews', 0);
    }

    public function test_a_second_round_cannot_start_while_one_is_open(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $approver = $this->learner();

        $this->send($document, $author, [$approver]);

        $this->actingAs($author)
            ->postJson(route('lms.documents.approval.store', $document), ['approvers' => [$approver->id]])
            ->assertStatus(409);

        $this->assertDatabaseCount('material_reviews', 1);
    }

    /* ---------- Чтение на время круга ---------- */

    /**
     * Позванный читает закрытый черновик, хотя его туда не впускали.
     *
     * Иначе согласовать нечего: материала для него не существует. И читает ровно
     * пока круг идёт — см. следующий тест.
     */
    public function test_an_approver_reads_a_closed_draft_they_were_never_admitted_to(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->closed()->create(['author_id' => $author->id]);
        $approver = $this->learner();

        // До отправки материала для него не существует.
        $this->actingAs($approver)
            ->getJson(route('lms.documents.show', $document))
            ->assertForbidden();

        $this->send($document, $author, [$approver]);

        $this->actingAs($approver)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.review.awaits_me', true);
    }

    public function test_the_reading_ends_with_the_round(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->closed()->create(['author_id' => $author->id]);
        $approver = $this->learner();

        $review = $this->send($document, $author, [$approver]);

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.approve', $review))
            ->assertOk();

        // Материал вышел, но закрытым: в круг человека звали, в сам материал —
        // нет, и читать его больше нечем.
        $this->assertTrue($document->refresh()->isPublished());

        $this->actingAs($approver)
            ->getJson(route('lms.documents.show', $document))
            ->assertForbidden();
    }

    /* ---------- Согласие ---------- */

    public function test_the_material_goes_out_when_everyone_has_approved(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);

        [$first, $second] = [$this->learner(), $this->learner()];

        $review = $this->send($document, $author, [$first, $second]);

        $this->actingAs($first)
            ->postJson(route('lms.approvals.approve', $review))
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalStatus::Pending->value);

        // Одного согласия мало: материал ждёт всех.
        $this->assertFalse($document->refresh()->isPublished());

        $this->actingAs($second)
            ->postJson(route('lms.approvals.approve', $review))
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalStatus::Approved->value);

        $document->refresh();

        $this->assertTrue($document->isPublished());
        $this->assertNotNull($document->published_at);
        $this->assertNotNull($review->refresh()->closed_at);
    }

    public function test_nobody_answers_twice(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $approver = $this->learner();

        $review = $this->send($document, $author, [$approver]);

        $this->actingAs($approver)->postJson(route('lms.approvals.approve', $review))->assertOk();

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.approve', $review))
            ->assertStatus(409);
    }

    /** Чужой круг — тот же случай, что и несуществующий. */
    public function test_a_stranger_cannot_decide(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);

        $review = $this->send($document, $author, [$this->learner()]);

        $this->actingAs($this->learner())
            ->postJson(route('lms.approvals.approve', $review))
            ->assertNotFound();
    }

    /* ---------- Возврат ---------- */

    public function test_a_return_needs_a_reason_and_ends_the_round(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $approver = $this->learner();

        $review = $this->send($document, $author, [$approver]);

        // Без причины возврата не бывает: она — единственное, что говорит
        // автору, что делать дальше.
        $this->actingAs($approver)
            ->postJson(route('lms.approvals.return', $review), ['comment' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('comment');

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Проверьте пункт 3: ставка не та.'])
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalStatus::Returned->value)
            ->assertJsonPath('data.returned_reason', 'Проверьте пункт 3: ставка не та.');

        // Материал не вышел, и круг закончился, не дожидаясь остальных.
        $this->assertFalse($document->refresh()->isPublished());
        $this->assertSame(ApprovalStatus::Returned, $review->refresh()->status);
    }

    /** Причина видна автору на самом материале — за ней он сюда и приходит. */
    public function test_the_author_sees_why_it_came_back(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $approver = $this->learner();

        $review = $this->send($document, $author, [$approver]);

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Не тот бланк.'])
            ->assertOk();

        $this->actingAs($author)
            ->getJson(route('lms.documents.show', $document))
            ->assertOk()
            ->assertJsonPath('data.review.status', ApprovalStatus::Returned->value)
            ->assertJsonPath('data.review.returned_reason', 'Не тот бланк.');
    }

    /**
     * Исправленный материал уходит новым кругом, и согласуют его заново все
     * (решение пользователя 2026-09-30): текст изменился, а прежнее «согласен»
     * относилось к прежнему тексту.
     */
    public function test_resubmission_resets_the_approvals_already_given(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);

        [$first, $second] = [$this->learner(), $this->learner()];

        $review = $this->send($document, $author, [$first, $second]);

        $this->actingAs($first)->postJson(route('lms.approvals.approve', $review))->assertOk();
        $this->actingAs($second)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Исправьте таблицу.'])
            ->assertOk();

        $again = $this->send($document, $author, [$first, $second]);

        $this->assertSame(2, $again->round);
        $this->assertCount(2, $again->awaiting());

        // Прежний круг остался историей: по нему видно, что говорили в прошлый раз.
        $this->assertSame(2, MaterialReview::query()->count());
    }

    /* ---------- Автор не ждёт ---------- */

    public function test_the_author_may_publish_without_waiting(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $approver = $this->learner();

        $review = $this->send($document, $author, [$approver]);

        $this->actingAs($author)
            ->putJson(route('lms.documents.update', $document), [
                'title' => $document->title,
                'summary' => $document->summary,
                'content_json' => $document->content_json,
                'status' => CourseStatus::Published->value,
                'visibility' => $document->visibility->value,
                'category_id' => $document->category_id,
            ])
            ->assertOk();

        $this->assertTrue($document->refresh()->isPublished());

        // Круг закрыт отменой: ждать ответа от людей, которым уже нечего решать,
        // было бы обманом обеих сторон.
        $this->assertSame(ApprovalStatus::Cancelled, $review->refresh()->status);

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.approve', $review))
            ->assertStatus(409);
    }

    public function test_the_author_withdraws_the_submission(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);

        $review = $this->send($document, $author, [$this->learner()]);

        $this->actingAs($author)
            ->deleteJson(route('lms.documents.approval.destroy', $document))
            ->assertNoContent();

        $this->assertSame(ApprovalStatus::Cancelled, $review->refresh()->status);
    }

    /* ---------- Очередь и значок ---------- */

    public function test_the_queue_shows_what_awaits_this_person_first(): void
    {
        $author = $this->author();
        $approver = $this->learner();

        $answered = Regulation::factory()->create(['author_id' => $author->id, 'title' => 'Уже решён']);
        $waiting = Regulation::factory()->create(['author_id' => $author->id, 'title' => 'Ждёт ответа']);

        $first = $this->send($answered, $author, [$approver]);
        $this->send($waiting, $author, [$approver]);

        $this->actingAs($approver)->postJson(route('lms.approvals.approve', $first))->assertOk();

        $response = $this->actingAs($approver)
            ->getJson(route('lms.approvals.index'))
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Ждущее сверху: решённое не прячется, к нему возвращаются.
        $this->assertSame('Ждёт ответа', $response->json('data.0.material.title'));
        $this->assertTrue($response->json('data.0.awaits_me'));
        $this->assertSame('Уже решён', $response->json('data.1.material.title'));

        $this->actingAs($approver)
            ->getJson(route('lms.approvals.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.pending', 1)
            ->assertJsonPath('data.is_approver', true);
    }

    /**
     * Закрытый круг значок не считает.
     *
     * Нерешённый ответ переживает свой круг: отозвали отправку — строка человека
     * навсегда осталась «не ответил», потому что отвечать уже не на что. Значок
     * такие строки считал, и раздел звал разбирать очередь, в которой ничего
     * нет: ровно это пользователь и увидел на боевом экране.
     */
    public function test_a_closed_round_stops_counting_towards_the_badge(): void
    {
        $author = $this->author();
        $approver = $this->learner();

        $withdrawn = Regulation::factory()->create(['author_id' => $author->id]);
        $this->send($withdrawn, $author, [$approver]);

        $this->actingAs($author)
            ->deleteJson(route('lms.documents.approval.destroy', $withdrawn))
            ->assertNoContent();

        $this->actingAs($approver)
            ->getJson(route('lms.approvals.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.pending', 0)
            // Звали — значит раздел остаётся: в нём видно, что стало с
            // материалом, который у человека просили посмотреть.
            ->assertJsonPath('data.is_approver', true);
    }

    /** Чужой возврат заканчивает круг — и снимает ожидание с остальных. */
    public function test_a_return_by_somebody_else_clears_the_badge_too(): void
    {
        $author = $this->author();
        [$first, $second] = [$this->learner(), $this->learner()];

        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $review = $this->send($document, $author, [$first, $second]);

        $this->actingAs($first)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Не тот бланк.'])
            ->assertOk();

        $this->actingAs($second)
            ->getJson(route('lms.approvals.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.pending', 0);

        // И в очереди у второго строка не висит ждущей: решать нечего.
        $this->actingAs($second)
            ->getJson(route('lms.approvals.index'))
            ->assertOk()
            ->assertJsonPath('data.0.awaits_me', false);
    }

    /** Кого не звали ни разу — тому и раздела не показывают. */
    public function test_somebody_never_asked_has_no_section(): void
    {
        $this->actingAs($this->learner())
            ->getJson(route('lms.approvals.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.is_approver', false);
    }

    /* ---------- Автор видит ответ проверяющего ---------- */

    /**
     * Возврат числится за автором, а не только приходит уведомлением.
     *
     * Телефон мог быть выключен, а уведомление — заменено следующим; вернувшаяся
     * работа должна лежать там же, где чужая работа лежит у согласующего.
     */
    public function test_a_return_lands_in_the_authors_own_list(): void
    {
        $author = $this->author();
        $approver = $this->learner();

        $document = Regulation::factory()->create(['author_id' => $author->id, 'title' => 'Бланк расчёта']);
        $review = $this->send($document, $author, [$approver]);

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Не тот бланк.'])
            ->assertOk();

        $this->actingAs($author)
            ->getJson(route('lms.approvals.mine'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', ApprovalStatus::Returned->value)
            ->assertJsonPath('data.0.returned_reason', 'Не тот бланк.')
            ->assertJsonPath('data.0.material.title', 'Бланк расчёта');

        // И значок считает это работой автора наравне с чужой.
        $this->actingAs($author)
            ->getJson(route('lms.approvals.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.returned', 1)
            ->assertJsonPath('data.pending', 0)
            ->assertJsonPath('data.is_approver', true);
    }

    /** Отправил заново — прежний возврат с автора снимается. */
    public function test_resubmitting_clears_the_return_from_the_author(): void
    {
        $author = $this->author();
        $approver = $this->learner();

        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $review = $this->send($document, $author, [$approver]);

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Исправьте таблицу.'])
            ->assertOk();

        $this->send($document, $author, [$approver]);

        $response = $this->actingAs($author)
            ->getJson(route('lms.approvals.mine'))
            ->assertOk()
            // Только последний круг: прежний — история, и дважды один материал
            // в списке не стоит.
            ->assertJsonCount(1, 'data');

        $this->assertSame(ApprovalStatus::Pending->value, $response->json('data.0.status'));

        $this->actingAs($author)
            ->getJson(route('lms.approvals.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.returned', 0);
    }

    /**
     * Выложил руками — напоминание об исправлении снимается.
     *
     * Круг остался возвращённым, но автор ответил на него по-своему: выпустил
     * материал. Иначе значок горел бы вечно.
     */
    public function test_publishing_by_hand_clears_the_return(): void
    {
        $author = $this->author();
        $approver = $this->learner();

        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $review = $this->send($document, $author, [$approver]);

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Слишком длинно.'])
            ->assertOk();

        $this->actingAs($author)
            ->putJson(route('lms.documents.update', $document), [
                'title' => $document->title,
                'summary' => $document->summary,
                'content_json' => $document->content_json,
                'status' => CourseStatus::Published->value,
                'visibility' => $document->visibility->value,
                'category_id' => $document->category_id,
            ])
            ->assertOk();

        $this->actingAs($author)
            ->getJson(route('lms.approvals.pending-count'))
            ->assertOk()
            ->assertJsonPath('data.returned', 0);
    }

    /** Свои отправки видит только тот, кто отправлял. */
    public function test_nobody_reads_somebody_elses_submissions(): void
    {
        $author = $this->author();
        $document = Regulation::factory()->create(['author_id' => $author->id]);

        $this->send($document, $author, [$this->learner()]);

        $this->actingAs($this->author())
            ->getJson(route('lms.approvals.mine'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /* ---------- Курс ---------- */

    public function test_a_course_goes_out_the_same_way(): void
    {
        $author = $this->author();
        $course = Course::factory()->create(['author_id' => $author->id, 'status' => CourseStatus::Draft]);
        $approver = $this->learner();

        $response = $this->actingAs($author)
            ->postJson(route('lms.courses.approval.store', $course), ['approvers' => [$approver->id]])
            ->assertCreated();

        /** @var MaterialReview $review */
        $review = MaterialReview::query()->findOrFail($response->json('data.id'));

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.approve', $review))
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalStatus::Approved->value);

        $course->refresh();

        $this->assertTrue($course->isPublished());
        $this->assertNotNull($course->published_at);
    }

    /**
     * Отправляет тот, кто вправе править: читателю такой кнопки не дают.
     */
    public function test_a_reader_cannot_send_anything_for_approval(): void
    {
        $document = Regulation::factory()->create(['author_id' => $this->author()->id]);

        $this->actingAs($this->learner())
            ->postJson(route('lms.documents.approval.store', $document), ['approvers' => [$this->learner()->id]])
            ->assertForbidden();
    }

    /* ---------- Урок ---------- */

    /**
     * Урок выходит к людям тем же путём (решение пользователя 2026-09-30).
     *
     * Своё состояние у урока появилось ради этого: пока круг идёт, урока людям
     * не видно — иначе согласование было бы украшением, «согласуйте то, что все
     * уже прочитали».
     */
    public function test_a_lesson_goes_out_when_everyone_has_approved(): void
    {
        $author = $this->author();
        $approver = $this->learner();

        $lesson = $this->draftLesson();

        $response = $this->actingAs($author)
            ->postJson(route('lms.lessons.approval.store', $lesson), ['approvers' => [$approver->id]])
            ->assertCreated();

        /** @var MaterialReview $review */
        $review = MaterialReview::query()->findOrFail($response->json('data.id'));

        // Пока идёт круг, урока людям не видно — а согласующий его читает.
        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertNotFound();

        $this->actingAs($approver)
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk()
            ->assertJsonPath('data.review.awaits_me', true);

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.approve', $review))
            ->assertOk()
            ->assertJsonPath('data.status', ApprovalStatus::Approved->value);

        $lesson->refresh();

        $this->assertTrue($lesson->isPublished());

        // И теперь его видно всем, кому открыт курс.
        $this->actingAs($this->learner())
            ->getJson(route('lms.lessons.show', $lesson))
            ->assertOk();
    }

    /** Вернули урок — он остаётся черновиком, а автор читает причину. */
    public function test_a_returned_lesson_stays_out_of_sight(): void
    {
        $author = $this->author();
        $approver = $this->learner();

        $lesson = $this->draftLesson();

        $response = $this->actingAs($author)
            ->postJson(route('lms.lessons.approval.store', $lesson), ['approvers' => [$approver->id]])
            ->assertCreated();

        /** @var MaterialReview $review */
        $review = MaterialReview::query()->findOrFail($response->json('data.id'));

        $this->actingAs($approver)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Нет примера расчёта.'])
            ->assertOk();

        $this->assertFalse($lesson->refresh()->isPublished());

        $this->actingAs($author)
            ->getJson(route('lms.approvals.mine'))
            ->assertOk()
            ->assertJsonPath('data.0.returned_reason', 'Нет примера расчёта.')
            ->assertJsonPath('data.0.material.label', 'Урок');
    }

    /**
     * Черновик урока не всплывает там, где считают и ищут.
     *
     * Прогресс, поиск по платформе и корпус консультанта — три места, где урок,
     * которого людям не видно, означал бы курс, который нельзя пройти до конца,
     * ссылку в никуда и ответ по несогласованному тексту.
     */
    public function test_a_draft_lesson_counts_nowhere(): void
    {
        $lesson = $this->draftLesson();
        $course = $lesson->owningCourse();
        $learner = $this->learner();

        // В курсе для читателя его нет вовсе.
        $response = $this->actingAs($learner)
            ->getJson(route('lms.courses.show', $course))
            ->assertOk();

        $this->assertSame(0, collect($response->json('data.modules'))->sum(fn (array $module): int => count($module['lessons'])));

        // А тот, кто курс ведёт, видит его с пометкой.
        $forAuthor = $this->actingAs($this->author())
            ->getJson(route('lms.courses.show', $course))
            ->assertOk();

        $this->assertSame(false, $forAuthor->json('data.modules.0.lessons.0.is_published'));

        // Пройти его нельзя — «пройден» у невидимого ничего не значит.
        $this->actingAs($learner)->postJson(route('lms.enroll', $course))->assertCreated();

        $this->actingAs($learner)
            ->postJson(route('lms.lessons.complete', $lesson))
            ->assertStatus(409);

        // И в поиске по платформе его нет: читателю вести туда некуда.
        $found = $this->actingAs($learner)
            ->getJson(route('search', ['q' => $lesson->title]))
            ->assertOk();

        $this->assertSame([], collect($found->json('data.sections'))->firstWhere('key', 'lessons')['items'] ?? []);
    }

    /* ---------- Уведомления на телефон ---------- */

    /**
     * Все четыре события согласования уходят уведомлением.
     *
     * Позвали, вернули, согласовали, отменили — каждое из них человек должен
     * узнать, не открывая приложение: круг идёт днями, и ждать, пока обе стороны
     * догадаются заглянуть в раздел, значит растянуть его на неделю.
     */
    public function test_every_turn_of_the_round_reaches_the_phone(): void
    {
        Queue::fake();

        $author = $this->author();
        $approver = $this->learner();

        $document = Regulation::factory()->create(['author_id' => $author->id, 'title' => 'Бланк расчёта']);

        // 1. Позвали — согласующему.
        $review = $this->send($document, $author, [$approver]);

        Queue::assertPushed(SendPush::class, fn (SendPush $job): bool => $this->recipientsOf($job) === [$approver->id]);

        // 2. Вернули — тому, кто отправлял. Это и есть «ответ проверяющего,
        // который не согласовал».
        $this->actingAs($approver)
            ->postJson(route('lms.approvals.return', $review), ['comment' => 'Не тот бланк.'])
            ->assertOk();

        Queue::assertPushed(SendPush::class, fn (SendPush $job): bool => $this->recipientsOf($job) === [$author->id]);

        // 3. Согласовали все — автору, вместе с тем, что материал вышел.
        $again = $this->send($document, $author, [$approver]);

        $this->actingAs($approver)->postJson(route('lms.approvals.approve', $again))->assertOk();

        // Два уведомления автору: возврат и согласие.
        Queue::assertPushed(
            SendPush::class,
            fn (SendPush $job): bool => $this->recipientsOf($job) === [$author->id],
        );

        $this->assertSame(
            2,
            collect(Queue::pushed(SendPush::class))
                ->filter(fn (SendPush $job): bool => $this->recipientsOf($job) === [$author->id])
                ->count(),
        );
    }

    /** Автор выложил материал сам — позванные узнают, что ответа больше не ждут. */
    public function test_cancelling_tells_the_people_who_were_asked(): void
    {
        Queue::fake();

        $author = $this->author();
        $approver = $this->learner();

        $document = Regulation::factory()->create(['author_id' => $author->id]);
        $this->send($document, $author, [$approver]);

        $this->actingAs($author)
            ->deleteJson(route('lms.documents.approval.destroy', $document))
            ->assertNoContent();

        // Два уведомления согласующему: «просят согласовать» и «отменено».
        $this->assertSame(
            2,
            collect(Queue::pushed(SendPush::class))
                ->filter(fn (SendPush $job): bool => $this->recipientsOf($job) === [$approver->id])
                ->count(),
        );
    }

    /**
     * Кому ушло уведомление — по закрытому полю задания.
     *
     * @return list<int>
     */
    private function recipientsOf(SendPush $job): array
    {
        $recipients = (fn (): array => $this->userIds)->call($job);

        sort($recipients);

        return $recipients;
    }

    /* ---------- Помощники ---------- */

    /** Урок, которого людям ещё не видно, — в опубликованном курсе. */
    private function draftLesson(): Lesson
    {
        $course = Course::factory()->published()->create();
        $module = CourseModule::factory()->create(['course_id' => $course->id, 'position' => 0]);

        return Lesson::factory()->draft()->create(['module_id' => $module->id, 'title' => 'Расчёт премии']);
    }

    /**
     * @param  list<User>  $approvers
     */
    private function send(Regulation|Course $material, User $author, array $approvers): MaterialReview
    {
        $route = $material instanceof Course
            ? route('lms.courses.approval.store', $material)
            : route('lms.documents.approval.store', $material);

        $response = $this->actingAs($author)
            ->postJson($route, ['approvers' => array_map(static fn (User $one): int => $one->id, $approvers)])
            ->assertCreated();

        return MaterialReview::query()->findOrFail($response->json('data.id'));
    }
}
