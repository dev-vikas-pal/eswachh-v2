<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Concerns\MigratesOnlyWhenStale;

abstract class TestCase extends BaseTestCase
{
    /*
     * Skips the two and a half minute rebuild when the test database already
     * has every migration. Isolation is unchanged - each test still runs in a
     * transaction that is rolled back.
     */
    use MigratesOnlyWhenStale;

    /**
     * Make the next request look like it came from the SPA.
     *
     * Sanctum only starts a session for requests from a stateful origin, which
     * it decides from the Origin or Referer header. A browser always sends one;
     * a test does not unless it is asked to. Anything that signs in or signs
     * out needs this, because those are the calls that touch the session.
     */
    protected function fromSpa(): static
    {
        return $this->withHeader('Origin', config('app.url'));
    }
}
