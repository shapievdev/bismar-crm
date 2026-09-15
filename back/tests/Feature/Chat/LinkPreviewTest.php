<?php

declare(strict_types=1);

namespace Tests\Feature\Chat;

use App\Support\Chat\HostGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\ActsAsSpaClient;
use Tests\Concerns\MakesConversations;
use Tests\TestCase;

/**
 * Карточка ссылки, сказанной в переписке.
 *
 * Главное здесь не «показывает заголовок», а «не ходит куда не следует»: точка
 * принимает адрес от человека и идёт по нему сама, изнутри сети. Без проверки
 * адреса это прямая дорога во внутренние службы.
 */
final class LinkPreviewTest extends TestCase
{
    use ActsAsSpaClient, MakesConversations, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Разрешение имён — обращение к сети, и проверкам оно ни к чему: здесь
        // важно, что запрещённое остаётся запрещённым, а не то, куда сегодня
        // указывает example.com.
        $this->app->bind(HostGuard::class, fn (): HostGuard => new class extends HostGuard
        {
            /**
             * @return list<string>
             */
            protected function resolve(string $host): array
            {
                return $host === 'internal.example' ? ['10.0.0.5'] : ['93.184.216.34'];
            }
        });
    }

    public function test_a_page_becomes_a_card(): void
    {
        Http::fake([
            '*' => Http::response(<<<'HTML'
                <html><head>
                    <meta property="og:title" content="Угловая шлифовальная машина Makita">
                    <meta property="og:description" content="Артикул GA5030R, 5 241 ₽">
                    <meta property="og:image" content="/media/makita.jpg">
                    <meta property="og:site_name" content="Бисмар">
                </head><body>…</body></html>
                HTML, headers: ['Content-Type' => 'text/html; charset=utf-8']),
        ]);

        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('https://shop.example/p/123'))
            ->assertOk()
            ->assertJsonPath('data.title', 'Угловая шлифовальная машина Makita')
            ->assertJsonPath('data.description', 'Артикул GA5030R, 5 241 ₽')
            ->assertJsonPath('data.site_name', 'Бисмар')
            ->assertJsonPath('data.host', 'shop.example')
            // Относительный адрес картинки достроен: по нему пойдёт браузер.
            ->assertJsonPath('data.image', 'https://shop.example/media/makita.jpg');
    }

    /**
     * Мета-теги, лежащие далеко от начала страницы, всё равно находятся.
     *
     * Расчёт «всё нужное в первых строках» не пережил первой же настоящей
     * ссылки: Figma держит свои теги за семьюстами килобайтами встроенных
     * скриптов, и карточка не собиралась вовсе.
     */
    public function test_metadata_far_into_the_page_is_still_found(): void
    {
        $padding = str_repeat('<script>/* '.str_repeat('x', 900).' */</script>', 700);

        Http::fake([
            '*' => Http::response(
                '<html><head>'.$padding.'<meta property="og:title" content="Далёкий заголовок"></head></html>',
                headers: ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('https://shop.example/heavy'))
            ->assertOk()
            ->assertJsonPath('data.title', 'Далёкий заголовок');
    }

    /** Нет ни Open Graph, ни заголовка — нет и карточки: показывать нечего. */
    public function test_a_page_without_a_title_gives_nothing(): void
    {
        Http::fake([
            '*' => Http::response('<html><body>просто текст</body></html>', headers: [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('https://shop.example/p/123'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /** Обычный `<title>` годится, когда Open Graph не проставлен. */
    public function test_the_plain_title_is_enough(): void
    {
        Http::fake([
            '*' => Http::response(
                '<html><head><title>  Прайс   на   сентябрь </title></head></html>',
                headers: ['Content-Type' => 'text/html'],
            ),
        ]);

        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('https://shop.example/price'))
            ->assertOk()
            // Пробелы схлопнуты: в разметке их ставят как придётся.
            ->assertJsonPath('data.title', 'Прайс на сентябрь');
    }

    /**
     * Адрес, ведущий внутрь сети, не открывается.
     *
     * Это и есть главное здесь: без проверки посторонний заставил бы сервер
     * сходить по внутреннему адресу за себя и вернуть, что там лежит.
     */
    public function test_an_address_inside_the_network_is_refused(): void
    {
        Http::fake();

        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('http://internal.example/secrets'))
            ->assertOk()
            ->assertJsonPath('data', null);

        Http::assertNothingSent();
    }

    /** Числовой адрес внутренней сети — тем более. */
    public function test_a_private_address_is_refused(): void
    {
        Http::fake();

        foreach (['http://127.0.0.1:8100/api', 'http://169.254.169.254/latest/meta-data'] as $url) {
            $this->actingAs($this->employee())
                ->getJson(route('chat.link-preview').'?url='.urlencode($url))
                ->assertOk()
                ->assertJsonPath('data', null);
        }

        Http::assertNothingSent();
    }

    /** Не веб-адрес не принимается вовсе. */
    public function test_a_non_web_address_is_refused(): void
    {
        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('file:///etc/passwd'))
            ->assertUnprocessable()
            ->assertJsonValidationErrorFor('url');
    }

    /** Переадресация внутрь сети обрывается — проверяется каждое звено. */
    public function test_a_redirect_into_the_network_is_refused(): void
    {
        Http::fake([
            'shop.example/*' => Http::response('', 302, ['Location' => 'http://internal.example/secrets']),
            '*' => Http::response('<html><head><title>Внутреннее</title></head></html>', headers: [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('https://shop.example/go'))
            ->assertOk()
            ->assertJsonPath('data', null);

        Http::assertSentCount(1);
    }

    /** Не страница — не карточка: за картинкой ничего не стоит. */
    public function test_a_non_html_answer_gives_nothing(): void
    {
        Http::fake([
            '*' => Http::response('двоичное', headers: ['Content-Type' => 'image/jpeg']),
        ]);

        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('https://shop.example/photo.jpg'))
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    /**
     * За одной и той же ссылкой ходят один раз.
     *
     * Ссылку на прайс скидывают в пять переписок, и каждая её реплика спросила
     * бы карточку заново.
     */
    public function test_the_same_link_is_fetched_once(): void
    {
        Cache::clear();

        Http::fake([
            '*' => Http::response('<html><head><title>Прайс</title></head></html>', headers: [
                'Content-Type' => 'text/html',
            ]),
        ]);

        $me = $this->employee();

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($me)
                ->getJson(route('chat.link-preview').'?url='.urlencode('https://shop.example/price'))
                ->assertOk()
                ->assertJsonPath('data.title', 'Прайс');
        }

        Http::assertSentCount(1);
    }

    /** Свои страницы карточкой не разворачиваются: они и так за входом. */
    public function test_our_own_pages_are_skipped(): void
    {
        Http::fake();

        config(['app.url' => 'https://crm.bismar.pro']);

        $this->actingAs($this->employee())
            ->getJson(route('chat.link-preview').'?url='.urlencode('https://crm.bismar.pro/lms'))
            ->assertOk()
            ->assertJsonPath('data', null);

        Http::assertNothingSent();
    }

    /** Точка закрыта от посторонних, как и вся переписка. */
    public function test_a_guest_is_refused(): void
    {
        $this->getJson(route('chat.link-preview').'?url='.urlencode('https://shop.example/p'))
            ->assertUnauthorized();
    }
}
