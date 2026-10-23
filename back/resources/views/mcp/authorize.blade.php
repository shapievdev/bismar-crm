{{--
    Экран согласия: сотрудник разрешает коннектору работать от его имени.

    Своя страница, а не опубликованная из пакета. Та собрана под обычное
    приложение Laravel: Tailwind через @vite и шрифты с чужого домена, — а здесь
    бэкенд отдаёт один только API, сборки стилей у него нет и манифест @vite
    уронил бы страницу. Поэтому вся вёрстка в одном файле.

    Три вещи, которые человек обязан увидеть прежде, чем нажать «разрешить»:
    кто просит, куда его вернут и под кем он сидит. Второе — главное: имя
    клиента придумывает сам клиент при регистрации (RFC 7591), и «Claude» в
    заголовке не значит ничего, пока адрес возврата не ведёт к Anthropic.

    Поля формы — только `auth_token` и CSRF: Passport 13 берёт сам запрос
    авторизации из сессии, а присланное сверяет с тем, что в ней лежит (см.
    RetrievesAuthRequestFromSession).
--}}
@php
    /** Куда вернут код: показываем хосты, а не адреса целиком. */
    $hosts = collect($client->redirect_uris ?? [])
        ->map(fn (string $uri): ?string => parse_url($uri, PHP_URL_HOST))
        ->filter()
        ->unique()
        ->values();
@endphp
    <!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Разрешить доступ — {{ config('app.name') }}</title>

    <style>
        :root {
            color-scheme: light dark;
            --paper: #ffffff;
            --ink: #16181d;
            --muted: #6b7280;
            --line: #e5e7eb;
            --accent: #1f6feb;
            --warn-bg: #fff8e5;
            --warn-line: #f0d48a;
            --warn-ink: #6b4e00;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --paper: #1b1d22;
                --ink: #f3f4f6;
                --muted: #9aa0ab;
                --line: #30343c;
                --accent: #4a90ff;
                --warn-bg: #2a2410;
                --warn-line: #4d421c;
                --warn-ink: #f0dca8;
            }

            body { background: #11131a; }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background: #f5f6f8;
            color: var(--ink);
            font: 16px/1.5 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
        }

        .card {
            width: 100%;
            max-width: 460px;
            background: var(--paper);
            border: 1px solid var(--line);
            border-radius: 14px;
            padding: 28px;
            box-shadow: 0 8px 28px rgb(16 18 24 / 8%);
        }

        h1 {
            margin: 0 0 6px;
            font-size: 21px;
            line-height: 1.3;
        }

        .lead {
            margin: 0 0 20px;
            color: var(--muted);
            font-size: 15px;
        }

        dl {
            margin: 0 0 20px;
            border: 1px solid var(--line);
            border-radius: 10px;
            padding: 14px 16px;
        }

        dt {
            color: var(--muted);
            font-size: 13px;
        }

        dd {
            margin: 2px 0 12px;
            font-weight: 500;
            word-break: break-word;
        }

        dd:last-of-type { margin-bottom: 0; }

        .notice {
            margin: 0 0 20px;
            padding: 12px 14px;
            border: 1px solid var(--warn-line);
            background: var(--warn-bg);
            color: var(--warn-ink);
            border-radius: 10px;
            font-size: 14px;
        }

        ul.rights {
            margin: 0 0 20px;
            padding-left: 20px;
            color: var(--muted);
            font-size: 14px;
        }

        .buttons {
            display: flex;
            gap: 10px;
        }

        .buttons form { flex: 1; }

        button {
            width: 100%;
            padding: 11px 14px;
            font: inherit;
            font-weight: 500;
            border-radius: 9px;
            cursor: pointer;
            border: 1px solid var(--line);
            background: var(--paper);
            color: var(--ink);
        }

        button.primary {
            border-color: var(--accent);
            background: var(--accent);
            color: #fff;
        }

        button:hover { filter: brightness(0.97); }
    </style>
</head>
<body>
<main class="card">
    <h1>Разрешить доступ приложению «{{ $client->name }}»?</h1>

    <p class="lead">
        Оно сможет работать в платформе от вашего имени — ровно в тех пределах,
        какие вам открыты.
    </p>

    <dl>
        <dt>Вы вошли как</dt>
        <dd>
            {{ $user->name }}
            @if ($user->job_title)
                · {{ $user->job_title }}
            @endif
        </dd>

        <dt>Уровень доступа</dt>
        <dd>{{ $user->accessLevel()->label() }}</dd>

        <dt>Куда вас вернут</dt>
        <dd>{{ $hosts->isEmpty() ? 'адрес не указан' : $hosts->implode(', ') }}</dd>
    </dl>

    @if ($hosts->isNotEmpty() && ! $hosts->contains(fn (string $host): bool => str_ends_with($host, 'claude.ai') || str_ends_with($host, 'claude.com')))
        {{--
            Разрешённые домены возврата перечислены в config/mcp.php, и сюда
            чужой не должен доходить вовсе. Предупреждение — на случай, если
            список однажды расширят: человеку решать, узнаёт он этот адрес или
            нет.
        --}}
        <p class="notice">
            Возврат ведёт не на claude.ai. Если вы не знаете, что это за адрес, — откажите.
        </p>
    @endif

    <ul class="rights">
        <li>Приложение действует вашими правами: ни одного нового оно не получает.</li>
        <li>Всё, что оно сделает, записывается в журнал от вашего имени.</li>
        <li>Пароль ему не передаётся.</li>
        @foreach ($scopes as $scope)
            <li>Полномочие: {{ $scope->description }}.</li>
        @endforeach
    </ul>

    <div class="buttons">
        <form method="POST" action="{{ route('passport.authorizations.deny') }}">
            @csrf
            @method('DELETE')
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit">Отказать</button>
        </form>

        <form method="POST" action="{{ route('passport.authorizations.approve') }}">
            @csrf
            <input type="hidden" name="auth_token" value="{{ $authToken }}">
            <button type="submit" class="primary">Разрешить</button>
        </form>
    </div>
</main>
</body>
</html>
