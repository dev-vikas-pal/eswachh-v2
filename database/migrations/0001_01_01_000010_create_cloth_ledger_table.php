<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every movement of a cloth balance.
     *
     * v1 kept cloth_balance as a plain integer that anything could write to,
     * with nothing behind it. Nobody could say why a customer had seven left,
     * and it showed: all 22 cloth top-up payments in v1 - twenty three thousand
     * rupees of them - were taken against subscriptions whose cloth_service was
     * still off and whose count was still zero. The money arrived and the
     * balance never moved.
     *
     * Here the balance on the subscription is a cached total and this table is
     * the truth. Every change writes a row, in the same transaction, so the two
     * can always be checked against each other - and there is a command that
     * does exactly that.
     */
    public function up(): void
    {
        Schema::create('cloth_entries', function (Blueprint $table) {
            $table->uuidKey();
            $table->char('branch_id', 36)->index();

            $table->char('subscription_id', 36)->index();
            $table->uuidRef('customer_id');

            // purchase | issue | adjustment | expiry
            $table->string('type', 20);

            // Signed: +100 for a bundle, -1 for a cloth used. Summing the
            // column for a subscription must always equal its balance.
            $table->integer('quantity');

            // The balance after this entry. Stored so the ledger reads like a
            // bank statement without re-summing everything above each row.
            $table->integer('balance_after');

            // What caused it. A purchase points at the money; an issue points
            // at the car that was cleaned.
            $table->uuidRef('payment_id');
            $table->uuidRef('service_log_id');
            $table->uuidRef('cloth_bundle_id');

            // Required for an adjustment: a balance changed by hand with no
            // reason given is the thing this table exists to prevent.
            $table->string('reason', 255)->nullable();

            $table->uuidRef('actor_id');

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('payment_id')->references('id')->on('payments')->nullOnDelete();
            $table->foreign('service_log_id')->references('id')->on('service_logs')->nullOnDelete();
            $table->foreign('cloth_bundle_id')->references('id')->on('cloth_bundles')->nullOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            // One cloth per service, enforced by the database. A retried
            // request cannot charge a customer two cloths for one clean.
            $table->unique('service_log_id');

            $table->index(['subscription_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloth_entries');
    }
};
