<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cloths going to the laundry and coming back.
     *
     * Physical logistics, deliberately separate from cloth_entries. That ledger
     * answers "how many has this customer paid for and used"; this answers
     * "where are the cloths right now". Conflating them would mean a cloth in a
     * laundry van looked like a cloth consumed.
     *
     * Per car per day, matching how v1 keyed it: a cleaner collects dirty
     * cloths from one car on the round and returns clean ones later.
     */
    public function up(): void
    {
        Schema::create('cloth_movements', function (Blueprint $table) {
            $table->uuidKey();
            $table->branchOwned();

            $table->char('vehicle_id', 36)->index();
            $table->uuidRef('subscription_id');
            $table->uuidRef('cleaner_id')->index();

            // pickup | delivery
            $table->string('direction', 20);

            $table->unsignedInteger('cloth_count');

            $table->date('moved_on');
            $table->string('note', 255)->nullable();

            $table->auditColumns();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('cleaner_id')->references('id')->on('users')->nullOnDelete();

            /*
             * One pickup and one delivery per car per day. A cleaner tapping
             * twice on a slow phone must not double the count, and correcting a
             * number should replace it rather than add to it.
             */
            $table->unique(['vehicle_id', 'direction', 'moved_on', 'deleted_at'], 'cloth_movements_one_per_day');
            $table->index(['branch_id', 'moved_on', 'direction']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloth_movements');
    }
};
