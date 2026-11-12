<?php

declare(strict_types=1);

namespace Tests\Feature\Achievements;

use App\Enums\Permission;
use App\Models\Achievement;
use App\Models\AchievementAward;
use App\Models\OnboardingTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class AchievementCatalogueTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function test_the_catalogue_is_kept_by_the_right_and_read_by_everybody(): void
    {
        $keeper = $this->userWith(Permission::ManageAchievements);

        $this->actingAs($keeper)
            ->postJson(route('achievements.store'), [
                'title' => 'Первый выход в зал',
                'description' => 'Отработал первую смену рядом с наставником',
            ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Первый выход в зал')
            ->assertJsonPath('data.image_url', null)
            ->assertJsonPath('data.people_count', 0);

        // Читает справочник всякий, кто вошёл: значок стоит в чужом профиле и
        // в этапе маршрута, и без названия оба были бы набором номеров.
        $this->actingAs(User::factory()->create())
            ->getJson(route('achievements.index'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Первый выход в зал');
    }

    public function test_without_the_right_the_catalogue_is_read_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('achievements.store'), ['title' => 'Самозванец'])
            ->assertForbidden();

        $this->assertDatabaseCount('achievements', 0);
    }

    public function test_the_picture_replaces_the_one_it_supersedes(): void
    {
        $keeper = $this->userWith(Permission::ManageAchievements);
        $achievement = Achievement::query()->create(['title' => 'Сдал на кассу']);

        $this->actingAs($keeper)
            ->postJson(route('achievements.image.store', $achievement), [
                'image' => UploadedFile::fake()->image('значок.png', 256, 256),
            ])
            ->assertOk();

        $first = $achievement->refresh()->image_path;
        $this->assertNotNull($first);
        Storage::disk('s3')->assertExists($first);

        $this->actingAs($keeper)
            ->postJson(route('achievements.image.store', $achievement), [
                'image' => UploadedFile::fake()->image('другой.png', 256, 256),
            ])
            ->assertOk();

        // Вытесненный файл не копится в бакете.
        Storage::disk('s3')->assertMissing($first);
        Storage::disk('s3')->assertExists($achievement->refresh()->image_path);
    }

    public function test_an_svg_is_refused(): void
    {
        $keeper = $this->userWith(Permission::ManageAchievements);
        $achievement = Achievement::query()->create(['title' => 'Значок']);

        $this->actingAs($keeper)
            ->postJson(route('achievements.image.store', $achievement), [
                'image' => UploadedFile::fake()->create('значок.svg', 10, 'image/svg+xml'),
            ])
            ->assertUnprocessable();
    }

    public function test_hiding_an_achievement_leaves_it_with_those_who_earned_it(): void
    {
        $keeper = $this->userWith(Permission::ManageAchievements);
        $achievement = Achievement::query()->create(['title' => 'Старый значок']);
        $owner = User::factory()->create();

        AchievementAward::query()->create([
            'achievement_id' => $achievement->getKey(),
            'user_id' => $owner->getKey(),
            'awarded_at' => now(),
            'reason' => 'Этап «Первая неделя»',
        ]);

        $this->actingAs($keeper)
            ->deleteJson(route('achievements.destroy', $achievement))
            ->assertNoContent();

        // Из выбора ушло…
        $this->actingAs($keeper)
            ->getJson(route('achievements.index'))
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // …а в профиле осталось.
        $this->actingAs($owner)
            ->getJson(route('profile.achievements'))
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Старый значок')
            ->assertJsonPath('data.0.reason', 'Этап «Первая неделя»');

        // И возвращается туда, откуда его убрали.
        $this->actingAs($keeper)
            ->postJson(route('achievements.restore', $achievement->getKey()))
            ->assertOk()
            ->assertJsonPath('data.is_hidden', false);
    }

    public function test_a_hidden_achievement_cannot_be_pinned_to_a_step(): void
    {
        $achievement = Achievement::query()->create(['title' => 'Спрятанный']);
        $achievement->delete();

        $hr = $this->userWith(Permission::ManageOnboarding);
        $template = OnboardingTemplate::factory()->create();

        $this->actingAs($hr)
            ->postJson(route('onboarding.manage.stages.save', $template), [
                'title' => 'Первый день',
                'day_offset' => 1,
                'achievement_id' => $achievement->getKey(),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('achievement_id');
    }
}
