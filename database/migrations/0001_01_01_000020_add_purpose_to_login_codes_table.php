<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a code was issued for.
 *
 * Signing in and proving a number on the signup form are different things, and
 * a code issued for one must not work for the other - otherwise a code texted
 * to a customer to sign in could be used to register their number against a
 * stranger's new account.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('login_codes', function (Blueprint $table) {
            $table->string('purpose', 20)->default('login')->after('phone');
        });

        Schema::table('login_codes', function (Blueprint $table) {
            // The lookup this table exists for, now that purpose is part of it.
            $table->index(['phone', 'purpose', 'consumed_at'], 'login_codes_lookup_index');
        });
    }

    public function down(): void
    {
        Schema::table('login_codes', function (Blueprint $table) {
            $table->dropIndex('login_codes_lookup_index');
            $table->dropColumn('purpose');
        });
    }
};
