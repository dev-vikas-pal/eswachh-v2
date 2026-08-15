<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Business details the office can change without a deployment.
     *
     * Deliberately NOT where credentials go. A gateway secret belongs in .env,
     * where it is not in a database dump, not on a screen, and not in a backup
     * an administrator can download. What lives here is the sort of thing that
     * changes when the business moves office: the address on an invoice, the
     * number on the contact page.
     *
     * Key/value rather than one row of columns, so adding a setting is a seed
     * rather than a migration.
     */
    public function up(): void
    {
        Schema::create('site_settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_settings');
    }
};
