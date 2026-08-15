<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Branches are the tenants. Created after users so the audit columns on
     * other tables have somewhere to point, but before anything branch owned.
     */
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuidKey();

            $table->string('name');
            $table->string('code', 20)->nullable();

            $table->string('contact_name')->nullable();
            $table->string('contact_phone', 20)->nullable();
            $table->string('contact_email')->nullable();

            $table->boolean('status')->default(true);

            $table->auditColumns();

            $table->unique(['code', 'deleted_at']);
        });

        // Now that branches exist, tie users to them.
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['branch_id']);
        });

        Schema::dropIfExists('branches');
    }
};
