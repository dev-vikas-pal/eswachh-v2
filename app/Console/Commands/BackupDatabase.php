<?php

namespace App\Console\Commands;

use App\Domain\Alerts\AlertRaiser;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;

/**
 * Takes a copy of the database.
 *
 * Two things make a backup worth having, and v1's had neither.
 *
 * It is checked. A dump that failed halfway leaves a file that looks fine in a
 * directory listing and restores into nothing, so the size and the closing
 * statement are verified before it is called a success - and an alert is raised
 * when it is not.
 *
 * And old ones are removed on a stated schedule, so the disk does not fill up
 * silently and take the site down with it.
 */
class BackupDatabase extends Command
{
    protected $signature = 'eswachh:backup
                            {--keep=14 : How many daily backups to keep}
                            {--no-prune : Keep everything, however old}';

    protected $description = 'Dump the database to storage, verify it, and prune old copies';

    /** The last thing mysqldump writes when it finishes properly. */
    private const COMPLETION_MARKER = 'Dump completed';

    public function handle(AlertRaiser $alerts): int
    {
        $disk = Storage::disk('local');
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");

        $name = sprintf('eswachh-%s.sql', Carbon::now()->format('Y-m-d-His'));
        $relative = 'backups/'.$name;

        $disk->makeDirectory('backups');
        $path = $disk->path($relative);

        $this->info("Backing up {$config['database']}…");

        $process = new Process([
            $this->mysqldump(),
            /*
             * TCP, said outright.
             *
             * Left to itself mysqldump may try a named pipe or a socket file
             * depending on how it was built, and on Windows the pipe attempt is
             * what produced "Can't create TCP/IP socket (10106)" - an error
             * that names TCP while being about not using it.
             */
            '--protocol=TCP',
            '--host='.$config['host'],
            '--port='.$config['port'],
            '--user='.$config['username'],
            '--password='.$config['password'],
            // Rows written one per line, so a broken dump is obvious in a diff
            // rather than being one enormous unreadable line.
            '--skip-extended-insert',
            '--single-transaction',
            '--quick',
            '--default-character-set=utf8mb4',
            '--result-file='.$path,
            $config['database'],
        ]);

        // A big database takes a while; the default 60 seconds is not enough.
        $process->setTimeout(600);

        /*
         * Windows needs SystemRoot to open a socket at all.
         *
         * This runs from two places: the scheduler, which inherits a normal
         * shell environment, and the Backups screen, where the parent is the
         * web server - and a process spawned from there can arrive with almost
         * no environment. Without SystemRoot, Winsock cannot initialise and
         * mysqldump reports "Can't create TCP/IP socket (10106)", which is why
         * the same command worked from a terminal and failed from the button.
         *
         * Passed rather than assumed, so the two paths behave the same.
         */
        if (PHP_OS_FAMILY === 'Windows') {
            $process->setEnv(array_filter([
                'SystemRoot' => getenv('SystemRoot') ?: 'C:\\Windows',
                'TEMP' => getenv('TEMP') ?: sys_get_temp_dir(),
                'TMP' => getenv('TMP') ?: sys_get_temp_dir(),
                'PATH' => getenv('PATH') ?: '',
            ]));
        }

        $process->run();

        if (! $process->isSuccessful()) {
            return $this->failed($alerts, $relative, trim($process->getErrorOutput()) ?: 'mysqldump failed.');
        }

        if (! $problem = $this->verify($path)) {
            $size = $disk->size($relative);

            $this->info(sprintf('Backup written: %s (%s)', $name, $this->humanSize($size)));

            Log::info('Database backed up.', ['file' => $relative, 'bytes' => $size]);

            // A backup that succeeded closes yesterday's failure alert, so the
            // list reflects the situation rather than its history.
            $alerts->resolve(AlertRaiser::BACKUP_FAILED);

            if (! $this->option('no-prune')) {
                $this->prune((int) $this->option('keep'));
            }

            return self::SUCCESS;
        }

        return $this->failed($alerts, $relative, $problem);
    }

    /**
     * Is this file actually a usable dump?
     *
     * @return string|null  The problem, or null when it is sound
     */
    private function verify(string $path): ?string
    {
        if (! is_file($path)) {
            return 'The dump file was not created.';
        }

        $size = filesize($path) ?: 0;

        // A dump of a real database is never this small. A few hundred bytes
        // means a header and an error.
        if ($size < 1024) {
            return "The dump is only {$size} bytes, which is too small to be a real backup.";
        }

        // mysqldump writes its completion line last, so its presence is proof
        // the process reached the end rather than dying mid-table.
        $tail = '';
        $handle = fopen($path, 'r');

        if ($handle) {
            fseek($handle, max(0, $size - 512));
            $tail = (string) fread($handle, 512);
            fclose($handle);
        }

        if (! str_contains($tail, self::COMPLETION_MARKER)) {
            return 'The dump does not end with a completion marker, so it stopped part way.';
        }

        return null;
    }

    private function failed(AlertRaiser $alerts, string $relative, string $reason): int
    {
        $this->error('Backup failed: '.$reason);

        Log::error('Database backup failed.', ['file' => $relative, 'reason' => $reason]);

        // Raised where somebody will see it. A backup quietly failing for three
        // months is how a restore turns out to be impossible.
        $alerts->raise(
            AlertRaiser::BACKUP_FAILED,
            'The nightly backup did not work',
            body: $reason,
            severity: 'critical',
        );

        // Remove the stub so a broken file is never mistaken for a backup.
        Storage::disk('local')->delete($relative);

        return self::FAILURE;
    }

    /**
     * Delete all but the most recent few.
     */
    private function prune(int $keep): void
    {
        $disk = Storage::disk('local');

        $files = collect($disk->files('backups'))
            ->filter(fn (string $file) => str_ends_with($file, '.sql'))
            // The filename carries the timestamp, so sorting the names sorts
            // them by age.
            ->sortDesc()
            ->values();

        $stale = $files->slice(max(1, $keep));

        foreach ($stale as $file) {
            $disk->delete($file);
        }

        if ($stale->isNotEmpty()) {
            $this->line("Removed {$stale->count()} backup(s) older than the last {$keep}.");
        }
    }

    /**
     * Where mysqldump actually is.
     *
     * XAMPP does not put it on the PATH, so real locations are checked before
     * falling back to the bare name. Checking the bare name first would always
     * "succeed" and then fail at run time with a shell error, which is what
     * makes a missing tool look like a broken database.
     */
    private function mysqldump(): string
    {
        $candidates = array_filter([
            env('MYSQLDUMP_PATH'),
            // The usual XAMPP locations, on whichever drive it was installed to.
            'C:\\xampp\\mysql\\bin\\mysqldump.exe',
            'D:\\xampp\\mysql\\bin\\mysqldump.exe',
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/opt/homebrew/bin/mysqldump',
        ]);

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        // Last resort: hope it is on the PATH. If it is not, the error names
        // the tool rather than blaming the database.
        return 'mysqldump';
    }

    private function humanSize(int $bytes): string
    {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024) {
                return round($bytes, 1).' '.$unit;
            }
            $bytes /= 1024;
        }

        return round($bytes, 1).' TB';
    }
}
