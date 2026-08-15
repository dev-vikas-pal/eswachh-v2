<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Geography: where a customer lives.
     *
     * Kept separate from branches on purpose. A sector is a place; a branch is
     * the business that services it. One branch covers one or more sectors,
     * recorded as sectors.branch_id, so "where does this customer live" and
     * "who services them" stay different questions.
     */
    public function up(): void
    {
        Schema::create('states', function (Blueprint $table) {
            $table->uuidKey();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->auditColumns();
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->uuidKey();
            $table->char('state_id', 36)->index();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->auditColumns();
            $table->foreign('state_id')->references('id')->on('states')->cascadeOnDelete();
        });

        Schema::create('areas', function (Blueprint $table) {
            $table->uuidKey();
            $table->char('city_id', 36)->index();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->auditColumns();
            $table->foreign('city_id')->references('id')->on('cities')->cascadeOnDelete();
        });

        Schema::create('sectors', function (Blueprint $table) {
            $table->uuidKey();
            $table->char('area_id', 36)->index();

            // Which branch services this sector. Null means unassigned.
            $table->branchOwned(nullable: true);

            $table->string('name');
            $table->boolean('status')->default(true);
            $table->auditColumns();

            $table->foreign('area_id')->references('id')->on('areas')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->nullOnDelete();
        });

        Schema::create('societies', function (Blueprint $table) {
            $table->uuidKey();
            $table->char('sector_id', 36)->index();
            $table->string('name');
            // Some societies carry a servicing surcharge.
            $table->unsignedBigInteger('surcharge_paise')->default(0);
            $table->boolean('status')->default(true);
            $table->auditColumns();
            $table->foreign('sector_id')->references('id')->on('sectors')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('societies');
        Schema::dropIfExists('sectors');
        Schema::dropIfExists('areas');
        Schema::dropIfExists('cities');
        Schema::dropIfExists('states');
    }
};
