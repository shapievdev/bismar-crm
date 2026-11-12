<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Enums\ConversationKind;
use App\Enums\DeletionScope;
use App\Models\Achievement;
use App\Models\AchievementAward;
use App\Models\Conversation;
use App\Models\OnboardingTemplate;
use App\Models\User;
use App\Support\Chat\Notifications;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * «Уведомления» — личная лента, в которую пишет приложение.
 *
 * Проверяется не «переписка работает» — за это отвечает MessengerTest, — а
 * то, чем эта лента от переписки отличается: заводится сама, в ней не
 * говорят, из неё не выходят, и чужая не видна.
 */
final class NotificationsTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_the_feed_appears_with_the_first_notification(): void
    {
        $somebody = User::factory()->create();

        // Пока уведомлений нет, пустой строчки в списке тоже нет.
        $this->actingAs($somebody)
            ->getJson(route('chat.conversations.index'))
            ->assertOk()
            ->assertJsonMissing(['kind' => ConversationKind::System->value]);

        app(Notifications::class)->send($somebody, 'Проверка связи');

        // Ищем ленту по виду, а не по месту в списке: рядом стоит общий чат
        // компании, и обе переписки заведены в одну и ту же секунду.
        $feed = collect(
            $this->actingAs($somebody)
                ->getJson(route('chat.conversations.index'))
                ->assertOk()
                ->json('data'),
        )->firstWhere('kind', ConversationKind::System->value);

        $this->assertNotNull($feed);
        $this->assertSame(Notifications::TITLE, $feed['title']);
        $this->assertTrue($feed['is_system']);
        $this->assertSame(1, $feed['unread_count']);
    }

    public function test_a_feed_is_one_per_person_and_nobody_else_sees_it(): void
    {
        $mine = User::factory()->create();
        $stranger = User::factory()->create();

        $notifications = app(Notifications::class);

        $notifications->send($mine, 'Первое');
        $notifications->send($mine, 'Второе');

        $feed = $notifications->conversationFor($mine);

        $this->assertSame(
            1,
            Conversation::query()
                ->where('kind', ConversationKind::System)
                ->where('created_by_id', $mine->getKey())
                ->count(),
        );

        $this->actingAs($stranger)
            ->getJson(route('chat.messages.index', $feed))
            ->assertForbidden();
    }

    public function test_nobody_writes_into_it(): void
    {
        $somebody = User::factory()->create();
        $feed = app(Notifications::class)->conversationFor($somebody);

        $this->actingAs($somebody)
            ->postJson(route('chat.messages.store', $feed), ['body' => 'Спасибо!'])
            ->assertUnprocessable();

        // И администратор тоже: его пропускает Gate::before, поэтому отказ
        // стоит перед проверкой прав.
        $this->actingAs($this->administrator())
            ->postJson(route('chat.messages.store', $feed), ['body' => 'И я'])
            ->assertUnprocessable();

        $this->assertSame(0, $feed->messages()->whereNotNull('user_id')->count());
    }

    public function test_it_is_not_left_and_not_erased_for_everyone(): void
    {
        $somebody = User::factory()->create();
        $feed = app(Notifications::class)->conversationFor($somebody);

        $this->actingAs($somebody)
            ->postJson(route('chat.conversations.leave', $feed))
            ->assertUnprocessable();

        $this->actingAs($somebody)
            ->deleteJson(route('chat.conversations.destroy', $feed), [
                'scope' => DeletionScope::Everyone->value,
            ])
            ->assertUnprocessable();

        // А убрать у себя — можно: прочитанные уведомления тем и кончаются.
        $this->actingAs($somebody)
            ->deleteJson(route('chat.conversations.destroy', $feed), [
                'scope' => DeletionScope::Mine->value,
            ])
            ->assertOk();
    }

    /**
     * Первое, ради чего лента заведена: закрытый шаг маршрута выдаёт значок,
     * и человек узнаёт об этом здесь.
     */
    public function test_an_earned_achievement_arrives_as_a_notification(): void
    {
        $badge = Achievement::query()->create([
            'title' => 'Первый день позади',
            'description' => 'Закрыл первый этап маршрута',
        ]);

        $trainee = $this->trainee();

        $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($badge): void {
            $template->stages()->first()->update(['achievement_id' => $badge->getKey()]);
        });

        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();

        $feed = app(Notifications::class)->conversationFor($trainee);

        $this->actingAs($trainee)
            ->getJson(route('chat.messages.index', $feed))
            ->assertOk()
            ->assertJsonPath('data.0.kind', 'system')
            ->assertJsonPath('data.0.body', 'Новое достижение: «Первый день позади»')
            // Карточка под текстом: сам значок и то, за что он получен.
            ->assertJsonPath('data.0.about.kind', 'achievement')
            ->assertJsonPath('data.0.about.kind_label', 'Достижение')
            ->assertJsonPath('data.0.about.title', 'Первый день позади')
            ->assertJsonPath('data.0.about.context', 'Этап «Этап 1»')
            ->assertJsonPath('data.0.about.url', '/profile');
    }

    /**
     * Несказанное досылается командой — и ровно один раз.
     *
     * Дыра здесь возможна: значок выдан до того, как завели ленту; запись в
     * ленту упала; уведомление удалили. Отменить выдачу нельзя, а пересчёт
     * маршрута молчит — он видит, что значок уже есть.
     */
    public function test_a_missed_announcement_is_delivered_later_and_only_once(): void
    {
        $badge = Achievement::query()->create(['title' => 'Молчаливый']);
        $somebody = User::factory()->create();

        // Выдача без рассказа — ровно то, что осталось от старых значков.
        AchievementAward::query()->create([
            'achievement_id' => $badge->getKey(),
            'user_id' => $somebody->getKey(),
            'awarded_at' => now(),
            'reason' => 'Этап «Первая неделя»',
        ]);

        $this->artisan('chat:announce-achievements')->assertSuccessful();

        $feed = app(Notifications::class)->conversationFor($somebody);

        $this->assertSame(1, $feed->messages()->count());
        $this->assertSame(
            'Новое достижение: «Молчаливый»',
            $feed->messages()->latest('id')->value('body'),
        );

        // Второй прогон ничего не добавляет: несказанное ищется по факту, а не
        // по памяти.
        $this->artisan('chat:announce-achievements')->assertSuccessful();

        $this->assertSame(1, $feed->messages()->count());
    }

    /** Повторный пересчёт маршрута не шлёт второе уведомление о том же значке. */
    public function test_the_same_achievement_is_announced_once(): void
    {
        $badge = Achievement::query()->create(['title' => 'Один раз']);
        $trainee = $this->trainee();

        $this->journeyFor($trainee, stages: 1, prepare: function (OnboardingTemplate $template) use ($badge): void {
            $template->stages()->first()->update(['achievement_id' => $badge->getKey()]);
        });

        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();
        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();
        $this->actingAs($trainee)->getJson(route('onboarding.my'))->assertOk();

        $this->assertSame(1, app(Notifications::class)->conversationFor($trainee)->messages()->count());
    }
}
