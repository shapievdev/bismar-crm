<?php

declare(strict_types=1);

namespace App\Support\Chat;

use DOMDocument;
use DOMXPath;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Карточка ссылки: что это за страница, куда она ведёт и как выглядит.
 *
 * Читается по требованию, а не при отправке, и не хранится в сообщении. Причин
 * две. Ждать чужой сайт, пока человек отправляет реплику, нельзя — сервер на
 * том конце отвечает и полминуты. А складывать в свою базу чужие заголовки и
 * картинки значит хранить то, что завтра изменится или окажется не тем, чем
 * было: ссылка — единственное, что сказал человек, остальное принадлежит сайту.
 *
 * Вместо этого — общий кэш на сутки. Ссылку на прайс скидывают в пять разных
 * переписок, и ходить за ней пять раз незачем; а отвечающая ошибкой страница не
 * должна опрашиваться на каждое открытие ленты, поэтому неудача тоже
 * запоминается — ненадолго.
 */
final readonly class LinkPreview
{
    /** Сколько помнить удачную карточку. */
    private const KEEP_MINUTES = 1440;

    /** И сколько — неудачу: страница могла просто лежать. */
    private const KEEP_FAILURE_MINUTES = 60;

    /** Дольше ждать чужой сайт незачем: карточка — украшение, а не содержание. */
    private const TIMEOUT_SECONDS = 5;

    /**
     * Сколько байт страницы разбирать.
     *
     * Мегабайт, и это не с потолка. Расчёт «всё нужное лежит в начале» не
     * оправдался на первой же настоящей ссылке: Figma держит свои мета-теги на
     * 738-й тысяче байт — перед ними идут семьсот килобайт встроенных скриптов,
     * и на четверти мегабайта карточка не собиралась вовсе.
     *
     * Совсем без предела нельзя: на том конце может оказаться поток без конца.
     * Читаем ограниченно и из потока, а не строкой целиком, — так недочитанное
     * не оседает в памяти.
     */
    private const MAX_BYTES = 1_048_576;

    /** Сколько переадресаций проходим, проверяя каждую. */
    private const MAX_HOPS = 3;

    public function __construct(private HostGuard $guard) {}

    /**
     * Карточка для ссылки — или null, если показывать нечего.
     *
     * @return array<string, string|null>|null
     */
    public function for(string $url): ?array
    {
        $normalised = $this->normalise($url);

        if ($normalised === null) {
            return null;
        }

        $key = 'chat.link-preview.'.hash('sha256', $normalised);

        $remembered = cache()->get($key);

        if ($remembered !== null) {
            // Неудачу помним отдельным значением: `null` в кэше неотличим от
            // «ещё не спрашивали», и дохлая ссылка опрашивалась бы всякий раз.
            return $remembered === false ? null : $remembered;
        }

        $card = $this->read($normalised);

        cache()->put(
            $key,
            $card ?? false,
            now()->addMinutes($card === null ? self::KEEP_FAILURE_MINUTES : self::KEEP_MINUTES),
        );

        return $card;
    }

    /**
     * Приводит ссылку к тому виду, по которому за ней можно идти.
     *
     * Здесь же отсеивается всё, чему в карточке делать нечего: не веб-адрес,
     * адрес внутрь сети, свой собственный сайт. Последнее — не из осторожности:
     * своя страница закрыта входом, и карточка из неё вышла бы страницей входа.
     */
    private function normalise(string $url): ?string
    {
        $parts = parse_url(trim($url));

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        if (! in_array(strtolower($parts['scheme']), ['http', 'https'], strict: true)) {
            return null;
        }

        if ($this->isOurOwn($parts['host'])) {
            return null;
        }

        return $this->guard->allows($parts['host']) ? $url : null;
    }

    private function isOurOwn(string $host): bool
    {
        $ours = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $ours !== null && strcasecmp($host, (string) $ours) === 0;
    }

    /**
     * Идёт по ссылке и разбирает то, что вернулось.
     *
     * Переадресации проходим сами, по одной: Guzzle умеет это и сам, но тогда
     * проверить, куда именно нас увели, будет уже негде — а увести могут прямо
     * внутрь сети.
     *
     * @return array<string, string|null>|null
     */
    private function read(string $url): ?array
    {
        $current = $url;

        for ($hop = 0; $hop <= self::MAX_HOPS; $hop++) {
            try {
                $response = Http::withHeaders([
                    // Многие сайты отдают голый каркас тому, кого не узнали.
                    'User-Agent' => 'Mozilla/5.0 (compatible; BismarCRM/1.0; +link-preview)',
                    'Accept' => 'text/html,application/xhtml+xml',
                    'Accept-Language' => 'ru,en;q=0.8',
                ])
                    ->withoutRedirecting()
                    ->timeout(self::TIMEOUT_SECONDS)
                    ->connectTimeout(self::TIMEOUT_SECONDS)
                    ->get($current);
            } catch (ConnectionException) {
                return null;
            } catch (Throwable) {
                return null;
            }

            if ($response->redirect()) {
                $next = $this->absolute((string) $response->header('Location'), $current);

                if ($next === null || $this->normalise($next) === null) {
                    return null;
                }

                $current = $next;

                continue;
            }

            if ($response->failed()) {
                return null;
            }

            // Не страница — не карточка: за картинкой или архивом здесь ничего
            // не стоит, а читать их целиком мы всё равно не будем.
            if (! Str::contains(strtolower((string) $response->header('Content-Type')), 'text/html')) {
                return null;
            }

            return $this->parse($this->capped($response), $current);
        }

        return null;
    }

    /**
     * Тело ответа, но не больше отведённого.
     *
     * Читается из потока, а не через `body()`: тот собирает всё в одну строку, и
     * страница на полсотни мегабайт оседала бы в памяти целиком — притом что
     * нужен нам только её заголовок.
     */
    private function capped(Response $response): string
    {
        $stream = $response->toPsrResponse()->getBody();
        $html = '';

        while (! $stream->eof() && strlen($html) < self::MAX_BYTES) {
            $chunk = $stream->read(self::MAX_BYTES - strlen($html));

            // Пустой кусок при незакрытом потоке означает, что читать больше
            // нечего: без этого цикл крутился бы вхолостую.
            if ($chunk === '') {
                break;
            }

            $html .= $chunk;
        }

        return $html;
    }

    /**
     * Достаёт из страницы то, что она сама о себе объявила.
     *
     * Сперва Open Graph — его пишут ровно для этого, — потом обычные заголовок
     * и описание. Карточка без названия не карточка: показывать голый адрес и
     * так умеет сама ссылка.
     *
     * @return array<string, string|null>|null
     */
    private function parse(string $html, string $url): ?array
    {
        $document = new DOMDocument;

        // Разметка в интернете кривая всегда; сообщения об этом нам не нужны.
        $previous = libxml_use_internal_errors(true);

        // Подсказка о кодировке: без неё кириллица в заголовке превращается в
        // вопросительные знаки — DOMDocument считает всё latin1.
        $loaded = $document->loadHTML(
            '<?xml encoding="UTF-8">'.$this->toUtf8($html),
            LIBXML_NOWARNING | LIBXML_NOERROR,
        );

        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            return null;
        }

        $xpath = new DOMXPath($document);

        $title = $this->meta($xpath, 'og:title')
            ?? $this->meta($xpath, 'twitter:title')
            ?? $this->text($xpath, '//title');

        if ($title === null) {
            return null;
        }

        $image = $this->meta($xpath, 'og:image') ?? $this->meta($xpath, 'twitter:image');

        return [
            'url' => $url,
            // Домен показывается отдельной строкой: по нему решают, стоит ли
            // вообще нажимать.
            'host' => (string) parse_url($url, PHP_URL_HOST),
            'title' => Str::limit($title, 120),
            'description' => Str::limit(
                $this->meta($xpath, 'og:description')
                    ?? $this->meta($xpath, 'twitter:description')
                    ?? $this->metaByName($xpath, 'description')
                    ?? '',
                200,
            ) ?: null,
            'site_name' => $this->meta($xpath, 'og:site_name'),
            // Адрес картинки бывает относительным — в карточке он должен быть
            // таким, чтобы по нему сходил браузер.
            'image' => $image === null ? null : $this->absolute($image, $url),
        ];
    }

    /**
     * Приводит страницу к UTF-8.
     *
     * Русские сайты на windows-1251 ещё живы, и заголовок с них без перевода
     * читается набором знаков.
     */
    private function toUtf8(string $html): string
    {
        if (preg_match('/charset=["\']?\s*([\w-]+)/i', substr($html, 0, 2048), $found) !== 1) {
            return $html;
        }

        $charset = strtoupper($found[1]);

        if (in_array($charset, ['UTF-8', 'UTF8'], strict: true)) {
            return $html;
        }

        $converted = @mb_convert_encoding($html, 'UTF-8', $charset);

        return is_string($converted) ? $converted : $html;
    }

    private function meta(DOMXPath $xpath, string $property): ?string
    {
        return $this->text($xpath, sprintf('//meta[@property="%s"]/@content', $property));
    }

    private function metaByName(DOMXPath $xpath, string $name): ?string
    {
        return $this->text($xpath, sprintf('//meta[@name="%s"]/@content', $name));
    }

    private function text(DOMXPath $xpath, string $query): ?string
    {
        $found = $xpath->query($query);
        $value = $found === false ? null : $found->item(0)?->nodeValue;
        $value = $value === null ? '' : trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        return $value === '' ? null : $value;
    }

    /** Относительный адрес — в полный, от адреса самой страницы. */
    private function absolute(string $link, string $base): ?string
    {
        if ($link === '') {
            return null;
        }

        if (str_starts_with($link, 'http://') || str_starts_with($link, 'https://')) {
            return $link;
        }

        $parts = parse_url($base);

        if ($parts === false || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $root = $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');

        if (str_starts_with($link, '//')) {
            return $parts['scheme'].':'.$link;
        }

        if (str_starts_with($link, '/')) {
            return $root.$link;
        }

        $directory = rtrim(dirname($parts['path'] ?? '/'), '/');

        return $root.$directory.'/'.$link;
    }
}
