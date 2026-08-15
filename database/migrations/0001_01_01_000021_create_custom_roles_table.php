<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Roles the business defines for itself.
 *
 * These sit *beside* the four built-in roles rather than replacing them, which
 * is what the comment on the UserRole enum asked for. The built-in role stays
 * on the user and keeps deciding the two things that must never be editable
 * from a screen: whether somebody sees across branches, and whether they are
 * staff or a customer. A custom role only narrows or widens what they may do
 * inside that.
 *
 * v1 stored roles and permissions in tables and then cached the lookup badly,
 * leaving new users with no permissions until somebody cleared the cache. The
 * abilities here are one JSON column read with the user, so there is no second
 * table to join and no cache to go stale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_roles', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('name', 60);
            $table->string('description', 255)->nullable();

            /*
             * Which built-in role this one behaves like. It decides branch
             * scoping and whether the holder is staff - neither of which is a
             * checkbox, because getting either wrong is a data breach rather
             * than an inconvenience.
             */
            $table->string('base_role', 30);

            // The whole permission set, read with the user in one go.
            $table->json('abilities');

            $table->boolean('status')->default(true);

            // The three the HasAuditColumns trait writes. deleted_by is easy to
            // forget and only fails at the moment somebody deletes a row.
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->uuid('deleted_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            // Two live roles called "Supervisor" would be indistinguishable on
            // every screen that shows a role name.
            $table->unique(['name', 'deleted_at']);
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * Nullable, and it stays nullable: everybody has a built-in role,
             * and a custom one is an optional refinement on top. Dropping the
             * custom role therefore always leaves a working account rather
             * than one with no permissions at all.
             */
            $table->foreignUuid('custom_role_id')->nullable()->after('role')
                ->constrained('custom_roles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('custom_role_id');
        });

        Schema::dropIfExists('custom_roles');
    }
};
