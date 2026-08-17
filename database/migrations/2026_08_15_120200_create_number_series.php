<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Something for two simultaneous invoice numbers to queue behind.
 *
 * Numbering used to lock the branch a number belonged to, which let branches
 * run independent series. Branches are gone, and sectors are the wrong
 * replacement - somebody covering three of them would have their invoices split
 * across three runs, and gaps in an invoice series are what gets questioned at
 * audit.
 *
 * So one series for the business, and one row per kind to take the lock on.
 * Locking the highest existing number instead cannot work: when none has been
 * issued yet there is no row to lock, and that is precisely when two requests
 * collide.
 *
 * The row holds no counter. The next number is still read from the invoices
 * themselves, so this table can be emptied without losing a sequence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('number_series', function (Blueprint $table) {
            // INV, CMP. The whole key: short, and there will never be many.
            $table->string('kind', 10)->primary();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('number_series');
    }
};
