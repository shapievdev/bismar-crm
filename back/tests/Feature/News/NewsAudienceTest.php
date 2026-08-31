<?php

declare(strict_types=1);

namespace Tests\Feature\News;

use App\Enums\DepartmentRole;
use App\Enums\NewsAudience;
use App\Enums\NewsStatus;
use App\Enums\Permission;
use App\Jobs\SendPush;
use App\Models\Department;
use App\Models\Group;
use App\Models\News;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Кому адресована новость: людям, отделам и группам — и всем трём разом.
 *
 * Состав отдела и группы читается на каждом обращении, а не замораживается при
 * публикации: пришедший в отдел завтра увидит адресованное отделу вчера, и это
 * ровно то, ради чего адресуют отделу, а не двадцати фамилиям.
 */
final class NewsAudienceTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    private function editor(): User
    {
        return $this->userWith(Permission::ManageNews);
    }

    private function department(string $name, ?Department $parent = null): Department
    {
        return Department::factory()->create([
            'name' => $name,
            'parent_id' => ($parent ?? Department::query()->whereNull('parent_id')->firstOrFail())->getKey(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return [
            'title' => 'Переезд склада',
            'status' => NewsStatus::Published->value,
            'audience' => NewsAudience::Selected->value,
            ...$overrides,
        ];
    }

    /* ---------- Отдел ---------- */

    /**
     * Адресуясь направлению, адресуются и всему, что под ним: перечислять
     * подотделы руками значит забыть о том, который завели завтра.
     */
    public function test_a_news_item_addressed_to_a_department_reaches_its_whole_branch(): void
    {
        $warehouse = $this->department('Склад');
        $shift = $this->department('Ночная смена', $warehouse);

        $keeper = User::factory()->create();
        $nightly = User::factory()->create();
        $seller = User::factory()->create();

        $warehouse->people()->attach($keeper->id, ['role' => DepartmentRole::Head->value]);
        $shift->people()->attach($nightly->id, ['role' => DepartmentRole::Member->value]);
        $this->department('Коммерция')->people()->attach($seller->id, ['role' => DepartmentRole::Member->value]);

        $news = News::factory()->published()->addressed()->create();
        $news->departments()->attach($warehouse);

        foreach ([$keeper, $nightly] as $reader) {
            $this->actingAs($reader)->getJson(route('news.index'))->assertOk()->assertJsonCount(1, 'data');
            $this->actingAs($reader)->getJson(route('news.show', $news))->assertOk();
        }

        $this->actingAs($seller)->getJson(route('news.index'))->assertOk()->assertJsonCount(0, 'data');
        $this->actingAs($seller)->getJson(route('news.show', $news))->assertForbidden();
    }

    /** Пришедший в отдел позже видит адресованное отделу раньше. */
    public function test_someone_joining_the_department_later_sees_it(): void
    {
        $warehouse = $this->department('Склад');
        $newcomer = User::factory()->create();

        $news = News::factory()->published()->addressed()->create();
        $news->departments()->attach($warehouse);

        $this->actingAs($newcomer)->getJson(route('news.index'))->assertOk()->assertJsonCount(0, 'data');

        $warehouse->people()->attach($newcomer->id, ['role' => DepartmentRole::Member->value]);

        $this->actingAs($newcomer)->getJson(route('news.index'))->assertOk()->assertJsonCount(1, 'data');
    }

    /* ---------- Группа ---------- */

    public function test_a_news_item_addressed_to_a_group_reaches_only_its_people(): void
    {
        $mentors = Group::factory()->create(['name' => 'Наставники']);
        $mentor = User::factory()->create();
        $other = User::factory()->create();

        $mentors->members()->attach($mentor->id);

        $news = News::factory()->published()->addressed()->create();
        $news->groups()->attach($mentors);

        $this->actingAs($mentor)->getJson(route('news.index'))->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($other)->getJson(route('news.index'))->assertOk()->assertJsonCount(0, 'data');
    }

    /* ---------- Все три вида вместе ---------- */

    public function test_the_three_kinds_of_addressee_add_up(): void
    {
        $editor = $this->editor();

        $warehouse = $this->department('Склад');
        $mentors = Group::factory()->create();

        $keeper = User::factory()->create();
        $mentor = User::factory()->create();
        $named = User::factory()->create();
        $stranger = User::factory()->create();

        $warehouse->people()->attach($keeper->id, ['role' => DepartmentRole::Member->value]);
        $mentors->members()->attach($mentor->id);

        $this->actingAs($editor)
            ->postJson(route('news.store'), $this->payload([
                'recipients' => [$named->id],
                'department_ids' => [$warehouse->id],
                'group_ids' => [$mentors->id],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.departments.0.name', 'Склад')
            ->assertJsonCount(1, 'data.groups');

        $news = News::query()->sole();

        foreach ([$keeper, $mentor, $named] as $reader) {
            $this->actingAs($reader)->getJson(route('news.show', $news))->assertOk();
        }

        $this->actingAs($stranger)->getJson(route('news.show', $news))->assertForbidden();
    }

    /**
     * Знаменатель ознакомлений — те же люди, и каждый по одному разу: назвав
     * человека поимённо и его отдел целиком, «3 из 20» не превращают в «3 из 21».
     */
    public function test_the_audience_is_counted_once_per_person(): void
    {
        $editor = $this->editor();

        $warehouse = $this->department('Склад');
        $keeper = User::factory()->create();
        $warehouse->people()->attach($keeper->id, ['role' => DepartmentRole::Member->value]);

        $this->actingAs($editor)
            ->postJson(route('news.store'), $this->payload([
                'requires_acknowledgement' => true,
                'recipients' => [$keeper->id],
                'department_ids' => [$warehouse->id],
            ]))
            ->assertCreated()
            ->assertJsonPath('data.audience_size', 1);
    }

    /** Уволенный в знаменатель не идёт: платформа ему закрыта. */
    public function test_a_dismissed_person_is_out_of_the_count(): void
    {
        $group = Group::factory()->create();
        $group->members()->attach([User::factory()->create()->id, User::factory()->dismissed()->create()->id]);

        $news = News::factory()->published()->addressed()->create();
        $news->groups()->attach($group);

        $this->actingAs($this->editor())
            ->getJson(route('news.show', $news))
            ->assertOk()
            ->assertJsonPath('data.audience_size', 1);
    }

    /* ---------- Правка адресатов ---------- */

    public function test_an_addressed_news_item_may_go_out_with_only_a_department(): void
    {
        $warehouse = $this->department('Склад');

        $this->actingAs($this->editor())
            ->postJson(route('news.store'), $this->payload(['department_ids' => [$warehouse->id]]))
            ->assertCreated();
    }

    public function test_publishing_without_any_addressee_is_refused(): void
    {
        $this->actingAs($this->editor())
            ->postJson(route('news.store'), $this->payload([
                'recipients' => [],
                'department_ids' => [],
                'group_ids' => [],
            ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipients');
    }

    /**
     * Оставленные адресаты ожили бы при обратном переключении и адресовали бы
     * новость тем, кого сегодня никто не выбирал.
     */
    public function test_switching_back_to_everyone_forgets_departments_and_groups(): void
    {
        $editor = $this->editor();
        $warehouse = $this->department('Склад');
        $group = Group::factory()->create();

        $this->actingAs($editor)->postJson(route('news.store'), $this->payload([
            'department_ids' => [$warehouse->id],
            'group_ids' => [$group->id],
        ]))->assertCreated();

        $news = News::query()->sole();

        $this->actingAs($editor)
            ->putJson(route('news.update', $news), $this->payload(['audience' => NewsAudience::Everyone->value]))
            ->assertOk();

        $this->assertSame(0, $news->departments()->count());
        $this->assertSame(0, $news->groups()->count());
    }

    /* ---------- Уведомление ---------- */

    public function test_the_notification_goes_to_the_whole_addressed_audience(): void
    {
        Queue::fake();

        $editor = $this->editor();
        $warehouse = $this->department('Склад');
        $group = Group::factory()->create();

        $keeper = User::factory()->create();
        $mentor = User::factory()->create();
        $stranger = User::factory()->create();

        $warehouse->people()->attach($keeper->id, ['role' => DepartmentRole::Member->value]);
        $group->members()->attach($mentor->id);

        $this->actingAs($editor)->postJson(route('news.store'), $this->payload([
            'department_ids' => [$warehouse->id],
            'group_ids' => [$group->id],
        ]))->assertCreated();

        Queue::assertPushed(SendPush::class, function (SendPush $job) use ($keeper, $mentor, $stranger, $editor): bool {
            /** @var list<int> $recipients */
            $recipients = (fn (): array => $this->userIds)->call($job);

            return in_array($keeper->id, $recipients, true)
                && in_array($mentor->id, $recipients, true)
                && ! in_array($stranger->id, $recipients, true)
                // Автору не шлём: он знает, что нажал «опубликовать».
                && ! in_array($editor->id, $recipients, true);
        });
    }
}
