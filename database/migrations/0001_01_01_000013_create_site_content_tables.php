<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The words on the public site, editable without a deployment.
     *
     * v1 had its banner headline, its offers and its questions written into
     * Blade templates, so changing "Flat Rs. 75 off" meant a developer and a
     * release. Anything the business says to customers belongs in the database,
     * behind a screen they can reach.
     *
     * Deliberately not branch scoped: there is one public website.
     */
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->uuidKey();

            $table->string('headline');
            $table->string('subheadline', 500)->nullable();
            $table->string('eyebrow', 120)->nullable();

            // What the button says and where it goes. Stored as a route name
            // rather than a URL so a renamed page cannot leave a dead button.
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_route', 60)->nullable();

            $table->string('secondary_label', 60)->nullable();
            $table->string('secondary_route', 60)->nullable();

            // A path under storage, never a remote URL: a banner pointing at
            // somebody else's server is a hotlink that breaks without notice.
            $table->string('image_path')->nullable();

            /*
             * A banner can be scheduled. A festival offer that comes down by
             * itself on the right morning is worth more than one somebody has
             * to remember to remove.
             */
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->auditColumns();

            $table->index(['status', 'sort_order']);
        });

        Schema::create('faqs', function (Blueprint $table) {
            $table->uuidKey();

            $table->string('question', 300);
            $table->text('answer');

            // Lets the list be grouped once there are more than a screenful.
            $table->string('category', 60)->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->auditColumns();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('banners');
    }
};
