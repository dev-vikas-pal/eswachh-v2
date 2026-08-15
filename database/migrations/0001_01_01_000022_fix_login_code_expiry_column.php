<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stops a sign in code renewing itself every time it is guessed at.
 *
 * expires_at was created as a MySQL TIMESTAMP. Where
 * explicit_defaults_for_timestamp is off - which it is on a stock XAMPP and on
 * plenty of hosts - MySQL gives the first TIMESTAMP column in a table an
 * implicit "DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP".
 *
 * The row is updated on every wrong guess, to count the attempt. So every wrong
 * guess pushed the expiry to the moment of the guess, and a code could be
 * worked at indefinitely as long as somebody kept trying. The attempt ceiling
 * still capped it at five, but the five minute life meant nothing.
 *
 * A separate migration rather than an edit to the original, because the table
 * already exists wherever this has been run.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('login_codes')) {
            return;
        }

        // Raw, because Doctrine is not installed and a change() on a timestamp
        // would not remove the ON UPDATE clause anyway.
        DB::statement('ALTER TABLE `login_codes` MODIFY `expires_at` DATETIME NOT NULL');
        DB::statement('ALTER TABLE `login_codes` MODIFY `consumed_at` DATETIME NULL DEFAULT NULL');

        /*
         * Anything outstanding is spent. A code issued under the old column may
         * have had its expiry pushed forward, and there is no way to tell which
         * - so none of them are trusted. The cost is that somebody mid-sign-in
         * asks for another code.
         */
        DB::table('login_codes')->whereNull('consumed_at')->update(['consumed_at' => now()]);
    }

    public function down(): void
    {
        if (! Schema::hasTable('login_codes')) {
            return;
        }

        DB::statement('ALTER TABLE `login_codes` MODIFY `expires_at` TIMESTAMP NOT NULL');
        DB::statement('ALTER TABLE `login_codes` MODIFY `consumed_at` TIMESTAMP NULL DEFAULT NULL');
    }
};
