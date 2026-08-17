<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;

/**
 * Reading the application log from a screen.
 *
 * v1 had this and it earns its place: when something goes wrong on a live
 * server, the person who can help is usually not the person with shell access.
 *
 * Three things make it safe enough to expose:
 *
 *  - the date is matched against a strict pattern and then rebuilt into a
 *    filename here, so nothing from the request ever reaches the filesystem as
 *    a path. A log viewer that concatenates user input onto a directory is the
 *    classic way to hand somebody the .env file;
 *  - only the daily files this application writes are listed;
 *  - it is administrator only. Logs carry phone numbers, payment references and
 *    occasionally a whole request body, so this is not a franchise owner's
 *    screen.
 *
 * Only the last ten days exist to read: Monolog deletes the rest.
 */
class LogController extends Controller implements HasMiddleware
{
    /** What the daily channel writes, and the only shape we will open. */
    private const PATTERN = '/^\d{4}-\d{2}-\d{2}$/';

    /** Kept in step with config('logging.channels.daily.days'). */
    private const KEEP_DAYS = 10;

    public static function middleware(): array
    {
        return ['can:manage.master'];
    }

    /**
     * Which days have a log, newest first.
     */
    public function index(): JsonResponse
    {
        $days = [];

        foreach ($this->files() as $path) {
            if (! preg_match('/laravel-(\d{4}-\d{2}-\d{2})\.log$/', basename($path), $m)) {
                continue;
            }

            $days[] = [
                'date' => $m[1],
                'size' => $this->readableSize(filesize($path) ?: 0),
                'bytes' => filesize($path) ?: 0,
                'modified' => Carbon::createFromTimestamp(filemtime($path) ?: 0)->toIso8601String(),
            ];
        }

        usort($days, fn ($a, $b) => strcmp($b['date'], $a['date']));

        return response()->json([
            'data' => $days,
            'meta' => [
                'kept_days' => self::KEEP_DAYS,
                'note' => 'Older files are deleted automatically. Nothing here is kept beyond '
                    .self::KEEP_DAYS.' days.',
            ],
        ]);
    }

    /**
     * One day's entries, newest first.
     */
    /**
     * Empty one day's log.
     *
     * Truncated rather than deleted: the logger holds the file open, and a
     * deleted file on a running process is written to a handle nobody can read
     * until the next rotation - so the log would appear to keep growing while
     * the screen showed nothing.
     *
     * Today's file is emptied, not removed, for the same reason.
     */
    public function destroy(string $date): JsonResponse
    {
        abort_unless(preg_match(self::PATTERN, $date) === 1, 404);

        $path = storage_path('logs/laravel-'.$date.'.log');

        abort_unless(is_file($path), 404);

        file_put_contents($path, '');

        /*
         * Recorded in the log it just emptied, which is the point: "the log is
         * empty" and "somebody emptied the log" look identical afterwards, and
         * only one of them is worth knowing.
         */
        Log::info('Log file emptied by hand.', [
            'file' => 'laravel-'.$date.'.log',
            'by' => auth()->user()?->name,
        ]);

        return response()->json(['message' => 'That day has been emptied.']);
    }

    public function show(Request $request, string $date): JsonResponse
    {
        // Matched, never trusted. The filename is built from our own constant
        // and the matched digits, so no part of the request is a path.
        abort_unless(preg_match(self::PATTERN, $date) === 1, 404);

        $filters = $request->validate([
            'level' => ['sometimes', 'string', 'max:20'],
            'search' => ['sometimes', 'string', 'max:100'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:2000'],
        ]);

        $path = storage_path('logs/laravel-'.$date.'.log');

        abort_unless(is_file($path), 404);

        $entries = $this->parse($path);

        if ($level = strtoupper($filters['level'] ?? '')) {
            $entries = array_values(array_filter($entries, fn ($e) => $e['level'] === $level));
        }

        if ($search = $filters['search'] ?? null) {
            $needle = mb_strtolower($search);
            $entries = array_values(array_filter(
                $entries,
                fn ($e) => str_contains(mb_strtolower($e['message'].' '.$e['context']), $needle),
            ));
        }

        $total = count($entries);

        // Newest first, and capped: a busy day can be tens of thousands of
        // lines, and sending all of them helps nobody.
        $entries = array_slice(array_reverse($entries), 0, $filters['limit'] ?? 300);

        return response()->json([
            'data' => $entries,
            'meta' => [
                'date' => $date,
                'total' => $total,
                'shown' => count($entries),
                'levels' => $this->levelCounts($path),
            ],
        ]);
    }

    // --------------------------------------------------------------- private

    /**
     * @return array<int, string>
     */
    private function files(): array
    {
        $dir = storage_path('logs');

        if (! is_dir($dir)) {
            return [];
        }

        return File::glob($dir.'/laravel-*.log') ?: [];
    }

    /**
     * Split a log file into entries.
     *
     * Monolog writes one entry per line but a stack trace runs over many, so a
     * line that does not start with a timestamp belongs to the entry above it.
     * Splitting on newlines alone turns one exception into forty useless rows.
     *
     * @return array<int, array<string, string>>
     */
    private function parse(string $path): array
    {
        $entries = [];
        $current = null;

        $handle = fopen($path, 'r');

        if (! $handle) {
            return [];
        }

        while (($line = fgets($handle)) !== false) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2})[^\]]*\]\s+(\w+)\.(\w+):\s?(.*)$/s', $line, $m)) {
                if ($current) {
                    $entries[] = $this->finish($current);
                }

                $current = [
                    'at' => str_replace(' ', 'T', $m[1]),
                    'environment' => $m[2],
                    'level' => strtoupper($m[3]),
                    'message' => rtrim($m[4]),
                    'context' => '',
                ];

                continue;
            }

            // A continuation line: part of the trace for the entry above.
            if ($current) {
                $current['context'] .= $line;
            }
        }

        fclose($handle);

        if ($current) {
            $entries[] = $this->finish($current);
        }

        return $entries;
    }

    /**
     * @param  array<string, string>  $entry
     * @return array<string, string>
     */
    private function finish(array $entry): array
    {
        // Trimmed hard: a trace can be a hundred kilobytes, and the screen is
        // for spotting what went wrong, not for reading every frame.
        $entry['context'] = mb_substr(trim($entry['context']), 0, 4000);

        return $entry;
    }

    /**
     * @return array<string, int>
     */
    private function levelCounts(string $path): array
    {
        $counts = [];

        foreach ($this->parse($path) as $entry) {
            $counts[$entry['level']] = ($counts[$entry['level']] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    private function readableSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return round($bytes / 1024).' KB';
        }

        return round($bytes / 1024 / 1024, 1).' MB';
    }
}
