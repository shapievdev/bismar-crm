<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use Anthropic\Client;
use Anthropic\RequestOptions;
use App\Enums\CourseVisibility;
use App\Enums\Permission;
use App\Models\Regulation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesUsers;
use Tests\Support\FakeAnthropicTransport;
use Tests\TestCase;

/**
 * Документы — тоже база знаний.
 *
 * Консультант читал только учебные материалы, и правила, по которым люди
 * работают, для него не существовали: сотрудник спрашивал «как оформить
 * возврат», ответ лежал в документе, а приходило «в базе знаний об этом ничего
 * нет». Теперь корпус поиска общий — с теми же правилами видимости, потому что
 * пересказ закрытого документа выдаёт его не хуже открытой страницы.
 */
final class DocumentsInTheBaseTest extends TestCase
{
    use ActsAsSpaClient, MakesUsers, RefreshDatabase;

    public function test_an_answer_is_built_from_a_document_and_cites_it(): void
    {
        $document = $this->publishedDocument(
            'Правила возврата товара',
            'Возврат оформляется в течение четырнадцати дней при наличии чека и упаковки.',
        );

        $this->fakeModel(FakeAnthropicTransport::replying('Четырнадцать дней при наличии чека [источник 1].'));

        $response = $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'В какой срок оформляется возврат товара'])
            ->assertOk();

        $source = $response->json('data.sources.0');

        $this->assertSame('document', $source['kind'], 'Источником оказался не документ.');
        $this->assertSame('Правила возврата товара', $source['title']);
        $this->assertSame('/lms/documents/'.$document->slug, $source['url']);
        $this->assertStringContainsString('четырнадцати дней', $source['quote']);
    }

    /** Черновик не пересказывают: неопубликованное выдаёт себя так же верно. */
    public function test_a_draft_document_never_reaches_the_model(): void
    {
        Regulation::factory()->create([
            'title' => 'Правила возврата товара',
            'content_json' => $this->article('Возврат оформляется в течение четырнадцати дней.'),
        ]);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'В какой срок оформляется возврат товара'])
            ->assertOk()
            ->assertJsonPath('data.sources', []);

        $this->assertFalse($transport->wasCalled(), 'Модель спросили черновиком документа.');
    }

    /**
     * Закрытый документ не пересказывают тому, кого в него не пускали, — то же
     * правило, что у закрытого курса.
     */
    public function test_a_private_document_stays_closed_to_outsiders(): void
    {
        $author = $this->author();

        $document = Regulation::factory()->published()->create([
            'author_id' => $author->getKey(),
            'visibility' => CourseVisibility::Private,
            'title' => 'Порядок работы с наличными',
            'content_json' => $this->article('Инкассация проводится ежедневно до восемнадцати часов.'),
        ]);

        $transport = $this->fakeModel(FakeAnthropicTransport::replying('Не должно быть вызвано.'));

        $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'До какого часа проводится инкассация'])
            ->assertOk()
            ->assertJsonPath('data.sources', []);

        $this->assertFalse($transport->wasCalled(), 'Закрытый документ ушёл в модель постороннему.');

        // Автору тот же вопрос отвечается — доступ у него есть.
        $this->fakeModel(FakeAnthropicTransport::replying('До восемнадцати часов [источник 1].'));

        $this->actingAs($author)
            ->postJson(route('lms.ask'), ['question' => 'До какого часа проводится инкассация'])
            ->assertOk()
            ->assertJsonPath('data.sources.0.title', $document->title);
    }

    /** Правку текста поиск подхватывает сам: нарезка идёт при сохранении. */
    public function test_editing_a_document_updates_what_the_consultant_finds(): void
    {
        $document = $this->publishedDocument(
            'Правила возврата товара',
            'Возврат оформляется в течение четырнадцати дней.',
        );

        $document->update(['content_json' => $this->article('Возврат оформляется в течение тридцати дней.')]);

        $this->fakeModel(FakeAnthropicTransport::replying('Тридцать дней [источник 1].'));

        $response = $this->actingAs($this->learner())
            ->postJson(route('lms.ask'), ['question' => 'В какой срок оформляется возврат товара'])
            ->assertOk();

        $this->assertStringContainsString('тридцати дней', $response->json('data.sources.0.quote'));
    }

    /* ---------- helpers ---------- */

    private function publishedDocument(string $title, string $text): Regulation
    {
        return Regulation::factory()->published()->create([
            'title' => $title,
            'content_json' => $this->article($text),
        ]);
    }

    /**
     * Статья документа в том виде, в каком её пишет редактор: абзац со своим
     * именем — по нему ссылка попадает не в документ целиком, а в это место.
     *
     * @return array<string, mixed>
     */
    private function article(string $text): array
    {
        return [
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'attrs' => ['data-block-id' => 'b1', 'blockId' => 'b1'],
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

    private function author(): User
    {
        return $this->userWith(
            Permission::ViewCourses,
            Permission::CreateCourses,
            Permission::UpdateCourses,
        );
    }
}
