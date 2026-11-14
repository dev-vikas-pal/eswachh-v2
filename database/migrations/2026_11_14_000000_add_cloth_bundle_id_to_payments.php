<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which bundle a cloth top-up bought.
     *
     * It used to be worked out afterwards by finding a bundle at the price that
     * was paid. Two bundles at one price credited whichever came first, and a
     * price changed between paying and the callback credited nothing at all.
     * The bundle is known when the payment is opened, so it is written then.
     *
     * Nullable: subscription payments have none, and top-ups taken before this
     * column existed still fall back to the price.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->uuidRef('cloth_bundle_id')->after('subscription_id');
            $table->foreign('cloth_bundle_id')->references('id')->on('cloth_bundles')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cloth_bundle_id']);
            $table->dropColumn('cloth_bundle_id');
        });
    }
};
