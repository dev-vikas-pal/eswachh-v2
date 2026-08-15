<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What can be bought, and what a vehicle is.
     *
     * These mirror v1's pricing inputs deliberately, because the price of a
     * subscription is built from all of them:
     *
     *   (category + package + service type + society surcharge) * months
     *     - duration discount + cloth
     *
     * Keeping the same inputs means the import can reproduce historic prices
     * rather than guessing at them.
     *
     * All money is stored in paise as an integer. Decimals invite rounding
     * arguments; integers do not.
     */
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table) {
            $table->uuidKey();
            $table->string('name');
            $table->unsignedBigInteger('price_paise')->default(0);
            $table->boolean('status')->default(true);
            $table->auditColumns();
        });

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->uuidKey();
            $table->char('vehicle_category_id', 36)->index();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->auditColumns();
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->cascadeOnDelete();
        });

        Schema::create('packages', function (Blueprint $table) {
            $table->uuidKey();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedBigInteger('price_paise')->default(0);
            $table->boolean('status')->default(true);
            $table->auditColumns();
        });

        // How often the interior is cleaned. v1 called these "internaltypes".
        Schema::create('service_types', function (Blueprint $table) {
            $table->uuidKey();
            $table->string('name');
            $table->unsignedBigInteger('price_paise')->default(0);
            $table->boolean('status')->default(true);
            $table->auditColumns();
        });

        // Billing period, with the discount for committing to a longer one.
        Schema::create('durations', function (Blueprint $table) {
            $table->uuidKey();
            $table->string('name');
            $table->unsignedSmallInteger('months');
            $table->unsignedBigInteger('discount_paise')->default(0);
            $table->boolean('status')->default(true);
            $table->auditColumns();
        });

        // Cloth bundles, sold with a subscription or as a top up.
        Schema::create('cloth_bundles', function (Blueprint $table) {
            $table->uuidKey();
            $table->string('name');
            $table->unsignedInteger('cloth_count');
            $table->unsignedBigInteger('price_paise')->default(0);
            $table->boolean('status')->default(true);
            $table->auditColumns();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloth_bundles');
        Schema::dropIfExists('durations');
        Schema::dropIfExists('service_types');
        Schema::dropIfExists('packages');
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_categories');
    }
};
