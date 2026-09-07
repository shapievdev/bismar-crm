<?php

declare(strict_types=1);

namespace Tests\Feature\Lms;

use App\Enums\CourseVisibility;
use App\Models\Course;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Laravel\Scout\EngineManager;
use Laravel\Scout\Engines\Engine;
use Mockery;
use RuntimeException;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\TestCase;

/**
 * Каталог и поисковик.
 *
 * Живого Meilisearch в тестах нет и быть не должно — он один на несколько
 * приложений. Вместо него подставной движок: он отвечает тем, чем ответил бы
 * настоящий, и этого довольно, чтобы проверить главное — что доступ решает
 * по-прежнему база, что порядок выдачи сохраняется и что молчание поисковика
 * не оставляет человека с пустым экраном.
 */
final class CatalogSearchTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /**
     * @param  list<int>|RuntimeException  $answer
     */
    private function pretendTheEngineAnswers(array|RuntimeException $answer): void
    {
        $engine = Mockery::mock(Engine::class);

        if ($answer instanceof RuntimeException) {
            $engine->shouldReceive('keys')->andThrow($answer);
        } else {
            $engine->shouldReceive('keys')->andReturn(new Collection($answer));
        }

        resolve(EngineManager::class)->extend('fake', fn (): Engine => $engine);
        config(['scout.driver' => 'fake']);
    }

    public function test_the_catalogue_keeps_the_order_the_engine_returned(): void
    {
        $first = Course::factory()->published()->create(['title' => 'Первый']);
        $second = Course::factory()->published()->create(['title' => 'Второй']);

        // Поисковик считает вторым то, что в каталоге лежит первым: порядок
        // выдачи — его, а не «что свежее».
        $this->pretendTheEngineAnswers([$second->getKey(), $first->getKey()]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'что угодно']))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Второй')
            ->assertJsonPath('data.1.title', 'Первый');
    }

    /**
     * В индексе лежит и закрытое: его ищет тот, кому оно открыто. Решает, кому
     * показать, по-прежнему база — иначе правила о доступе разошлись бы по двум
     * местам.
     */
    public function test_the_engine_cannot_show_what_the_reader_may_not_see(): void
    {
        $open = Course::factory()->published()->create(['title' => 'Открытый']);
        $closed = Course::factory()->published()->create([
            'title' => 'Закрытый',
            'visibility' => CourseVisibility::Private,
        ]);
        $draft = Course::factory()->create(['title' => 'Черновик']);

        $this->pretendTheEngineAnswers([$closed->getKey(), $draft->getKey(), $open->getKey()]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'что угодно']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Открытый');
    }

    public function test_nothing_found_means_nothing_shown(): void
    {
        Course::factory()->published()->create(['title' => 'Работа с кассой']);

        $this->pretendTheEngineAnswers([]);

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'касса']))
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    /**
     * Поисковик — служба необязательная: упал он или не поднят вовсе, каталог
     * ищет по-старому. Пустой экран здесь был бы хуже неточного ответа.
     */
    public function test_a_silent_engine_falls_back_to_the_plain_search(): void
    {
        Course::factory()->published()->create([
            'title' => 'Работа с кассой',
            'keywords' => ['ККМ'],
        ]);
        Course::factory()->published()->create(['title' => 'Онбординг']);

        $this->pretendTheEngineAnswers(new RuntimeException('Meilisearch недоступен'));

        $this->actingAs($this->learner())
            ->getJson(route('lms.courses.index', ['search' => 'ккм']))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.title', 'Работа с кассой');
    }
}
