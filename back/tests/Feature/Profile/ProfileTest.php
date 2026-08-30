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
        $user = User::factory()->create(['last_name' => 'Старый', 'first_name' => 'Имярек']);

        $this->actingAs($user)
            ->putJson(route('profile.update'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'middle_name' => 'Августовна',
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJsonPath('data.last_name', 'Лавлейс')
            ->assertJsonPath('data.first_name', 'Ада')
            ->assertJsonPath('data.middle_name', 'Августовна')
            // The joined form is derived, never stored.
            ->assertJsonPath('data.name', 'Лавлейс Ада Августовна');

        $this->assertSame('Лавлейс Ада Августовна', $user->refresh()->name);
    }

    /**
     * Телефон и должность человек ведёт сам — за такой правкой к
     * администратору не ходят.
     */
    public function test_a_user_sets_their_own_phone_and_job_title(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.update'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => $user->email,
                'phone' => '8 (999) 000-99-77',
                'job_title' => 'Программист',
            ])
            ->assertOk()
            // Набрано через восьмёрку, а хранится одним видом.
            ->assertJsonPath('data.phone', '+79990009977')
            ->assertJsonPath('data.job_title', 'Программист');

        $user->refresh();

        $this->assertSame('+79990009977', $user->phone);
        $this->assertSame('Программист', $user->job_title);
    }

    public function test_a_wrong_number_is_refused_in_the_profile(): void
    {
        $user = User::factory()->create(['phone' => '+79990009977']);

        $this->actingAs($user)
            ->putJson(route('profile.update'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => $user->email,
                'phone' => '12-34',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame('+79990009977', $user->refresh()->phone);
    }

    /** Пустое поле — это «убрать», как и с отчеством. */
    public function test_a_phone_and_a_job_title_are_cleared_from_the_profile(): void
    {
        $user = User::factory()->create(['phone' => '+79990009977', 'job_title' => 'Стажёр']);

        $this->actingAs($user)
            ->putJson(route('profile.update'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => $user->email,
                'phone' => '',
                'job_title' => null,
            ])
            ->assertOk();

        $user->refresh();

        $this->assertNull($user->phone);
        $this->assertNull($user->job_title);
    }

    public function test_a_patronymic_is_optional_and_can_be_cleared(): void
    {
        $user = User::factory()->create([
            'last_name' => 'Лавлейс',
            'first_name' => 'Ада',
            'middle_name' => 'Августовна',
        ]);

        $this->actingAs($user)
            ->putJson(route('profile.update'), [
                'last_name' => 'Лавлейс',
                'first_name' => 'Ада',
                'email' => $user->email,
            ])
            ->assertOk()
            ->assertJsonPath('data.middle_name', null)
            ->assertJsonPath('data.name', 'Лавлейс Ада');

        $this->assertNull($user->refresh()->middle_name);
    }

    public function test_a_surname_and_a_given_name_are_both_required(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.update'), ['email' => $user->email])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['last_name', 'first_name']);
    }

    public function test_saving_without_changing_the_email_is_allowed(): void
    {
        $user = User::factory()->create();

        // The uniqueness rule has to ignore the user's own row, or nobody could
        // ever edit their name without also changing their address.
        $this->actingAs($user)
            ->putJson(route('profile.update'), [
                'last_name' => 'Новая',
                'first_name' => 'Фамилия',
                'email' => $user->email,
            ])
            ->assertOk();
    }

    public function test_an_email_taken_by_someone_else_is_refused(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->putJson(route('profile.update'), [
                'last_name' => $user->last_name,
                'first_name' => $user->first_name,
                'email' => $other->email,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('email');
    }

    public function test_a_guest_cannot_touch_a_profile(): void
    {
        $this->putJson(route('profile.update'), [
            'last_name' => 'Никто',
            'first_name' => 'Никак',
            'email' => 'no@example.com',
        ])->assertUnauthorized();
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
