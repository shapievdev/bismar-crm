<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Support\Ai\Embedder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Смысл ищет сам, а не пересортировывает найденное словами.
 *
 * Прежде смысловой поиск был лишь пересортировкой: слова отбирали сто двадцать
 * кусков, вектор менял их порядок. Материал, названный не теми словами, что
 * вопрос, в эти сто двадцать не попадал — и, значит, не существовал. Разница не
 * видна на базе из полусотни уроков и решает всё на базе из пяти тысяч: отбор
 * словами забирает долю корпуса, а окно остаётся прежним.
 *
 * Здесь это проверяется единственным способом, который ничего не берёт на веру:
 * один и тот же материал, один и тот же вопрос, и ни одного общего слова между
 * ними. Словами он не находится. Смыслом — находится.
 */
final class SemanticSearchTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    /** Слова вопроса, ни одно из которых не встречается в материале. */
    private const QUESTION = 'Сколько сохнет покрытие?';

    /** Слова материала, ни одно из которых не встречается в вопросе. */
    private const MATERIAL = 'Эмаль на водной основе застывает не менее четырёх часов при комнатной температуре.';

    /**
     * Ради чего заводился pgvector: материал найден по смыслу, хотя общих слов
     * с вопросом у него нет.
     */
    public function test_material_named_in_other_words_is_found_by_meaning(): void
    {
        $this->withEmbeddings();
        $this->publishedLesson('Отделочные работы', self::MATERIAL);

        $this->fakeModel(FakeAnthropicTransport::replying('Четыре часа [источник 1].'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => self::QUESTION])
            ->assertOk()
            ->assertJsonPath('data.sources.0.lesson_title', 'Отделочные работы');
    }

    /**
     * Тот же материал и тот же вопрос без смыслового поиска — не находятся.
     *
     * Ровно эта пара и показывает, что нашла его именно вторая ветка поиска, а
     * не удачное совпадение стеммера.
     */
    public function test_the_same_material_stays_invisible_to_words_alone(): void
    {
        $this->publishedLesson('Отделочные работы', self::MATERIAL);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => self::QUESTION])
            ->assertOk()
            ->assertJsonPath('data.answer', 'В базе знаний об этом ничего нет.');

        $this->assertFalse($transport->wasCalled());
    }

    /**
     * У вектора нет исхода «не совпало»: ближайшие куски находятся всегда, в
     * том числе к вопросу, к которому в базе нет ничего. Держит их порог
     * ai.passages_floor — без него честное «ничего нет» стало бы недостижимо.
     */
    public function test_a_question_the_base_knows_nothing_about_is_answered_honestly(): void
    {
        $this->withEmbeddings();
        $this->publishedLesson('Отделочные работы', self::MATERIAL);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Как поменять картридж в принтере?'])
            ->assertOk()
            ->assertJsonPath('data.answer', 'В базе знаний об этом ничего нет.');

        $this->assertFalse($transport->wasCalled(), 'Модель позвали, хотя показывать ей было нечего.');
    }

    /* ---------- helpers ---------- */

    /**
     * Смысловой поиск на управляемых векторах.
     *
     * Тема задаётся списком слов, и списки нарочно не пересекаются: вопрос и
     * материал об одном и том же не имеют ни одной общей леммы, а вектор у них
     * один. Так близость выходит ровно 1 или 0, и порог проверяется без
     * подгонки чисел.
     */
    private function withEmbeddings(): void
    {
        config(['ai.api_key' => 'test-key', 'ai.embedding_model' => 'test-embeddings']);

        Http::fake(['*/v1/embeddings' => function (Request $request): array {
            /** @var list<string> $inputs */
            $inputs = $request->data()['input'];

            return ['data' => array_map(static function (string $text): array {
                $aboutPaint = self::mentions($text, ['сохнет', 'покрытие', 'эмаль', 'застывает', 'отделочные']);

                return ['embedding' => array_pad(
                    [$aboutPaint ? 1.0 : 0.0, $aboutPaint ? 0.0 : 1.0],
                    Embedder::DIMENSIONS,
                    0.0,
                )];
            }, $inputs)];
        }]);
    }

    /**
     * @param  list<string>  $words
     */
    private static function mentions(string $text, array $words): bool
    {
        foreach ($words as $word) {
            if (mb_stripos($text, $word) !== false) {
                return true;
            }
        }

        return false;
    }

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
    }

    private function publishedLesson(string $title, string $content): Lesson
    {
        $module = CourseModule::factory()->create([
            'course_id' => Course::factory()->published()->create(['title' => 'Ремонт'])->id,
        ]);

        return Lesson::factory()->create([
            'module_id' => $module->id,
            'title' => $title,
            'content' => $content,
        ]);
    }
}
