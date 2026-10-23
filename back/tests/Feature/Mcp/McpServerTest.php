<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Servers\Bismar;
use App\Mcp\Tools\Whoami;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Сервер MCP как целое: от чьего имени он работает и что показывает.
 *
 * Права по инструментам проверяются там, где и сами инструменты; здесь — общее:
 * что сервер вообще поднимается, что действует от имени подключившегося и что
 * «кто я» рассказывает о нём правду.
 */
final class McpServerTest extends TestCase
{
    use MakesUsers, RefreshDatabase;

    public function test_the_server_answers_as_the_person_who_connected(): void
    {
        $user = User::factory()->create([
            'last_name' => 'Курабанов',
            'first_name' => 'Давлет',
            'job_title' => 'Продавец',
        ]);

        Bismar::actingAs($user)
            ->tool(Whoami::class)
            ->assertOk()
            ->assertHasNoErrors()
            ->assertStructuredContent([
                'id' => $user->getKey(),
                'name' => 'Курабанов Давлет',
                'job_title' => 'Продавец',
                'phone' => $user->phone,
                'email' => $user->email,
                'access_level' => 'user',
                'access_level_label' => 'Пользователь',
                'grants_everything' => false,
                'permissions' => [],
                'departments' => [],
            ]);
    }

    /**
     * У администратора права не отмечены по одному, но действуют все: он
     * проходит `Gate::before`. Инструмент должен говорить об этом прямо, иначе
     * модель решит, что ей ничего нельзя.
     */
    public function test_an_administrator_is_told_that_everything_is_granted(): void
    {
        Bismar::actingAs($this->administrator())
            ->tool(Whoami::class)
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $content) => $content
                ->where('access_level', 'admin')
                ->where('grants_everything', true)
                ->where('permissions', fn (Collection $permissions): bool => $permissions
                    ->contains('users.manage') && $permissions->contains('analytics.view'))
                ->etc());
    }

    /** «Кто я» открыт всякому, кто вошёл: это не полномочие. */
    public function test_knowing_yourself_needs_no_permission(): void
    {
        Bismar::actingAs(User::factory()->create())
            ->tools()
            ->assertRegistered(Whoami::class);
    }
}
