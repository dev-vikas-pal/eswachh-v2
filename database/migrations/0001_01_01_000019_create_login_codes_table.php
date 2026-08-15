<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One time codes for signing in by phone.
 *
 * v1's customers signed in with a code sent to their mobile, and most of them
 * have no idea what their password is. The table exists so the code can be
 * stored hashed and counted against - v1 kept the code in plain text, never
 * limited how many times one could be guessed, and accepted 112233 for any
 * number in any environment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('login_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('phone', 20);

            // Hashed, like a password. A leaked backup of this table must not
            // hand somebody a working code.
            $table->string('code_hash');

            // Guesses so far. A six digit code is only strong while the number
            // of attempts is small.
            $table->unsignedTinyInteger('attempts')->default(0);

            $table->timestamp('expires_at');
            $table->timestamp('consumed_at')->nullable();

            // Who asked, for the abuse trail.
            $table->string('requested_ip', 45)->nullable();

            $table->timestamps();

            // The lookup this table exists for: the live code for a number.
            $table->index(['phone', 'consumed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_codes');
    }
};
