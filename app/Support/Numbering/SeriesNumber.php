<?php

namespace App\Support\Numbering;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issues the next number in a per branch, per financial year series.
 *
 * Invoices and complaints both need a short number a person can read down a
 * phone. Both need it unbroken within a branch, and both need two simultaneous
 * requests not to get the same one. That is one problem, so it is one class.
 *
 * Locking the branch row serialises issuing within a branch and lets branches
 * run independently.
 */
class SeriesNumber
{
    /**
     * @param  class-string<Model>  $model   Where existing numbers live
     * @param  string  $column               The column holding them
     * @param  string  $kind                 Letter block in the number: INV, CMP
     */
    public static function next(?string $branchId, string $model, string $column, string $kind): string
    {
        $year = self::financialYear();

        return DB::transaction(function () use ($branchId, $model, $column, $kind, $year) {
            $branch = $branchId
                ? Branch::query()->lockForUpdate()->find($branchId)
                : null;

            $prefix = sprintf('%s/%s/%s/', strtoupper($branch?->code ?? 'ESW'), $kind, $year);

            // Zero padded to a fixed width, so ordering the strings orders the
            // numbers. Without that, 10 sorts before 9.
            $highest = $model::query()
                ->withoutGlobalScopes()
                ->withTrashed()
                ->where($column, 'like', $prefix.'%')
                ->orderByDesc($column)
                ->value($column);

            $sequence = $highest
                ? ((int) substr($highest, strlen($prefix))) + 1
                : 1;

            return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        });
    }

    /**
     * The Indian financial year, which starts in April. Written as 2025-26.
     */
    public static function financialYear(?Carbon $on = null): string
    {
        $on ??= Carbon::today();

        $start = $on->month >= 4 ? $on->year : $on->year - 1;

        return $start.'-'.substr((string) ($start + 1), 2);
    }
}
