<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Database backups.
 *
 * Administrator only, and never a public disk: a database dump contains every
 * customer's details and every hashed password, so it is served through a
 * checked route rather than sitting under a guessable URL in public/.
 */
class BackupController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return ['can:manage.master'];
    }

    public function index(): JsonResponse
    {
        $disk = Storage::disk('local');

        $files = collect($disk->files('backups'))
            ->filter(fn (string $f) => str_ends_with($f, '.sql'))
            ->sortDesc()
            ->values()
            ->map(fn (string $f) => [
                'name' => basename($f),
                'size' => $disk->size($f),
                'size_human' => $this->humanSize($disk->size($f)),
                'taken_at' => Carbon::createFromTimestamp($disk->lastModified($f))->toIso8601String(),
            ]);

        return response()->json([
            'data' => $files,
            'meta' => [
                'count' => $files->count(),
                'total_bytes' => $files->sum('size'),
                // The figure that matters: a backup nobody has taken for a
                // fortnight is not a backup.
                'latest' => $files->first()['taken_at'] ?? null,
            ],
        ]);
    }

    /** Take one now. */
    public function store(): JsonResponse
    {
        $exit = Artisan::call('eswachh:backup');

        return response()->json([
            'message' => $exit === 0
                ? 'Backup taken and checked.'
                : 'The backup did not work. The reason is in the log and an alert has been raised.',
            'output' => trim(Artisan::output()),
        ], $exit === 0 ? 201 : 500);
    }

    public function download(string $name): StreamedResponse
    {
        // Rebuilt from the basename so a name like ../../.env cannot walk out
        // of the backups directory.
        $safe = basename($name);

        abort_unless(str_ends_with($safe, '.sql'), 404);

        $path = 'backups/'.$safe;

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path);
    }

    public function destroy(string $name): JsonResponse
    {
        $safe = basename($name);

        abort_unless(str_ends_with($safe, '.sql'), 404);

        Storage::disk('local')->delete('backups/'.$safe);

        return response()->json(['message' => 'Backup deleted.']);
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
