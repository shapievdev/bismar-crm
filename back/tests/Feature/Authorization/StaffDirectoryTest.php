<?php

declare(strict_types=1);
namespace Tests\Feature\Authorization;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

final class StaffDirectoryTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_active_and_dismissed_lists_are_separate_and_search_stays_in_selected_list(): void
    {
        $active = User::factory()->create(['last_name' => 'Петров', 'first_name' => 'Иван']);
        $gone = User::factory()->dismissed()->create(['last_name' => 'Петров', 'first_name' => 'Олег']);
        $this->actingAs($this->administrator());
        $this->getJson(route('users.index', ['status' => 'working', 'search' => 'Петров']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $active->id);
        $this->getJson(route('users.index', ['status' => 'dismissed', 'search' => 'Петров']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $gone->id);
        $this->getJson(route('users.index', ['status' => 'all', 'search' => 'Петров']))->assertJsonCount(2, 'data');
    }
    public function test_full_name_matches_across_fields_in_any_order_and_case(): void
    {
        $person = User::factory()->create(['last_name' => 'Петров', 'first_name' => 'Иван', 'middle_name' => 'Сергеевич']);
        User::factory()->create(['last_name' => 'Петров', 'first_name' => 'Олег', 'middle_name' => 'Сергеевич']);
        $this->actingAs($this->administrator());
        foreach (['петров иван сергеевич', '  Иван   Петров ', 'СЕРГЕЕВИЧ Иван'] as $search) {
            $this->getJson(route('users.index', ['search' => $search]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $person->id);
        }
    }
    public function test_phone_search_supports_common_formats_and_partial_numbers(): void
    {
        $person = User::factory()->create(['phone' => '+79991234567']);
        $this->actingAs($this->administrator());
        foreach (['+7 (999) 123-45-67', '8 999 123 45 67', '9991234567', '123-45-67'] as $search) {
            $this->getJson(route('users.index', ['search' => $search]))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $person->id);
        }
        $this->getJson(route('users.index', ['search' => ['bad']]))->assertUnprocessable();
        $this->getJson(route('users.index', ['status' => 'invalid']))->assertUnprocessable();
    }
}
