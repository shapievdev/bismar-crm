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
 */
trait ActsAsSpaClient
{
    protected function setUpActsAsSpaClient(): void
    {
        config()->set('sanctum.stateful', ['localhost:3000']);

        $this->withHeader('Origin', 'http://localhost:3000');
    }
}
