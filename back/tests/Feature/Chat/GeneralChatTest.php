<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Actions\User\CreateUser;
use App\Data\User\NewUserData;
use App\Enums\ConversationKind;
use App\Enums\DeletionScope;
use App\Models\Conversation;
use App\Models\User;
use App\Support\Chat\GeneralChat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Общий чат компании: один на всех, и в нём все.
 *
 * Проверяется не «переписка работает» — за это отвечает MessengerTest, — а
 * три обещания, которых у обычной группы нет: он у каждого, писать в него
 * может каждый, и уйти из него не может никто.
 */
final class GeneralChatTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_everybody_sees_it_without_being_invited(): void
    {
        $somebody = User::factory()->create();

        $this->actingAs($somebody)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonPath('data.0.kind', ConversationKind::General->value)
            ->assertJsonPath('data.0.title', GeneralChat::TITLE)
            ->assertJsonPath('data.0.is_general', true)
            // Владельца у него нет: он не принадлежит никому.
            ->assertJsonPath('data.0.is_owner', false);
    }

    public function test_everybody_may_speak_in_it(): void
    {
        $general = app(GeneralChat::class)->conversation();

        $first = User::factory()->create();
        $second = User::factory()->create();

        // Оба попадают в состав, просто открыв мессенджер.
        $this->actingAs($first)->getJson(route('chat.conversations.index'))->assertOk();
        $this->actingAs($second)->getJson(route('chat.conversations.index'))->assertOk();

        $this->actingAs($first)
            ->postJson(route('chat.messages.store', $general), ['body' => 'Всем доброе утро'])
            ->assertCreated();

        $this->actingAs($second)
            ->getJson(route('chat.messages.index', $general))
            ->assertOk()
            ->assertJsonPath('data.0.body', 'Всем доброе утро');

        // И сказанное коллегой висит у второго непрочитанным.
        $this->actingAs($second)
            ->getJson(route('chat.unread'))
            ->assertOk()
            ->assertJsonPath('data.unread', 1);
    }

    public function test_a_new_colleague_is_in_it_from_the_first_day(): void
    {
        $general = app(GeneralChat::class)->conversation();

        $newcomer = app(CreateUser::class)->handle(new NewUserData(
            lastName: 'Новиков',
            firstName: 'Пётр',
            middleName: null,
            email: 'novikov@bismar.test',
            phone: '+79990001122',
            jobTitle: 'Продавец',
            hiredAt: null,
            workMode: null,
            password: 'secret-pass',
        ));

        // Не дожидаясь, пока он откроет мессенджер.
        $this->assertTrue($general->includes($newcomer));
    }

    public function test_nobody_leaves_it_and_nobody_deletes_it(): void
    {
        $general = app(GeneralChat::class)->conversation();
        $somebody = User::factory()->create();

        $this->actingAs($somebody)->getJson(route('chat.conversations.index'))->assertOk();

        $this->actingAs($somebody)
            ->postJson(route('chat.conversations.leave', $general))
            ->assertUnprocessable();

        $this->actingAs($somebody)
            ->deleteJson(route('chat.conversations.destroy', $general), [
                'scope' => DeletionScope::Everyone->value,
            ])
            ->assertUnprocessable();

        $this->assertTrue($general->refresh()->includes($somebody));
        $this->assertDatabaseHas('conversations', ['id' => $general->id]);
    }

    /**
     * Даже администратор: его пропускает Gate::before, и потому отказ стоит
     * не в политике, а перед нею.
     */
    public function test_not_even_an_administrator_leaves_it(): void
    {
        $general = app(GeneralChat::class)->conversation();
        $admin = $this->administrator();

        $this->actingAs($admin)->getJson(route('chat.conversations.index'))->assertOk();

        $this->actingAs($admin)
            ->postJson(route('chat.conversations.leave', $general))
            ->assertUnprocessable();

        $this->actingAs($admin)
            ->postJson(route('chat.participants.store', $general), ['user_ids' => [$admin->id]])
            ->assertUnprocessable();

        $this->assertTrue($general->refresh()->includes($admin));
    }

    public function test_it_cannot_be_started_a_second_time_from_the_outside(): void
    {
        $somebody = User::factory()->create();

        $this->actingAs($somebody)
            ->postJson(route('chat.conversations.store'), [
                'kind' => ConversationKind::General->value,
                'title' => 'Второй общий',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');

        $this->assertSame(
            1,
            Conversation::query()->where('kind', ConversationKind::General)->count(),
        );
    }

    /** Приглушить общий чат можно: звук — личное дело, участие — нет. */
    public function test_it_can_be_muted(): void
    {
        $general = app(GeneralChat::class)->conversation();
        $somebody = User::factory()->create();

        $this->actingAs($somebody)->getJson(route('chat.conversations.index'))->assertOk();

        $this->actingAs($somebody)
            ->postJson(route('chat.conversations.mute', $general), ['muted' => true])
            ->assertOk();

        $this->actingAs($somebody)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonPath('data.0.is_muted', true);

        $this->assertTrue($general->refresh()->includes($somebody));
    }
}
