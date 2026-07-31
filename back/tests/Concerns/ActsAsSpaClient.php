<?php

declare(strict_types=1);

namespace Tests\Concerns;

/**
 * Makes test requests look like they came from the Nuxt SPA.
 *
 * Sanctum only starts a session for requests originating from a domain listed
 * in `sanctum.stateful`, which it detects via the Origin header. A browser
 * always sends that header; the test client does not, so any endpoint touching
 * the session needs it to be set explicitly.
 *
 * The origin is derived from `app.frontend_url` so that changing the SPA's port
 * in one place cannot leave the tests passing against a stale one.
 */
trait ActsAsSpaClient
{
    protected function setUpActsAsSpaClient(): void
    {
        $frontendUrl = (string) config('app.frontend_url');
        $host = (string) parse_url($frontendUrl, PHP_URL_HOST);
        $port = parse_url($frontendUrl, PHP_URL_PORT);

        config()->set('sanctum.stateful', [$host.($port ? ':'.$port : '')]);

        $this->withHeader('Origin', $frontendUrl);
    }
}
