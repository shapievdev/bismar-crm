<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Models\Group;
use App\Models\Regulation;
use App\Models\RegulationVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Консультант отвечает по версии спрашивающего (решение пользователя
 * 2026-09-12).
 *
 * У документа бывает несколько текстов: общий и версии для разных групп.
 * Отвечать надо по тому, который этому человеку и предназначен, — иначе
 * рознице пересказали бы расчёт офиса, а закрытая версия ушла бы наружу
 * пересказом, что не лучше открытой страницы.
 */
final class VersionsInTheBaseTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_the_answer_is_built_from_the_readers_own_version(): void
    {
        $document = $this->document('Как считается зарплата', 'Зарплата считается по окладу.');

        [$retail, $salesman] = $this->groupWithPerson();
        $this->version($document, 'Для розницы', $retail, 'Зарплата считается по выручке смены.');

        $this->fakeModel(FakeAnthropicTransport::replying('По выручке смены [источник 1].'));

        $quote = $this->actingAs($salesman)
            ->postJson(route('lms.ask'), ['question' => 'Как считается зарплата'])
            ->assertOk()
            ->json('data.sources.0.quote');

        $this->assertStringContainsString('выручке смены', (string) $quote);
        $this->assertStringNotContainsString('по окладу', (string) $quote);
    }

    /** Чья группа ни с чем не совпала — тому отвечают по общему тексту. */
    public function test_a_reader_without_a_version_gets_the_common_text(): void
    {
        $document = $this->document('Как считается зарплата', 'Зарплата считается по окладу.');

        [$retail] = $this->groupWithPerson();
        $this->version($document, 'Для розницы', $retail, 'Зарплата считается по выручке смены.');

        $this->fakeModel(FakeAnthropicTransport::replying('По окладу [источник 1].'));

        $quote = $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Как считается зарплата'])
            ->assertOk()
            ->json('data.sources.0.quote');

        $this->assertStringContainsString('по окладу', (string) $quote);
        $this->assertStringNotContainsString('выручке смены', (string) $quote);
    }

    /**
     * Закрытая версия не уходит наружу и пересказом.
     *
     * Отдельного условия для неё нет и не нужно: в корпус попадает либо своя
     * версия, либо общий текст, а чужая — никогда.
     */
    public function test_a_private_version_never_reaches_the_model(): void
    {
        $document = $this->document('Как считается зарплата', 'Зарплата считается по окладу.');

        [$chiefs] = $this->groupWithPerson();
        $this->version($document, 'Для руководителей', $chiefs, 'Директору положен процент от прибыли.', private: true);

        $this->fakeModel(FakeAnthropicTransport::replying('По окладу [источник 1].'));

        $quote = (string) $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'Как считается зарплата'])
            ->assertOk()
            ->json('data.sources.0.quote');

        $this->assertStringNotContainsString('процент от прибыли', $quote);
    }

    /** Текст версии попадает в корпус сам: наблюдатель нарезает его при сохранении. */
    public function test_the_text_of_a_version_is_indexed_on_save(): void
    {
        $document = $this->document('Как считается зарплата', 'Зарплата считается по окладу.');
        [$retail] = $this->groupWithPerson();

        $version = $this->version($document, 'Для розницы', $retail, 'Зарплата считается по выручке смены.');

        // Кусок помнит и документ — им идёт отбор в поиске, — и версию, которая
        // сужает выбранное.
        $segment = $version->segments()->firstOrFail();

        $this->assertSame($document->id, $segment->regulation_id);
        $this->assertStringContainsString('выручке смены', (string) $segment->content);

        // Заголовок куска — название документа, а не версии: спрашивают «что
        // там в правилах отпуска», а не «что там в версии для розницы».
        $this->assertSame('Как считается зарплата', $segment->heading);
    }

    /* ---------- Заготовки ---------- */

    private function document(string $title, string $text): Regulation
    {
        return Regulation::factory()->published()->create([
            'title' => $title,
            'content_json' => $this->article($text),
        ]);
    }

    /**
     * @return array{0: Group, 1: User}
     */
    private function groupWithPerson(): array
    {
        $group = Group::factory()->create();
        $person = $this->learner();

        $group->members()->attach($person);

        return [$group, $person];
    }

    private function version(
        Regulation $regulation,
        string $name,
        Group $group,
        string $text,
        bool $private = false,
    ): RegulationVersion {
        /** @var RegulationVersion $version */
        $version = $regulation->versions()->create([
            'name' => $name,
            'is_private' => $private,
            'position' => 1,
            'content_json' => $this->article($text, 'v1'),
        ]);

        $version->groups()->sync([$group->id]);

        return $version;
    }

    /**
     * @return array<string, mixed>
     */
    private function article(string $text, string $blockId = 'b1'): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['data-block-id' => $blockId, 'blockId' => $blockId],
                'content' => [['type' => 'text', 'text' => $text]],
            ]],
        ];
    }

    private function fakeModel(FakeAnthropicTransport $transport): FakeAnthropicTransport
    {
        $this->app->instance(Client::class, new Client(
            apiKey: 'test-key',
            requestOptions: RequestOptions::with(transporter: $transport, maxRetries: 0),
        ));

        return $transport;
    }
}
