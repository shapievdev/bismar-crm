<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Enums\ConversationKind;
use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use App\Enums\Permission;
use App\Jobs\SendPush;
use App\Models\PushSubscription;
use App\Support\Push\PushMessage;
use App\Support\Push\WebPushSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Уведомления на устройство: подписка, отписка и поводы, по которым они уходят.
 */
final class PushNotificationTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * @return array{endpoint: string, public_key: string, auth_token: string, device?: string}
     */
    private function subscription(string $endpoint = 'https://fcm.googleapis.com/fcm/send/abc'): array
    {
        return [
            'endpoint' => $endpoint,
            'public_key' => 'BPublicKeyOfTheDevice',
            'auth_token' => 'AuthTokenOfTheDevice',
            'device' => 'iPhone',
        ];
    }

    /* ---------- Подписка ---------- */

    public function test_a_device_subscribes_and_unsubscribes(): void
    {
        $user = $this->learner();

        $this->actingAs($user)
            ->postJson(route('push.store'), $this->subscription())
            ->assertNoContent();

        $this->assertDatabaseHas('push_subscriptions', [
            'user_id' => $user->getKey(),
            'device' => 'iPhone',
        ]);

        $this->actingAs($user)
            ->deleteJson(route('push.destroy'), ['endpoint' => $this->subscription()['endpoint']])
            ->assertNoContent();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    /**
     * На одном компьютере сменились двое: подписка достаётся тому, кто вошёл
     * сейчас, а не остаётся у прежнего.
     */
    public function test_the_same_device_moves_to_whoever_signed_in(): void
    {
        $first = $this->learner();
        $second = $this->learner();

        $this->actingAs($first)->postJson(route('push.store'), $this->subscription())->assertNoContent();
        $this->actingAs($second)->postJson(route('push.store'), $this->subscription())->assertNoContent();

        $this->assertSame(1, PushSubscription::query()->count());
        $this->assertSame($second->getKey(), PushSubscription::query()->firstOrFail()->user_id);
    }

    public function test_a_dismissed_person_loses_their_subscriptions(): void
    {
        $user = $this->learner();

        $this->actingAs($user)->postJson(route('push.store'), $this->subscription())->assertNoContent();

        $this->actingAs($this->administrator())
            ->postJson(route('users.dismiss', $user))
            ->assertOk();

        $this->assertSame(0, PushSubscription::query()->count());
    }

    /* ---------- Поводы ---------- */

    public function test_a_message_notifies_the_other_side_but_not_the_author(): void
    {
        Queue::fake();

        $author = $this->learner();
        $companion = $this->learner();

        // Переписка заводится тем же путём, что и в приложении: своей фабрики
        // у разговора нет, и заводить её ради одного теста незачем.
        $conversation = $this->actingAs($author)
            ->postJson(route('chat.conversations.store'), [
                'kind' => ConversationKind::Direct->value,
                'user_id' => $companion->id,
            ])
            ->assertCreated()
            ->json('data.id');

        $this->actingAs($author)
            ->postJson(route('chat.messages.store', $conversation), ['body' => 'Зайдите, пожалуйста'])
            ->assertCreated();

        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($companion): bool {
            return $this->recipientsOf($job) === [$companion->getKey()];
        });
    }

    public function test_a_published_news_notifies_everyone_except_its_author(): void
    {
        Queue::fake();

        $author = $this->userWith(Permission::ManageNews, Permission::ViewCourses);
        $reader = $this->learner();

        $this->actingAs($author)
            ->postJson(route('news.store'), [
                'title' => 'Переезд склада',
                'excerpt' => null,
                'content_json' => null,
                'status' => NewsStatus::Published->value,
                'is_pinned' => false,
                'audience' => NewsAudience::Everyone->value,
                'requires_acknowledgement' => true,
                'recipients' => [],
                'links' => [],
            ])
            ->assertCreated();

        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($author, $reader): bool {
            $recipients = $this->recipientsOf($job);

            return in_array($reader->getKey(), $recipients, true)
                && ! in_array($author->getKey(), $recipients, true);
        });
    }

    /**
     * Правка уже вышедшей новости не будит компанию во второй раз.
     */
    public function test_editing_a_published_news_notifies_nobody(): void
    {
        $author = $this->userWith(Permission::ManageNews, Permission::ViewCourses);

        $created = $this->actingAs($author)
            ->postJson(route('news.store'), [
                'title' => 'Переезд склада',
                'excerpt' => null,
                'content_json' => null,
                'status' => NewsStatus::Published->value,
                'is_pinned' => false,
                'audience' => NewsAudience::Everyone->value,
                'requires_acknowledgement' => false,
                'recipients' => [],
                'links' => [],
            ])
            ->assertCreated()
            ->json('data');

        Queue::fake();

        $this->actingAs($author)
            ->putJson(route('news.update', $created['slug']), [
                'title' => 'Переезд склада — уточнение',
                'excerpt' => null,
                'content_json' => null,
                'status' => NewsStatus::Published->value,
                'is_pinned' => false,
                'audience' => NewsAudience::Everyone->value,
                'requires_acknowledgement' => false,
                'recipients' => [],
                'links' => [],
            ])
            ->assertOk();

        Queue::assertNotPushed(SendPush::class);
    }

    /* ---------- Отправка ---------- */

    /**
     * Ключей нет — приложение живёт как жило: отправка тихо ничего не делает,
     * а не роняет очередь на каждом сообщении.
     */
    public function test_nothing_is_sent_while_the_keys_are_empty(): void
    {
        config()->set('push.vapid.public_key', null);
        config()->set('push.vapid.private_key', null);

        $sender = new WebPushSender;

        $this->assertFalse($sender->isConfigured());

        $user = $this->learner();
        $subscription = PushSubscription::query()->create([
            'user_id' => $user->getKey(),
            ...$this->subscription(),
        ]);

        $sender->send(new Collection([$subscription]), new PushMessage('Заголовок', 'Текст', '/', 'tag'));

        // Строка на месте: её удаляют, только когда служба доставки сказала,
        // что адреса больше нет.
        $this->assertDatabaseCount('push_subscriptions', 1);
    }

    public function test_a_long_body_is_shortened_for_the_screen(): void
    {
        $this->assertSame('короткий текст', PushMessage::shorten('  короткий   текст '));
        $this->assertSame(120, mb_strlen(PushMessage::shorten(str_repeat('а', 400))));
        $this->assertSame('', PushMessage::shorten(null));
    }

    /**
     * @return list<int>
     */
    private function recipientsOf(SendPush $job): array
    {
        $recipients = (fn (): array => $this->userIds)->call($job);

        sort($recipients);

        return $recipients;
    }
}
