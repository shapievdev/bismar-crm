<?php

declare(strict_types=1);

namespace Tests\Feature\Push;

use App\Enums\BroadcastAudience;
use App\Enums\DepartmentRole;
use App\Enums\Permission;
use App\Jobs\SendPush;
use App\Models\Department;
use App\Models\PushBroadcast;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Рассылки уведомлений: кому уходит и кто вправе отправлять.
 */
final class BroadcastTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function letter(array $overrides = []): array
    {
        return [
            'title' => 'Склад закрыт до понедельника',
            'body' => 'Приёмка не работает, заявки принимаем в мессенджере.',
            'url' => '/news',
            'audience' => BroadcastAudience::Everyone->value,
            ...$overrides,
        ];
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

    private function department(string $name, ?Department $parent = null): Department
    {
        return Department::factory()->create([
            'name' => $name,
            'parent_id' => ($parent ?? Department::query()->whereNull('parent_id')->firstOrFail())->getKey(),
        ]);
    }

    /* ---------- Кому уходит ---------- */

    public function test_a_broadcast_to_everyone_reaches_every_working_person(): void
    {
        Queue::fake();

        $administrator = $this->administrator();
        $colleague = $this->learner();
        $dismissed = User::factory()->dismissed()->create();

        $this->actingAs($administrator)
            ->postJson(route('push.broadcasts.store'), $this->letter())
            ->assertCreated()
            ->assertJsonPath('data.audience', BroadcastAudience::Everyone->value)
            ->assertJsonPath('data.title', 'Склад закрыт до понедельника');

        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($administrator, $colleague, $dismissed): bool {
            $recipients = $this->recipientsOf($job);

            return in_array($colleague->id, $recipients, true)
                // Автор из адресатов не вычитается: своё уведомление на своём
                // телефоне — доказательство, что рассылка дошла.
                && in_array($administrator->id, $recipients, true)
                // Уволенному платформа закрыта, и звать его туда незачем.
                && ! in_array($dismissed->id, $recipients, true);
        });

        $broadcast = PushBroadcast::query()->firstOrFail();

        $this->assertSame(2, $broadcast->recipients_count);
        $this->assertSame(0, $broadcast->devices_count);
        $this->assertSame($administrator->id, $broadcast->author_id);
    }

    public function test_a_broadcast_to_chosen_people_reaches_only_them(): void
    {
        Queue::fake();

        $chosen = $this->learner();
        $other = $this->learner();

        $this->actingAs($this->administrator())
            ->postJson(route('push.broadcasts.store'), $this->letter([
                'audience' => BroadcastAudience::Selected->value,
                'user_ids' => [$chosen->id],
            ]))
            ->assertCreated();

        Queue::assertPushed(SendPush::class, fn (SendPush $job): bool => $this->recipientsOf($job) === [$chosen->id]);

        // Названные поимённо остаются в истории: по рассылке «всем» список не
        // хранится, а здесь он и есть смысл отправки.
        $this->assertSame(
            [$chosen->id],
            PushBroadcast::query()->firstOrFail()->recipients()->pluck('users.id')->all(),
        );
    }

    /**
     * Рассылка отделу касается и его подотделов: перечислять их руками значит
     * забыть о том, который завели завтра.
     */
    public function test_a_broadcast_to_a_department_reaches_its_whole_branch(): void
    {
        Queue::fake();

        $warehouse = $this->department('Склад');
        $shift = $this->department('Ночная смена', $warehouse);
        $elsewhere = $this->department('Коммерция');

        $keeper = $this->learner();
        $nightly = $this->learner();
        $seller = $this->learner();

        $warehouse->people()->attach($keeper->id, ['role' => DepartmentRole::Head->value]);
        $shift->people()->attach($nightly->id, ['role' => DepartmentRole::Member->value]);
        $elsewhere->people()->attach($seller->id, ['role' => DepartmentRole::Member->value]);

        $this->actingAs($this->administrator())
            ->postJson(route('push.broadcasts.store'), $this->letter([
                'audience' => BroadcastAudience::Department->value,
                'department_id' => $warehouse->id,
            ]))
            ->assertCreated();

        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($keeper, $nightly, $seller): bool {
            $recipients = $this->recipientsOf($job);

            return $recipients === collect([$keeper->id, $nightly->id])->sort()->values()->all()
                && ! in_array($seller->id, $recipients, true);
        });
    }

    public function test_a_broadcast_with_nobody_to_reach_is_refused(): void
    {
        Queue::fake();

        $empty = $this->department('Пустой отдел');

        $this->actingAs($this->administrator())
            ->postJson(route('push.broadcasts.store'), $this->letter([
                'audience' => BroadcastAudience::Department->value,
                'department_id' => $empty->id,
            ]))
            ->assertStatus(409);

        Queue::assertNotPushed(SendPush::class);
        $this->assertSame(0, PushBroadcast::query()->count());
    }

    /* ---------- Кто вправе ---------- */

    /**
     * Рассылка — громкое действие: телефон звонит у всей компании, и права,
     * отмеченного галочкой, для такого мало.
     */
    public function test_only_an_administrator_may_send(): void
    {
        Queue::fake();

        $this->actingAs($this->userWith(Permission::ManageUsers, Permission::ManageNews))
            ->postJson(route('push.broadcasts.store'), $this->letter())
            ->assertForbidden();

        $this->actingAs($this->learner())
            ->getJson(route('push.broadcasts.index'))
            ->assertForbidden();

        Queue::assertNotPushed(SendPush::class);
    }

    public function test_a_link_outside_the_application_is_refused(): void
    {
        $this->actingAs($this->administrator())
            ->postJson(route('push.broadcasts.store'), $this->letter(['url' => 'https://example.com']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('url');
    }

    /* ---------- История ---------- */

    public function test_the_history_shows_what_was_sent(): void
    {
        Queue::fake();

        $administrator = $this->administrator();

        $this->actingAs($administrator)->postJson(route('push.broadcasts.store'), $this->letter())->assertCreated();

        $this->actingAs($administrator)
            ->getJson(route('push.broadcasts.index'))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Склад закрыт до понедельника')
            ->assertJsonPath('data.0.audience_label', 'Всем сотрудникам')
            ->assertJsonPath('data.0.author', $administrator->name);
    }
}
