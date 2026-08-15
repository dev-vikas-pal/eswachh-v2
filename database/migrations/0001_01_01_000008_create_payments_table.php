<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money.
     *
     * A payment is written the moment the customer is sent to the gateway, not
     * when they come back, so an abandoned attempt still leaves a record to
     * chase. It is completed in place when the callback arrives, which keeps
     * one attempt as one row.
     *
     * The unique key on gateway_payment_id is the idempotency guarantee: the
     * database refuses to record the same gateway payment twice, so a
     * resubmitted callback cannot renew a subscription twice or bank the money
     * twice, even if every check above it were removed.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->uuidKey();
            $table->branchOwned();

            $table->uuidRef('customer_id')->index();

            // Null until we know which period the money bought. A payment can
            // arrive for a subscription that no longer exists.
            $table->uuidRef('subscription_id')->index();

            // subscription | cloth_topup
            $table->string('purpose', 30)->default('subscription');

            // initiated | captured | failed | refunded
            $table->string('status', 20)->default('initiated')->index();

            $table->unsignedBigInteger('amount_paise');
            $table->string('currency', 3)->default('INR');

            $table->string('gateway', 30)->default('razorpay');
            $table->string('gateway_order_id', 100)->nullable();
            $table->string('gateway_payment_id', 100)->nullable();

            $table->string('method', 40)->nullable();
            // The bank or UPI reference, for matching against a statement.
            $table->string('reference', 191)->nullable();

            // When the money actually moved, as opposed to when we wrote the
            // row. Never auto-updated: v1 had a column that rewrote itself on
            // every touch and silently destroyed its own payment dates.
            $table->timestamp('paid_at')->nullable()->index();

            $table->string('invoice_number', 40)->nullable();

            // Set only when a human corrects the status after checking a bank
            // statement.
            $table->uuidRef('verified_by');
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();

            $table->auditColumns();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('verified_by')->references('id')->on('users')->nullOnDelete();

            // One gateway payment can only ever be recorded once.
            $table->unique('gateway_payment_id');
            $table->unique('invoice_number');
            $table->index('gateway_order_id');
            $table->index(['branch_id', 'status', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
