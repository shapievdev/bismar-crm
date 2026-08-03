<?php

declare(strict_types=1);

namespace Tests\Feature\Profile;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ActsAsSpaClient;
use Tests\TestCase;

final class ProfileTest extends TestCase
{
    use ActsAsSpaClient, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('s3');
    }

    public function test_a_user_can_rename_themselves(): void
    {
        $user = User::factory()->create(['name' => 'Старое имя']);

        $this->actingAs($user)
            ->putJson(route('profile.update'), [
                'name' => 'Ада Лавлейс',
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ада Лавлейс');

        $this->assertSame('Ада Лавлейс', $user->refresh()->name);
    }

    public function test_saving_without_changing_the_email_is_allowed(): void
    {
        $user = User::factory()->create();

        // The uniqueness rule has to ignore the user's own row, or nobody could
        // ever edit their name without also changing their address.
        $this->actingAs($user)
            ->putJson(route('profile.update'), ['name' => 'Новое имя', 'email' => $user->email])
            ->assertOk();
    }

    public function test_an_email_taken_by_someone_else_is_refused(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.update'), ['name' => $user->name, 'email' => $other->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_guest_cannot_touch_a_profile(): void
    {
        $this->putJson(route('profile.update'), ['name' => 'Никто', 'email' => 'no@example.com'])
            ->assertUnauthorized();
    }

    public function test_an_avatar_can_be_uploaded(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('profile.avatar.store'), [
                'avatar' => UploadedFile::fake()->image('фото.jpg', 400, 400),
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $user->id);

        $stored = $user->refresh();

        $this->assertNotNull($stored->avatar_path);
        Storage::disk('s3')->assertExists($stored->avatar_path);
        // The key is generated, never taken from the client's filename.
        $this->assertStringStartsWith("avatars/{$user->id}/", $stored->avatar_path);
    }

    public function test_replacing_an_avatar_removes_the_previous_object(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('profile.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('first.jpg'),
        ])->assertOk();

        $first = $user->refresh()->avatar_path;

        $this->actingAs($user)->postJson(route('profile.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('second.jpg'),
        ])->assertOk();

        $second = $user->refresh()->avatar_path;

        $this->assertNotSame($first, $second);
        Storage::disk('s3')->assertMissing($first);
        Storage::disk('s3')->assertExists($second);
    }

    public function test_an_avatar_can_be_removed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('profile.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('фото.png'),
        ])->assertOk();

        $path = $user->refresh()->avatar_path;

        $this->actingAs($user)
            ->deleteJson(route('profile.avatar.destroy'))
            ->assertOk()
            ->assertJsonPath('data.avatar_url', null);

        $this->assertNull($user->refresh()->avatar_path);
        Storage::disk('s3')->assertMissing($path);
    }

    public function test_a_non_image_is_refused_as_an_avatar(): void
    {
        $this->actingAs(User::factory()->create())
            ->postJson(route('profile.avatar.store'), [
                'avatar' => UploadedFile::fake()->create('notes.pdf', 20, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('avatar');
    }

    public function test_the_signed_in_user_carries_their_avatar_url(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson(route('profile.avatar.store'), [
            'avatar' => UploadedFile::fake()->image('фото.png'),
        ])->assertOk();

        $this->actingAs($user)
            ->getJson(route('auth.user'))
            ->assertOk()
            ->assertJsonPath('data.name', $user->name);

        $this->assertNotNull($user->refresh()->avatarUrl());
    }
}
