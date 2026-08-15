<?php

namespace App\Support\Database;

use Illuminate\Database\Schema\Blueprint;

/**
 * Shared column groups, registered as Blueprint macros.
 *
 * Every table gets the same spine, and no migration writes it by hand. If the
 * spine ever changes, it changes in one place.
 */
class SchemaMacros
{
    public static function register(): void
    {
        /*
         * Primary key. UUID version 7, so it is globally unique but still
         * time-ordered: rows append at the end of the index the way an
         * auto-increment does, instead of landing on random pages and
         * fragmenting the table.
         */
        Blueprint::macro('uuidKey', function (string $column = 'id') {
            /** @var Blueprint $this */
            return $this->char($column, 36)->primary();
        });

        /** A nullable reference to another UUID-keyed table. */
        Blueprint::macro('uuidRef', function (string $column) {
            /** @var Blueprint $this */
            return $this->char($column, 36)->nullable();
        });

        /**
         * Who touched the row, and when.
         *
         * These are deliberately nullable: a scheduled job has no logged in
         * user, and null there means "the system did it". That is a decision,
         * not an oversight - see HasAuditColumns.
         */
        Blueprint::macro('auditColumns', function () {
            /** @var Blueprint $this */
            $this->char('created_by', 36)->nullable();
            $this->char('updated_by', 36)->nullable();
            $this->char('deleted_by', 36)->nullable();
            $this->timestamps();
            $this->softDeletes();
        });

        /**
         * Marks a table as owned by a branch.
         *
         * Indexed because every query against this table filters on it: the
         * branch scope is applied globally, not per query.
         */
        Blueprint::macro('branchOwned', function (bool $nullable = false) {
            /** @var Blueprint $this */
            $column = $this->char('branch_id', 36);

            if ($nullable) {
                $column->nullable();
            }

            $this->index('branch_id');

            return $column;
        });
    }
}
