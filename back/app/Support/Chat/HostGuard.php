<?php

declare(strict_types=1);

namespace App\Support\Chat;

/**
 * Пускать ли сервер по этому адресу.
 *
 * Стоит перед всяким обращением наружу по ссылке, которую прислал человек. Без
 * него карточка ссылки превращается в дыру: попросив показать превью для
 * `http://127.0.0.1:8100/api/...` или `http://169.254.169.254/`, посторонний
 * заставляет сервер сходить туда за себя — изнутри сети, где ему открыто то,
 * что снаружи закрыто. Это классическая SSRF, и защита от неё бывает только
 * такая: разрешать лишь те адреса, что действительно ведут в интернет.
 *
 * Проверяется не имя, а то, во что оно разворачивается: `evil.example`
 * спокойно указывает на `127.0.0.1`, и по имени этого не видно. Проверять
 * приходится каждое звено переадресации — первый ответ может увести куда
 * угодно.
 *
 * Отдельным классом ради проверок: разрешение имён — обращение к сети, и
 * подменить его в тестах надо уметь, не подменяя заодно и сам разбор страницы.
 */
class HostGuard
{
    public function allows(string $host): bool
    {
        $host = trim($host, '[]');

        if ($host === '') {
            return false;
        }

        // Адрес, записанный числом, разворачивать не надо — он и так перед нами.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $this->isPublic($host);
        }

        $addresses = $this->resolve($host);

        // Имя, которое ни во что не разворачивается, никуда и не ведёт.
        if ($addresses === []) {
            return false;
        }

        // Все до единого: имя может отдавать несколько адресов, и хватит одного
        // внутреннего, чтобы запрос ушёл внутрь сети.
        foreach ($addresses as $address) {
            if (! $this->isPublic($address)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Во что разворачивается имя.
     *
     * @return list<string>
     */
    protected function resolve(string $host): array
    {
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];

        return array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['ip'] ?? $record['ipv6'] ?? null,
            $records,
        )));
    }

    /**
     * Ведёт ли адрес в интернет, а не внутрь.
     *
     * Частные и зарезервированные диапазоны отсеивает сам PHP: под них попадают
     * и `10.0.0.0/8`, и `127.0.0.0/8`, и `169.254.0.0/16` — тот самый адрес, по
     * которому в облаках лежат ключи от учётной записи машины.
     */
    private function isPublic(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }
}
