<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Bring a database and its caches up to this copy of the code, in one command.
 *
 * The v1 twin of this exists for the same reason: the alternative is seven
 * commands typed into a hosting panel in an order that is not obvious, where
 * getting it wrong fails quietly. Clearing the config cache after rebuilding it
 * throws away the thing you just made; running `config:cache` before editing
 * `.env` freezes the values you were about to change. The site keeps working
 * either way, on yesterday's settings.
 *
 * What differs from v1 is what can go wrong. v1's trap is a schema dump that
 * needs the `mysql` client. v2's is the front end: it is a compiled Vue
 * application, `public/build` is not in the repository, and without it every
 * page renders as an empty shell with no error anywhere.
 *
 * Safe to run twice.
 */
class Deploy extends Command
{
    protected $signature = 'eswachh:deploy
                            {--seed : Also seed the administrator, site content and message wording.}
                            {--force : Do not ask, even in production.}
                            {--pretend : Say what would happen and change nothing.}';

    protected $description = 'Run pending migrations, seed a blank database, and rebuild the caches';

    public function handle(): int
    {
        /*
         * Clear the config cache before reading a single value from it.
         *
         * Otherwise this reports the database the *last* deploy cached rather
         * than the one it is about to touch: config() answers from
         * bootstrap/cache/config.php while the queries run against whatever
         * .env says now. The v1 version of this command was caught doing
         * exactly that - naming one database and counting rows in another -
         * which on a deploy is the worst possible bug, because printing the
         * name is the whole point.
         */
        Artisan::call('config:clear');

        $database = config('database.connections.'.config('database.default').'.database');

        $this->newLine();
        $this->line('  <fg=cyan>Database</> '.$database);
        $this->line('  <fg=cyan>URL</> '.config('app.url'));
        $this->line('  <fg=cyan>Environment</> '.app()->environment());
        $this->newLine();

        try {
            DB::connection()->getPdo();
        } catch (Throwable $e) {
            $this->error('Cannot reach that database: '.$e->getMessage());

            return self::FAILURE;
        }

        foreach ($this->warnings() as $warning) {
            $this->warn('  '.$warning);
        }

        $blank = ! Schema::hasTable('migrations');
        $pending = $this->pendingMigrations();

        if ($blank) {
            $this->line('  <fg=yellow>This database is empty.</> Everything will be created from scratch.');
        } elseif ($pending === []) {
            $this->line('  <fg=green>No pending migrations.</> The schema is already up to date.');
        } else {
            $this->line('  <fg=yellow>'.count($pending).' migration(s) to run:</>');

            foreach ($pending as $migration) {
                $this->line('    '.$migration);
            }
        }

        $this->newLine();

        if ($this->option('pretend')) {
            $this->comment('  Pretending: nothing was changed.');

            return self::SUCCESS;
        }

        /*
         * The one question worth asking. Pointing a staging .env at the live
         * database and "trying the migrations out" is the single mistake here
         * that re-uploading files cannot undo.
         */
        if (! $this->option('force') && ! $this->confirm("Run against '{$database}'?", false)) {
            $this->comment('  Nothing was changed.');

            return self::SUCCESS;
        }

        $steps = [
            'Closing the site' => fn () => $pending || $blank ? Artisan::call('down') : null,
            'Running migrations' => fn () => Artisan::call('migrate', ['--force' => true]),
        ];

        if ($this->option('seed') || $blank) {
            /*
             * An empty database has no administrator, so there is nobody to
             * sign in as - and no message templates, which fails silently:
             * Messenger looks one up, does not find it, writes a line to the
             * log and returns. Assumed when the database is blank; opt-in
             * otherwise.
             */
            $steps['Seeding the administrator, content and message wording'] =
                fn () => Artisan::call('db:seed', ['--force' => true]);
        }

        // Clear, then build. In that order, always.
        $steps['Clearing caches'] = function () {
            Artisan::call('config:clear');
            Artisan::call('cache:clear');
            Artisan::call('route:clear');
            Artisan::call('view:clear');
        };

        $steps['Rebuilding caches'] = function () {
            Artisan::call('config:cache');
            Artisan::call('route:cache');
            Artisan::call('view:cache');
        };

        $steps['Linking storage'] = function () {
            try {
                Artisan::call('storage:link');
            } catch (Throwable) {
                // Already there.
            }
        };

        $steps['Opening the site'] = fn () => Artisan::call('up');

        foreach ($steps as $label => $step) {
            try {
                $this->components->task('  '.$label, function () use ($step) {
                    $step();

                    return true;
                });
            } catch (Throwable $e) {
                $this->newLine();
                $this->error('  Stopped at: '.$label);
                $this->error('  '.$e->getMessage());
                $this->newLine();
                $this->warn('  The site is still closed. Fix the above, then run this again.');
                $this->warn('  To reopen it without deploying: php artisan up');

                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->report();

        return self::SUCCESS;
    }

    /**
     * Things that will not stop the deploy but will ruin the result.
     *
     * @return array<int, string>
     */
    private function warnings(): array
    {
        $warnings = [];

        /*
         * The compiled front end.
         *
         * This is v2's version of a silent failure. The office and the public
         * site are Vue applications served from public/build, which is
         * gitignored - so a checkout or an upload that skipped it produces a
         * site that returns 200, renders an empty page, and logs nothing at
         * all. It has already happened once on the development machine, where
         * a stopped Vite server sent every page to a build from three weeks
         * earlier and none of the day's work appeared.
         */
        if (! file_exists(public_path('build/manifest.json'))) {
            $warnings[] = 'public/build is missing. The pages will render empty.';
            $warnings[] = '  Run `npm run build` locally and upload public/build - it is not in the repository.';
        }

        if (config('app.debug') && app()->environment('production')) {
            $warnings[] = 'APP_DEBUG is on in production. An error page will show the database password.';
        }

        // Messages only leave a production copy with the flag on, so the two
        // together are what a staging site must never have.
        if (app()->environment('production') && config('services.whatsapp.enabled')) {
            $warnings[] = 'WhatsApp delivery is ON. If this is a test site, real customers will be messaged.';
        }

        if (! config('app.timezone') || config('app.timezone') === 'UTC') {
            $warnings[] = 'APP_TIMEZONE is not Asia/Kolkata. Every imported date will be out by five and a half hours.';
        }

        if ($warnings !== []) {
            $warnings[] = '';
        }

        return $warnings;
    }

    /**
     * What is left to do, without running anything.
     *
     * `migrate:status` is parsed rather than reimplemented so this cannot
     * disagree with what `migrate` will actually do.
     *
     * @return array<int, string>
     */
    private function pendingMigrations(): array
    {
        if (! Schema::hasTable('migrations')) {
            return [];
        }

        Artisan::call('migrate:status');

        $pending = [];

        foreach (preg_split('/\R/', Artisan::output()) as $line) {
            if (str_contains($line, 'Pending') && preg_match('/(\d{4}_\d{2}_\d{2}_\d+_\w+)/', $line, $found)) {
                $pending[] = $found[1];
            }
        }

        return $pending;
    }

    /**
     * What the database holds now, so somebody can see it worked without
     * opening the site.
     */
    private function report(): void
    {
        $counts = [];

        $tables = [
            'users' => 'People',
            'customers' => 'Customers',
            'subscriptions' => 'Plans',
            'payments' => 'Payments',
            'message_templates' => 'Message templates',
        ];

        foreach ($tables as $table => $label) {
            if (Schema::hasTable($table)) {
                $counts[] = [$label, number_format(DB::table($table)->count())];
            }
        }

        if ($counts !== []) {
            $this->table(['In the database', 'Rows'], $counts);
        }

        if (Schema::hasTable('users') && DB::table('users')->count() === 0) {
            $this->warn('  There are no users, so nobody can sign in.');
            $this->warn('  Run it again with --seed, or import from v1 with eswachh:import.');
        }

        if (Schema::hasTable('message_templates') && DB::table('message_templates')->count() === 0) {
            $this->warn('  There are no message templates, so nothing will be sent to anybody.');
            $this->warn('  That failure is silent - run this again with --seed.');
        }

        $this->line('  <fg=green>Done.</> Check the rest with: <fg=cyan>php artisan eswachh:check-integrations</>');
        $this->newLine();
    }
}
