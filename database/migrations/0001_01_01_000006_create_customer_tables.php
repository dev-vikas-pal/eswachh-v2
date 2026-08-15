<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Customers, their vehicles, and the subscriptions against them.
     *
     * A customer is not a login. user_id is nullable, so a franchise owner can
     * register a walk-in customer who has never signed in - and the address
     * lives here, on the thing that has an address, rather than being bolted
     * onto the authentication record as v1 did.
     *
     * The cleaner is assigned to the VEHICLE, not to the subscription. A
     * cleaner services a car on a round; renewing the subscription does not
     * change who cleans it.
     */
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuidKey();
            $table->branchOwned();

            // The login, if they have one.
            $table->uuidRef('user_id')->unique();

            $table->string('name');
            $table->string('phone', 20)->nullable();
            $table->string('email')->nullable();

            // Where they are serviced.
            $table->uuidRef('state_id');
            $table->uuidRef('city_id');
            $table->uuidRef('area_id');
            $table->uuidRef('sector_id')->index();
            $table->uuidRef('society_id');
            $table->string('house_no', 100)->nullable();
            $table->text('address')->nullable();

            // When the cleaner should come.
            $table->time('preferred_time')->nullable();

            $table->boolean('status')->default(true);
            $table->auditColumns();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('sector_id')->references('id')->on('sectors')->nullOnDelete();
            $table->foreign('society_id')->references('id')->on('societies')->nullOnDelete();

            $table->index(['branch_id', 'status']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->uuidKey();
            $table->branchOwned();

            $table->char('customer_id', 36)->index();
            $table->uuidRef('vehicle_model_id');

            $table->string('registration', 20);

            // Who cleans this car. Survives renewal.
            $table->uuidRef('assigned_cleaner_id')->index();

            $table->boolean('status')->default(true);
            $table->auditColumns();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('vehicle_model_id')->references('id')->on('vehicle_models')->nullOnDelete();
            $table->foreign('assigned_cleaner_id')->references('id')->on('users')->nullOnDelete();

            // A registration is unique among live records.
            $table->unique(['registration', 'deleted_at']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->uuidKey();
            $table->branchOwned();

            $table->char('vehicle_id', 36)->index();
            $table->char('customer_id', 36)->index();

            // What was bought.
            $table->uuidRef('package_id');
            $table->uuidRef('service_type_id');
            $table->uuidRef('duration_id');

            /*
             * Renewal adds a row rather than editing one, so history is real
             * rather than reconstructed from a JSON snapshot. sequence is the
             * period number for this vehicle: 1, 2, 3...
             */
            $table->unsignedInteger('sequence')->default(1);
            $table->date('period_start');
            $table->date('period_end')->index();

            // pending | active | hold | ended. Expired is NOT a status: it is
            // an active period whose period_end has passed.
            $table->string('status', 20)->default('pending')->index();

            $table->unsignedBigInteger('amount_paise')->default(0);
            $table->unsignedBigInteger('paid_amount_paise')->default(0);

            // Cloth ironing add-on.
            $table->boolean('cloth_service')->default(false);
            $table->uuidRef('cloth_bundle_id');
            $table->integer('cloth_balance')->default(0);

            $table->timestamp('held_at')->nullable();
            $table->timestamp('ended_at')->nullable();

            $table->auditColumns();

            $table->foreign('branch_id')->references('id')->on('branches')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('customer_id')->references('id')->on('customers')->cascadeOnDelete();
            $table->foreign('package_id')->references('id')->on('packages')->nullOnDelete();
            $table->foreign('service_type_id')->references('id')->on('service_types')->nullOnDelete();
            $table->foreign('duration_id')->references('id')->on('durations')->nullOnDelete();
            $table->foreign('cloth_bundle_id')->references('id')->on('cloth_bundles')->nullOnDelete();

            // The queries the dashboard actually runs.
            $table->index(['branch_id', 'status', 'period_end']);
            $table->unique(['vehicle_id', 'sequence', 'deleted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('customers');
    }
};
