<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maps a v1 integer id to the v2 UUID it became.
     *
     * Two jobs. It makes the importer idempotent - running it twice updates
     * rather than duplicates - and it lets anyone trace a v2 record back to
     * the v1 row it came from, which matters the first time somebody queries
     * a figure using an order number they remember.
     *
     * Droppable once the migration is behind you and nobody quotes v1 numbers
     * any more.
     */
    public function up(): void
    {
        Schema::create('legacy_references', function (Blueprint $table) {
            $table->id();

            // 'customer', 'vehicle', 'subscription', 'payment', ...
            $table->string('entity', 40);

            // The v1 primary key. String, because not every v1 key was an int.
            $table->string('legacy_id', 64);

            $table->char('uuid', 36);

            // Anything worth keeping for diagnosis: the v1 car number, the
            // reason a row was skipped, and so on.
            $table->json('notes')->nullable();

            $table->timestamps();

            $table->unique(['entity', 'legacy_id']);
            $table->index('uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legacy_references');
    }
};
