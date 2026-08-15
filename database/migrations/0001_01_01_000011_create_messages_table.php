<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Every message the system tries to send.
     *
     * Two problems in v1 this fixes.
     *
     * The first is that nothing recorded what had been sent, so a job that ran
     * twice messaged the customer twice, and nobody could answer "did we tell
     * them?". The unique key here makes one message per subscription, per
     * purpose, per day a rule the database enforces.
     *
     * The second is that v1 sent real messages from development and from the
     * test suite - to real customers' real phones. Here a message that is not
     * actually delivered is still written down, as "suppressed", so the row
     * shows exactly what would have gone out without anybody receiving it.
     */
    public function up(): void
    {
        Schema::create('messages', function (Blueprint $table) {
            $table->uuidKey();
            $table->char('branch_id', 36)->index();

            $table->uuidRef('customer_id')->index();
            $table->uuidRef('subscription_id')->index();

            // whatsapp | sms
            $table->string('channel', 20)->default('whatsapp');

            // renewal_due | renewal_overdue | put_on_hold | payment_receipt
            $table->string('purpose', 40)->index();

            // The provider's template name, so a change of wording is visible
            // in the history rather than only in the code.
            $table->string('template', 80)->nullable();

            $table->string('recipient', 30);
            $table->text('body')->nullable();

            // queued | sent | failed | suppressed
            $table->string('status', 20)->default('queued')->index();

            // Deliberately not sent, and why: usually that delivery is off
            // outside production.
            $table->string('suppressed_reason', 120)->nullable();

            $table->string('provider_id', 120)->nullable();
            $table->text('error')->nullable();

            $table->timestamp('sent_at')->nullable();

            // A calendar day, so the uniqueness rule below is per day rather
            // than per instant.
            $table->date('sent_on');

            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->nullOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();

            /*
             * One message per subscription, per purpose, per day. A job that
             * runs twice, or two servers running the same schedule, cannot
             * message the same customer twice about the same thing.
             */
            $table->unique(['subscription_id', 'purpose', 'sent_on'], 'messages_one_per_day');

            $table->index(['branch_id', 'sent_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('messages');
    }
};
