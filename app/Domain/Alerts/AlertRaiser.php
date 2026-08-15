<?php

namespace App\Domain\Alerts;

use App\Models\Alert;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Raises an alert once, rather than every night.
 *
 * The dedupe key is the whole design. A nightly job that says "4 payments could
 * not be reconciled" every single night produces a list nobody reads within a
 * week, and then the one alert that mattered is lost in it. Keying on the thing
 * plus the day means the same problem is stated once.
 */
class AlertRaiser
{
    public const PAYMENT_UNMATCHED = 'payment_unmatched';

    public const COMPLAINT_OVERDUE = 'complaint_overdue';

    public const CLOTH_MISMATCH = 'cloth_mismatch';

    public const CLEANER_UNMARKED = 'cleaner_unmarked';

    public const SUBSCRIPTION_UNASSIGNED = 'subscription_unassigned';

    public const BACKUP_FAILED = 'backup_failed';

    /**
     * @param  array<string, mixed>  $link
     */
    public function raise(
        string $type,
        string $title,
        ?string $branchId = null,
        ?string $body = null,
        string $severity = 'warning',
        array $link = [],
        ?string $uniqueBy = null,
    ): ?Alert {
        // Defaults to once per type, per branch, per day.
        $key = sprintf(
            '%s|%s|%s',
            $type,
            $branchId ?? 'all',
            $uniqueBy ?? Carbon::today()->toDateString(),
        );

        try {
            return Alert::create([
                'branch_id' => $branchId,
                'type' => $type,
                'severity' => $severity,
                'title' => $title,
                'body' => $body,
                'link_route' => $link['route'] ?? null,
                'link_params' => $link['params'] ?? null,
                'dedupe_key' => $key,
            ]);
        } catch (QueryException $e) {
            // Already raised. The unique index is what guarantees it, so this
            // holds even with two servers running the same schedule.
            if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * Close an alert because the thing behind it was dealt with.
     */
    public function resolve(string $type, ?string $branchId = null, ?string $uniqueBy = null): void
    {
        Alert::query()
            ->where('type', $type)
            ->where('branch_id', $branchId)
            ->open()
            ->when($uniqueBy, fn ($q) => $q->where('dedupe_key', 'like', "%|{$uniqueBy}"))
            ->update(['resolved_at' => now()]);
    }
}
