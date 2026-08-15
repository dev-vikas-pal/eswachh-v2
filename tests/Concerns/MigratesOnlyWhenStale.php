<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Stops every test run rebuilding the test database from nothing.
 *
 * RefreshDatabase runs `migrate:fresh` once per process. Here that is about two
 * and a half minutes - dropping and recreating forty-eight tables on MariaDB -
 * and it is paid whether the run is the whole suite or a single file. Running
 * tests while working therefore cost more in waiting than in thinking, which is
 * how a suite quietly stops being run.
 *
 * Isolation is unchanged. Each test still runs inside a transaction that is
 * rolled back afterwards, exactly as before. The only thing skipped is the
 * rebuild, and only when the database already has every migration applied.
 *
 * How it works: RefreshDatabase skips the rebuild when
 * RefreshDatabaseState::$migrated is already true, so this sets that flag
 * before the trait looks at it. That is why this hooks setUpTraits - it runs
 * after the application is booted and before RefreshDatabase does its work.
 *
 * The check is deliberately conservative. Any migration file the database has
 * not recorded, a missing migrations table, or any error at all, and it lets
 * the normal rebuild happen. So adding a migration is picked up on its own:
 * the first run after it is slow, every run after that is not.
 *
 * `php artisan test --recreate-databases` still forces a rebuild, for when
 * something has been changed under the test database by hand.
 */
trait MigratesOnlyWhenStale
{
    protected function setUpTraits()
    {
        if (! RefreshDatabaseState::$migrated && $this->testDatabaseIsCurrent()) {
            RefreshDatabaseState::$migrated = true;
        }

        return parent::setUpTraits();
    }

    /**
     * Does the test database already have every migration on disk?
     */
    private function testDatabaseIsCurrent(): bool
    {
        if (in_array('--recreate-databases', $_SERVER['argv'] ?? [], true)) {
            return false;
        }

        try {
            if (! Schema::hasTable('migrations')) {
                return false;
            }

            $applied = DB::table('migrations')->pluck('migration')->all();
        } catch (\Throwable) {
            // No database, no connection, no permissions. Rebuild, and let the
            // real error surface from the migration itself rather than here.
            return false;
        }

        $onDisk = collect(File::files(database_path('migrations')))
            ->map(fn ($file) => $file->getFilenameWithoutExtension())
            ->all();

        /*
         * A file the database has not seen means the schema is behind. A row
         * with no file is ignored: that is a migration somebody deleted, and it
         * does not make the schema wrong.
         */
        return empty(array_diff($onDisk, $applied));
    }
}
