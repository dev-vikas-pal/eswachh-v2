<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Things somebody needs to look at.
     *
     * Deliberately not Laravel's polymorphic notifications table. These are not
     * one-per-user copies of the same message: an alert belongs to a branch and
     * whoever is working that branch today should see it, including somebody
     * who joined after it was raised.
     *
     * The dedupe key is what keeps this usable. Without it the nightly jobs
     * would raise "3 payments failed" every single night and the list would be
     * noise inside a week.
     */
    public function up(): void
    {
        Schema::create('alerts', function (Blueprint $table) {
            $table->uuidKey();

            // Null means it concerns the whole business, so only an
            // administrator sees it.
            $table->uuidRef('branch_id')->index();

            // payment_failed | complaint_overdue | cloth_mismatch | ...
            $table->string('type', 40)->index();

            // info | warning | critical
            $table->string('severity', 20)->default('info')->index();

            $table->string('title');
            $table->text('body')->nullable();

            // Where to go to deal with it: a route name and its parameters.
            $table->string('link_route', 60)->nullable();
            $table->json('link_params')->nullable();

            /*
             * One alert per thing per day. Raising the same warning nightly
             * turns the list into wallpaper, which is worse than no list.
             */
            $table->string('dedupe_key', 191);

            $table->timestamp('resolved_at')->nullable();
            $table->uuidRef('resolved_by');

            $table->timestamps();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('resolved_by')->references('id')->on('users')->nullOnDelete();

            $table->unique('dedupe_key');
            $table->index(['branch_id', 'resolved_at']);
        });

        /*
         * Who has already seen what.
         *
         * Separate from the alert because one alert is seen by several people,
         * and marking it read for yourself must not clear it for the person
         * who has to act on it.
         */
        Schema::create('alert_reads', function (Blueprint $table) {
            $table->char('alert_id', 36);
            $table->char('user_id', 36);
            $table->timestamp('read_at')->useCurrent();

            $table->primary(['alert_id', 'user_id']);
            $table->foreign('alert_id')->references('id')->on('alerts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alert_reads');
        Schema::dropIfExists('alerts');
    }
};
