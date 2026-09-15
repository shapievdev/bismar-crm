<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesConversations;
use Tests\TestCase;

/**
 * Поиск по сказанному.
 *
 * Проверяется русский словарь — «бланки» находятся по «бланк», — и границы
 * видимого: поиск не должен становиться щелью, через которую видно больше, чем
 * в самой переписке.
 *
 * Идёт на настоящем Postgres: полнотекстовый поиск с ICU-коллацией — это
 * возможности базы, а не приложения, и проверять их подделкой бессмысленно.
 */
final class MessageSearchTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, RefreshDatabase;

    /** Русский словарь: спрашивают «бланк», написано «бланки». */
    public function test_a_word_is_found_in_another_form(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $found = $this->saidIn($conversation, $other, 'Бланки лежат в папке «Сверки»');
        $this->saidIn($conversation, $other, 'Совсем про другое');

        $this->actingAs($me)
            ->getJson(route('chat.messages.search', $conversation).'?q='.urlencode('бланк'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $found->id)
            ->assertJsonPath('meta.total', 1);
    }

    /** Заглавная кириллица сворачивается: база создана в коллации C. */
    public function test_the_query_is_case_insensitive_in_cyrillic(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->saidIn($conversation, $me, 'Отгрузка ушла');

        $this->actingAs($me)
            ->getJson(route('chat.messages.search', $conversation).'?q='.urlencode('ОТГРУЗКА'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Последнее слово ищется по началу: человек ещё печатает. */
    public function test_the_last_word_matches_by_prefix(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());

        $this->saidIn($conversation, $me, 'Инвентаризация в пятницу');

        $this->actingAs($me)
            ->getJson(route('chat.messages.search', $conversation).'?q='.urlencode('инвентар'))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /** Поиск по всем своим перепискам находит разговор, который был закрыт. */
    public function test_the_global_search_reaches_every_conversation(): void
    {
        $me = $this->employee();
        $first = $this->conversationBetween($me, $this->employee());
        $second = $this->conversationBetween($me, $this->employee());

        $this->saidIn($first, $me, 'Договор подписан');
        $found = $this->saidIn($second, $me, 'Договор на согласовании');

        $this->actingAs($me)
            ->getJson(route('chat.search').'?q='.urlencode('договор'))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            // Свежее первым: находки читаются сверху вниз, как список.
            ->assertJsonPath('data.0.id', $found->id);
    }

    /** Чужие переписки не ищутся — ни глобально, ни по номеру. */
    public function test_other_peoples_conversations_stay_out(): void
    {
        $me = $this->employee();
        $mine = $this->conversationBetween($me, $this->employee());
        $this->saidIn($mine, $me, 'Своё про отгрузку');

        $strangers = $this->conversationBetween($this->employee(), $this->employee());
        $this->saidIn($strangers, $strangers->participants()->firstOrFail(), 'Чужое про отгрузку');

        $this->actingAs($me)
            ->getJson(route('chat.search').'?q='.urlencode('отгрузка'))
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->actingAs($me)
            ->getJson(route('chat.messages.search', $strangers).'?q='.urlencode('отгрузка'))
            ->assertForbidden();
    }

    /**
     * Удалённое у себя не находится: для этого человека переписка начинается с
     * той минуты, когда он её убрал.
     */
    public function test_what_was_cleared_is_not_found(): void
    {
        $me = $this->employee();
        $other = $this->employee();
        $conversation = $this->conversationBetween($me, $other);

        $this->saidIn($conversation, $other, 'Старая отгрузка');

        $this->actingAs($me)
            ->deleteJson(route('chat.conversations.destroy', $conversation), ['scope' => 'mine'])
            ->assertOk();

        // Отметка об удалении и новая реплика обязаны разойтись во времени:
        // время в базе хранится с точностью до секунды, и сказанное в ту же
        // секунду, в которую переписку убрали, считается сказанным до неё.
        $this->travel(1)->seconds();

        $this->saidIn($conversation, $other, 'Новая отгрузка');

        $this->actingAs($me)
            ->getJson(route('chat.search').'?q='.urlencode('отгрузка'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.body', 'Новая отгрузка');
    }

    /** Системные отметки — не разговор, и в находках только мешают. */
    public function test_system_notes_are_not_searched(): void
    {
        $owner = $this->employee();
        $mate = $this->employee();
        $group = $this->groupOf($owner, [$mate]);

        $this->actingAs($owner)
            ->postJson(route('chat.participants.store', $group), ['user_ids' => [$this->employee()->id]])
            ->assertOk();

        $this->actingAs($owner)
            ->getJson(route('chat.messages.search', $group).'?q='.urlencode('добавил'))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /** Пустой запрос ничего не ищет — и ничего не стоит. */
    public function test_an_empty_query_finds_nothing(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $this->saidIn($conversation, $me, 'Что-нибудь');

        $this->actingAs($me)
            ->getJson(route('chat.messages.search', $conversation).'?q='.urlencode(''))
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.total', 0);
    }

    /**
     * Синтаксис полнотекстового поиска в запрос не протекает.
     *
     * «а & б» — это два слова, а не оператор: подобранная строка не должна ни
     * ронять запрос, ни делать больше, чем поиск.
     */
    public function test_query_syntax_from_the_user_is_harmless(): void
    {
        $me = $this->employee();
        $conversation = $this->conversationBetween($me, $this->employee());
        $this->saidIn($conversation, $me, 'Поставка задержана');

        $this->actingAs($me)
            ->getJson(route('chat.messages.search', $conversation).'?q='.urlencode("поставка & ':*|!("))
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
