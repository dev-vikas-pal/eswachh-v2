<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The work actually being done, and what customers say about it.
     *
     * v1 recorded a cleaner's day as two numbers typed in by hand - cars
     * serviced and total cars - which nobody could check and which told a
     * customer nothing about their own car. Here the round is recorded car by
     * car, so "cars serviced" is a count of real events, and a customer asking
     * "was my car done on Tuesday?" has an answer.
     *
     * Complaints get an append-only trail for the same reason: v1 kept one
     * resolution note that whoever touched it last overwrote.
     */
    public function up(): void
    {
        /*
         * One row per cleaner per day. This is the register: was this person
         * working at all. What they did while working is service_logs.
         */
        Schema::create('attendances', function (Blueprint $table) {
            $table->uuidKey();
            $table->branchOwned();

            $table->char('cleaner_id', 36)->index();
            $table->date('worked_on');

            // present | absent | leave | holiday
            $table->string('status', 20)->default('present');

            $table->time('started_at')->nullable();
            $table->time('finished_at')->nullable();

            // Who marked it, and when they marked it - which is not the same as
            // the day being marked. A week of attendance filled in on a Friday
            // is worth knowing about.
            $table->uuidRef('marked_by');
            $table->timestamp('marked_at')->nullable();

            $table->string('note', 255)->nullable();

            $table->auditColumns();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('cleaner_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('marked_by')->references('id')->on('users')->nullOnDelete();

            // One register entry per person per day. deleted_at is in the key
            // so a deleted entry does not block a corrected one.
            $table->unique(['cleaner_id', 'worked_on', 'deleted_at']);
            $table->index(['branch_id', 'worked_on']);
        });

        /*
         * One row per car per day: the evidence behind the numbers.
         */
        Schema::create('service_logs', function (Blueprint $table) {
            $table->uuidKey();
            $table->branchOwned();

            $table->char('vehicle_id', 36)->index();
            $table->uuidRef('subscription_id');
            $table->uuidRef('cleaner_id')->index();

            $table->date('serviced_on');
            // The clock time, kept apart from the date so a round can be
            // reviewed in order without parsing timestamps.
            $table->timestamp('serviced_at')->nullable();

            // cleaned | car_absent | access_denied | customer_declined | missed
            $table->string('outcome', 30)->default('cleaned');

            $table->string('note', 255)->nullable();

            $table->auditColumns();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('cleaner_id')->references('id')->on('users')->nullOnDelete();

            // A car is serviced once a day. A second attempt corrects the first
            // rather than adding to the count.
            $table->unique(['vehicle_id', 'serviced_on', 'deleted_at']);
            $table->index(['branch_id', 'serviced_on', 'outcome']);
            $table->index(['cleaner_id', 'serviced_on']);
        });

        Schema::create('complaints', function (Blueprint $table) {
            $table->uuidKey();
            $table->branchOwned();

            // Short and quotable down a phone: GN1/CMP/2026-27/00042.
            $table->string('reference', 40)->unique();

            $table->char('customer_id', 36)->index();
            $table->uuidRef('vehicle_id');
            $table->uuidRef('subscription_id');

            // not_cleaned | poor_quality | cleaner_conduct | timing | billing | other
            $table->string('category', 30)->default('other');
            // low | normal | high
            $table->string('priority', 10)->default('normal');

            $table->text('description');

            // open | assigned | resolved | closed
            $table->string('status', 20)->default('open')->index();

            $table->uuidRef('assigned_to')->index();
            $table->timestamp('assigned_at')->nullable();

            /*
             * When this should have been dealt with by. Stored rather than
             * computed, because the promise made when a complaint is raised
             * must not silently change if the policy changes later.
             */
            $table->timestamp('due_at')->nullable()->index();

            $table->timestamp('resolved_at')->nullable();
            $table->uuidRef('resolved_by');
            $table->text('resolution_note')->nullable();

            $table->timestamp('closed_at')->nullable();
            $table->uuidRef('closed_by');

            // How often the customer came back unsatisfied. A complaint
            // reopened three times is a different problem from three
            // complaints.
            $table->unsignedSmallInteger('reopened_count')->default(0);

            $table->auditColumns();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->nullOnDelete();
            $table->foreign('subscription_id')->references('id')->on('subscriptions')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('closed_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['branch_id', 'status', 'due_at']);
        });

        /*
         * Append only. No updated_at, no deleted_at, no soft deletes: a trail
         * that can be edited is not a trail. Corrections are new entries.
         */
        Schema::create('complaint_events', function (Blueprint $table) {
            $table->uuidKey();
            $table->char('complaint_id', 36)->index();
            $table->char('branch_id', 36)->index();

            // raised | assigned | note | resolved | reopened | closed
            $table->string('type', 20);

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            $table->text('note')->nullable();

            // Null means the system did it, not that nobody knows who did.
            $table->uuidRef('actor_id');

            $table->timestamp('created_at')->useCurrent();

            $table->foreign('complaint_id')->references('id')->on('complaints')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('actor_id')->references('id')->on('users')->nullOnDelete();

            $table->index(['complaint_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('complaint_events');
        Schema::dropIfExists('complaints');
        Schema::dropIfExists('service_logs');
        Schema::dropIfExists('attendances');
    }
};
