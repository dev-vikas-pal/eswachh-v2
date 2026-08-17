<?php

namespace App\Support\Numbering;

use App\Support\Settings\SiteSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Issues the next number in a per financial year series.
 *
 * Invoices and complaints both need a short number a person can read down a
 * phone. Both need it unbroken, and both need two simultaneous requests not to
 * get the same one. That is one problem, so it is one class.
 *
 * One series for the business, not one per territory. It used to run per
 * branch, keyed on the branch's code; sectors are the wrong replacement,
 * because somebody covering three of them would have their invoices split
 * across three runs and an accountant reading one would find gaps that are not
 * gaps. The prefix now comes from the invoice_prefix setting, which existed all
 * along and which nothing had ever read.
 *
 * A row in number_series is what two simultaneous requests queue behind.
 */
class SeriesNumber
{
    /**
     * @param  class-string<Model>  $model   Where existing numbers live
     * @param  string  $column               The column holding them
     * @param  string  $kind                 Letter block in the number: INV, CMP
     */
    public static function next(string $model, string $column, string $kind): string
    {
        $year = self::financialYear();
        $prefix = sprintf(
            '%s/%s/%s/',
            strtoupper((string) (SiteSettings::get('invoice_prefix') ?: 'ESW')),
            $kind,
            $year,
        );

        return DB::transaction(function () use ($model, $column, $kind, $prefix) {
            /*
             * Serialised on a row of its own.
             *
             * Locking the highest existing number cannot work: when there is no
             * row yet there is nothing to lock, and that is exactly the moment
             * two requests collide.
             */
            DB::table('number_series')->insertOrIgnore(['kind' => $kind]);
            DB::table('number_series')->where('kind', $kind)->lockForUpdate()->first();

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
