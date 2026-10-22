<?php

declare(strict_types=1);

namespace Tests\Feature\Authorization;

use App\Enums\Permission;
use App\Models\Enrollment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Удаление учётной записи — крайняя мера, и она мягкая.
 *
 * Проверяется главным образом то, ради чего мягкость и заводили: запись
 * остаётся в базе со всем, что за ней числилось, и удаление можно отменить.
 * Приложение при этом удалённого не показывает и внутрь не пускает, а номер, с
 * которого входят, освобождает — иначе заведённая по ошибке запись держала бы
 * его за собой навсегда.
 *
 * Чего удалённый лишается в отчёте о штате — проверяется там же, где этот отчёт
 * и считают: StaffAnalyticsTest.
 *
 * Сами увольнение и возвращение в строй — рядом, в UserDismissalTest: это
 * другое решение, и правила у него свои.
 */
final class UserDeletionTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_a_superadmin_deletes_a_dismissed_record(): void
    {
        $user = User::factory()->dismissed()->create();
        $superAdministrator = $this->superAdministrator();

        $this->actingAs($superAdministrator)
            ->deleteJson(route('users.destroy', $user))
            ->assertNoContent();

        // Запись на месте, но убрана: стёртая переписала бы отчёт о штате
        // задним числом.
        $this->assertSoftDeleted('users', ['id' => $user->getKey()]);

        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'deleted_by_id' => $superAdministrator->getKey(),
        ]);
    }

    /**
     * Удаление не уносит за собой того, что за человеком числилось: прогресс
     * обучения, место в структуре, написанное им. Прежде всё это уходило
     * каскадом, и вместе с ним — половина отчётов о прошлом.
     */
    public function test_what_was_counted_against_the_person_survives(): void
    {
        $user = User::factory()->dismissed()->create();
        $enrollment = Enrollment::factory()->create(['user_id' => $user->getKey()]);

        $this->actingAs($this->superAdministrator())
            ->deleteJson(route('users.destroy', $user))
            ->assertNoContent();

        $this->assertDatabaseHas('enrollments', ['id' => $enrollment->getKey()]);
    }

    public function test_only_a_dismissed_record_is_deleted(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->superAdministrator())
            ->deleteJson(route('users.destroy', $user))
            ->assertStatus(409);

        $this->assertNotSoftDeleted('users', ['id' => $user->getKey()]);
    }

    public function test_an_administrator_cannot_delete_a_record(): void
    {
        $user = User::factory()->dismissed()->create();

        $this->actingAs($this->administrator())
            ->deleteJson(route('users.destroy', $user))
            ->assertStatus(409);

        $this->assertNotSoftDeleted('users', ['id' => $user->getKey()]);
    }

    public function test_nobody_deletes_themselves(): void
    {
        $superAdministrator = $this->superAdministrator();

        $this->actingAs($superAdministrator)
            ->deleteJson(route('users.destroy', $superAdministrator))
            ->assertStatus(409);

        $this->assertNotSoftDeleted('users', ['id' => $superAdministrator->getKey()]);
    }

    /* ---------- Удалённого не видно и не пускают ---------- */

    public function test_a_deleted_person_cannot_sign_in(): void
    {
        $user = User::factory()->deleted()->create();

        $this->postJson(route('auth.login'), [
            'phone' => $user->phone,
            'password' => 'password',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertGuest();
    }

    /**
     * В списке сотрудников удалённого нет — в отличие от уволенного, который
     * там остаётся ради возвращения в строй.
     */
    public function test_a_deleted_record_leaves_the_staff_list_and_the_card(): void
    {
        $deleted = User::factory()->deleted()->create();

        $this->actingAs($this->administrator())
            ->getJson(route('users.index'))
            ->assertOk()
            ->assertJsonMissing(['id' => $deleted->getKey()]);

        $this->actingAs($this->administrator())
            ->getJson(route('users.show', $deleted))
            ->assertNotFound();
    }

    /**
     * Удалённые — своим списком и одному суперадминистратору: возвращает их он.
     */
    public function test_the_superadmin_sees_the_deleted_apart(): void
    {
        $superAdministrator = $this->superAdministrator();
        $user = User::factory()->dismissed()->create();

        $this->actingAs($superAdministrator)
            ->deleteJson(route('users.destroy', $user))
            ->assertNoContent();

        $this->actingAs($superAdministrator)
            ->getJson(route('users.index', ['deleted' => 1]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $user->getKey())
            ->assertJsonPath('data.0.deleted_by', $superAdministrator->name);
    }

    public function test_an_administrator_may_not_look_at_the_deleted(): void
    {
        User::factory()->deleted()->create();

        $this->actingAs($this->administrator())
            ->getJson(route('users.index', ['deleted' => 1]))
            ->assertForbidden();
    }

    /**
     * Номер освобождается: он логин, и держать его за записью, которой в
     * приложении нет, значило бы не дать завести человека с этим номером — ни
     * нового, ни того же самого.
     */
    public function test_deletion_frees_the_phone_and_the_email(): void
    {
        $user = User::factory()->deleted()->create();

        $this->actingAs($this->administrator())
            ->postJson(route('users.store'), [
                'last_name' => 'Новиков',
                'first_name' => 'Пётр',
                'phone' => $user->phone,
                'email' => $user->email,
                'password' => 'Str0ng-passw0rd!',
            ])
            ->assertCreated();
    }

    /* ---------- Возвращение ---------- */

    public function test_a_superadmin_brings_a_deleted_record_back(): void
    {
        $user = $this->userWith(Permission::ViewCourses);
        $superAdministrator = $this->superAdministrator();

        $this->actingAs($superAdministrator)->postJson(route('users.dismiss', $user))->assertOk();
        $this->actingAs($superAdministrator)->deleteJson(route('users.destroy', $user))->assertNoContent();

        $this->actingAs($superAdministrator)
            ->postJson(route('users.restore', $user))
            ->assertOk()
            ->assertJsonPath('data.id', $user->getKey())
            ->assertJsonPath('data.deleted_at', null)
            // Возвращается уволенным — таким, каким удаляли: вернуть в строй
            // решают отдельно.
            ->assertJsonPath('data.own_permissions', [Permission::ViewCourses->value]);

        $this->assertNotSoftDeleted('users', ['id' => $user->getKey()]);
        $this->assertNotNull($user->fresh()?->dismissed_at);

        $this->actingAs($this->administrator())
            ->getJson(route('users.index'))
            ->assertOk()
            ->assertJsonFragment(['id' => $user->getKey()]);
    }

    public function test_an_administrator_cannot_bring_a_record_back(): void
    {
        $user = User::factory()->deleted()->create();

        $this->actingAs($this->administrator())
            ->postJson(route('users.restore', $user))
            ->assertStatus(409);

        $this->assertSoftDeleted('users', ['id' => $user->getKey()]);
    }

    /** Живую запись возвращать нечего — её и не удаляли. */
    public function test_a_record_that_is_not_deleted_is_not_found(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->superAdministrator())
            ->postJson(route('users.restore', $user))
            ->assertNotFound();
    }

    /**
     * Номер, выданный за это время другому, остаётся у него: по номеру входят,
     * и указывать он обязан на одного. Возвращаемая запись остаётся без него —
     * ей он нужен был ради входа, а теперь она нужна ради истории.
     */
    public function test_restoring_does_not_take_a_phone_back_from_a_colleague(): void
    {
        $deleted = User::factory()->deleted()->create();
        $newcomer = User::factory()->create(['phone' => $deleted->phone, 'email' => $deleted->email]);

        $this->actingAs($this->superAdministrator())
            ->postJson(route('users.restore', $deleted))
            ->assertOk()
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.email', null);

        $this->assertDatabaseHas('users', [
            'id' => $newcomer->getKey(),
            'phone' => $newcomer->phone,
        ]);
    }
}
