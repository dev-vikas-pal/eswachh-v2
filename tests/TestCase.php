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
     * Refuse to run against anything but the test database.
     *
     * phpunit.xml names its own database, and that is normally the end of it -
     * except that a cached config beats it. `bootstrap/cache/config.php` is
     * read before the environment, so one `php artisan config:cache` run while
     * pointed at the working database silently redirects the entire suite onto
     * live data. It happened here: the suite ran against 203 real customers,
     * and the only thing that saved them was RefreshDatabase wrapping each test
     * in a transaction.
     *
     * That is far too thin a margin. `migrate:fresh` runs on a stale schema and
     * drops every table, and it would have done it to the working database
     * without a word.
     *
     * Checked here rather than left to discipline, because the failure is
     * invisible: the suite passes, or fails in confusing ways, and nothing
     * mentions which database it is talking to.
     */
    protected function setUp(): void
    {
        /*
         * Checked before the application boots, and by looking at the file
         * rather than at config().
         *
         * It has to be before: RefreshDatabase does its work inside
         * parent::setUp(), and on a stale schema that means migrate:fresh -
         * every table dropped. A check that runs afterwards would report the
         * problem to an empty database.
         *
         * config() is not available this early either, which is fine, because
         * the cached file *is* the hazard. Nothing else can override
         * phpunit.xml, and a test run never wants one.
         */
        $cached = dirname(__DIR__).'/bootstrap/cache/config.php';

        if (file_exists($cached)) {
            $this->fail(
                "bootstrap/cache/config.php exists, and a cached config overrides phpunit.xml.\n"
                ."The suite would run against whichever database was cached - which has been the\n"
                ."working one. Run `php artisan config:clear` and try again."
            );
        }

        parent::setUp();
    }

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
