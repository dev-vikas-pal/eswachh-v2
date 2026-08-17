<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Let the branch columns go quiet.
 *
 * They are no longer read: visibility comes from the customer's sector and the
 * user_sector pivot. But they were all declared NOT NULL, so leaving them that
 * way would mean every insert still had to invent a value for a column nothing
 * consults - and the first one that could not would fail at the database rather
 * than anywhere a person could understand.
 *
 * Made nullable rather than dropped. Existing values stay exactly as they were,
 * so if the sector model turns out to be wrong the way back is to revert the
 * code, not to reconstruct data from a backup. A later migration drops them once
 * this has run long enough to be trusted.
 */
return new class extends Migration
{
    /**
     * Every table that carries the old copy of "whose customer is this".
     */
    private const TABLES = [
        'customers',
        'vehicles',
        'subscriptions',
        'complaints',
        'complaint_events',
        'service_logs',
        'cloth_movements',
        'cloth_entries',
        'payments',
        'attendances',
        'messages',
    ];

    public function up(): void
    {
        /*
         * Raw SQL rather than the schema builder, on purpose.
         *
         * Changing a column with ->change() needs doctrine/dbal to read the
         * existing definition, and getting that read wrong silently rewrites
         * the column's type. char(36) is what these are; saying so outright is
         * shorter than the alternative and cannot guess.
         */
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `branch_id` CHAR(36) NULL");
        }
    }

    public function down(): void
    {
        /*
         * Restoring NOT NULL needs every row to have a value, and rows written
         * while this was relaxed will not. Filled from the customer's sector
         * where it can be, which is where the value came from originally.
         */
        DB::statement('
            UPDATE customers
            JOIN sectors ON sectors.id = customers.sector_id
            SET customers.branch_id = sectors.branch_id
            WHERE customers.branch_id IS NULL
        ');

        foreach (self::TABLES as $table) {
            if ($table === 'customers') {
                continue;
            }

            if (DB::table($table)->whereNull('branch_id')->exists()) {
                throw new RuntimeException(
                    "Cannot restore NOT NULL on {$table}.branch_id: rows written under sector scoping have none. "
                    .'Decide what those rows belong to before rolling this back.'
                );
            }
        }

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE `{$table}` MODIFY `branch_id` CHAR(36) NOT NULL");
        }
    }
};
