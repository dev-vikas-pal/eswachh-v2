<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuidKey();

            // Null for a super admin, who belongs to no single branch.
            $table->branchOwned(nullable: true);

            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone', 20)->nullable();

            // Stored as the UserRole enum's value.
            $table->string('role', 32)->index();

            $table->string('password')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->rememberToken();

            $table->boolean('status')->default(true);

            $table->auditColumns();

            // Email and phone are unique among live records only, so a soft
            // deleted user does not block the address being reused.
            $table->unique(['email', 'deleted_at']);
            $table->unique(['phone', 'deleted_at']);
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->uuidRef('user_id')->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
