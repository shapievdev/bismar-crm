<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Enables Sanctum's cookie-based SPA authentication: requests coming from a
        // domain listed in `sanctum.stateful` are authenticated via the session.
        $middleware->statefulApi();

        /*
         * There is no login page to send a guest to — the sign-in form belongs to
         * the Nuxt application. Without this, the `auth` middleware falls back to
         * the framework default of `route('login')`, which is undefined here, and
         * an unauthenticated request that does not ask for JSON dies with a 500
         * instead of the 401 the handler below would have rendered.
         *
         * Одно исключение — экран согласия OAuth (`/oauth/authorize`), с
         * которого сотрудник разрешает коннектору claude.ai работать от его
         * имени. Это единственная страница приложения, которую Laravel отдаёт
         * сам, и открывает её человек в браузере: ответить ему 401 значило бы
         * показать пустую ошибку вместо входа. Поэтому его ведут на форму входа
         * Nuxt с просьбой вернуться сюда же.
         *
         * Возврат — только путь и только свой: `fullUrl()` увёл бы за собой
         * чужой хост, если подменить заголовок, а открытый перенос с нашей
         * формы входа — готовая ловушка для сотрудника.
         */
        $middleware->redirectGuestsTo(fn (Request $request): ?string => $request->is('oauth/*')
            ? '/login?redirect='.rawurlencode('/'.ltrim($request->getRequestUri(), '/'))
            : null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // MCP рядом с API: коннектор — такой же клиент без страниц, и отказ ему
        // должен приезжать разобранным ответом, а не вёрсткой страницы ошибки.
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->is('mcp') || $request->is('mcp/*'),
        );
    })->create();
