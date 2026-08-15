<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The blog, and the people behind the business.
     *
     * Not branch scoped: there is one public website whatever the franchise
     * arrangement behind it, and an article about car care is not a Greater
     * Noida article.
     */
    public function up(): void
    {
        Schema::create('post_categories', function (Blueprint $table) {
            $table->uuidKey();
            $table->string('name', 120);
            // The address the public sees. Unique so two categories cannot
            // fight over the same URL.
            $table->string('slug', 140)->unique();
            $table->string('description', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);
            $table->auditColumns();
        });

        Schema::create('post_tags', function (Blueprint $table) {
            $table->uuidKey();
            $table->string('name', 60);
            $table->string('slug', 80)->unique();
            $table->boolean('status')->default(true);
            $table->auditColumns();
        });

        Schema::create('posts', function (Blueprint $table) {
            $table->uuidKey();

            $table->string('title');
            $table->string('slug', 220)->unique();

            // Shown in listings and used for the page description, so it is a
            // real field rather than the first paragraph cut short.
            $table->string('excerpt', 500)->nullable();
            $table->longText('body');

            $table->uuidRef('post_category_id')->index();
            $table->uuidRef('author_id');

            $table->string('cover_image')->nullable();

            /*
             * Publishing is a date, not a flag. A post with a future date is
             * scheduled; one with no date is a draft. A boolean would need a
             * second column for "when", and the two would drift apart.
             */
            $table->timestamp('published_at')->nullable()->index();

            // Whether readers may comment. Off on an old post stops it
            // becoming a spam target without hiding the article.
            $table->boolean('comments_open')->default(true);

            $table->unsignedInteger('view_count')->default(0);

            $table->auditColumns();

            $table->foreign('post_category_id')->references('id')->on('post_categories')->nullOnDelete();
            $table->foreign('author_id')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('post_tag', function (Blueprint $table) {
            $table->char('post_id', 36);
            $table->char('post_tag_id', 36);

            $table->primary(['post_id', 'post_tag_id']);
            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('post_tag_id')->references('id')->on('post_tags')->cascadeOnDelete();
        });

        /*
         * Comments arrive from the public, so nothing appears on the site until
         * somebody approves it. v1 had a comments module with no moderation
         * state at all, which is a spam problem waiting to happen.
         */
        Schema::create('comments', function (Blueprint $table) {
            $table->uuidKey();

            $table->char('post_id', 36)->index();

            // A signed in reader, if there was one. Otherwise just a name.
            $table->uuidRef('user_id');
            $table->string('author_name', 120);
            $table->string('author_email', 191)->nullable();

            $table->text('body');

            // pending | approved | spam
            $table->string('status', 20)->default('pending')->index();

            // Kept for working out where a wave of spam came from. Not shown
            // to anybody, and not used for anything else.
            $table->string('ip_address', 45)->nullable();

            $table->uuidRef('moderated_by');
            $table->timestamp('moderated_at')->nullable();

            $table->auditColumns();

            $table->foreign('post_id')->references('id')->on('posts')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('moderated_by')->references('id')->on('users')->nullOnDelete();

            $table->index(['post_id', 'status']);
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->uuidKey();

            $table->string('name', 120);
            // What they do, in words a customer understands.
            $table->string('title', 120)->nullable();
            $table->text('bio')->nullable();
            $table->string('photo_path')->nullable();

            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('status')->default(true);

            $table->auditColumns();

            $table->index(['status', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('post_tag');
        Schema::dropIfExists('posts');
        Schema::dropIfExists('post_tags');
        Schema::dropIfExists('post_categories');
    }
};
