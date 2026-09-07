<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Models\Course;
use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Что бывает, когда поисковый сервер молчит, а материал сохраняют.
 *
 * Scout пишет в индекс прямо в обработчике `saved`, и без оговорок молчащий
 * Meilisearch означал бы, что курс нельзя сохранить, пока сервер не поднимут.
 * См. App\Models\Concerns\LenientlySearchable.
 */
final class SearchIndexFailureTest extends TestCase
{
    use RefreshDatabase;

    private function pretendTheEngineIsDown(): void
    {
        $engine = Mockery::mock(Engine::class);
        $engine->shouldReceive('update')->andThrow(new RuntimeException('Meilisearch недоступен'));
        $engine->shouldReceive('delete')->andThrow(new RuntimeException('Meilisearch недоступен'));

        resolve(EngineManager::class)->extend('fake', fn (): Engine => $engine);
        config(['scout.driver' => 'fake']);
    }

    /**
     * Тесты идут из консоли, а проверяется поведение в запросе. Флаг у
     * приложения читается один раз и запоминается, поэтому его и подменяем —
     * иначе эту ветку не достать.
     */
    private function pretendWeAreHandlingARequest(): void
    {
        Closure::bind(function (): void {
            $this->isRunningInConsole = false;
        }, $this->app, Application::class)();
    }

    public function test_a_silent_engine_does_not_stop_the_author_from_saving(): void
    {
        $this->pretendTheEngineIsDown();
        $this->pretendWeAreHandlingARequest();

        Log::shouldReceive('warning')->once();

        $course = Course::factory()->create(['title' => 'Работа с кассой']);

        $this->assertDatabaseHas('courses', ['id' => $course->getKey(), 'title' => 'Работа с кассой']);
    }

    /**
     * А вот руками запущенная переиндексация обязана падать: её и запускают
     * затем, чтобы узнать, что индекс собран.
     */
    public function test_the_same_failure_is_loud_on_the_command_line(): void
    {
        $this->pretendTheEngineIsDown();

        $this->expectException(RuntimeException::class);

        Course::factory()->create();
    }
}
