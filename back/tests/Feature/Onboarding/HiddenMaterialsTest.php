<?php

declare(strict_types=1);

namespace Tests\Feature\Onboarding;

use App\Enums\CourseVisibility;
use App\Enums\Permission;
use App\Models\Course;
use App\Models\OnboardingTemplate;
use App\Models\Regulation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesJourneys;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Предупреждение до наложения шаблона: его материалы, закрытые сотруднику.
 */
final class HiddenMaterialsTest extends TestCase
{
    use ActsAsSpaClient, MakesJourneys, MakesUsers, RefreshDatabase;

    public function test_a_private_course_and_a_sealed_section_are_named(): void
    {
        $template = OnboardingTemplate::factory()->create();
        $stage = $template->stages()->create([
            'position' => 1, 'day_offset' => 1, 'title' => 'Компания',
        ]);

        // Приватный курс: стажёра в него не пускали.
        $private = Course::factory()->published()->create([
            'title' => 'Закрытый курс',
            'visibility' => CourseVisibility::Private,
        ]);

        // Документ: у стажёра нет права на раздел документов.
        $document = Regulation::factory()->published()->create(['title' => 'Регламент склада']);

        foreach ([['course', $private], ['regulation', $document]] as $position => [$type, $material]) {
            $stage->links()->create([
                'linkable_type' => $type,
                'linkable_id' => $material->getKey(),
                'position' => $position + 1,
                'is_required' => true,
            ]);
        }

        // Стажёр с правом курсов, но без права документов и без допуска в
        // приватный курс: закрыты оба материала.
        $trainee = $this->userWith(Permission::ViewCourses);

        $response = $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.employees.templates.visibility', [$trainee, $template]))
            ->assertOk();

        $titles = collect($response->json('data.hidden'))->pluck('title')->all();

        $this->assertEqualsCanonicalizing(['Закрытый курс', 'Регламент склада'], $titles);
        $this->assertTrue($response->json('data.hidden.0.is_required'));
    }

    public function test_an_open_route_warns_about_nothing(): void
    {
        $template = OnboardingTemplate::factory()->create();
        $stage = $template->stages()->create([
            'position' => 1, 'day_offset' => 1, 'title' => 'Компания',
        ]);

        $open = Course::factory()->published()->create();

        $stage->links()->create([
            'linkable_type' => 'course',
            'linkable_id' => $open->getKey(),
            'position' => 1,
            'is_required' => true,
        ]);

        $trainee = $this->userWith(Permission::ViewCourses);

        $this->actingAs($this->hr())
            ->getJson(route('onboarding.manage.employees.templates.visibility', [$trainee, $template]))
            ->assertOk()
            ->assertJsonCount(0, 'data.hidden');
    }

    public function test_the_check_is_closed_without_the_permission(): void
    {
        $template = OnboardingTemplate::factory()->create();

        $this->actingAs($this->userWith())
            ->getJson(route('onboarding.manage.employees.templates.visibility', [$this->userWith(), $template]))
            ->assertForbidden();
    }
}
