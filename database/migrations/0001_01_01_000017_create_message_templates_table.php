<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What we say to customers, as data rather than as code.
     *
     * v1 kept these in a table and was right to. The wording of a renewal
     * reminder is the business talking to its customers, and changing it should
     * not need a developer and a deployment - especially as the person who
     * knows what it should say is not the person who can deploy.
     *
     * It is also what makes a bulk send possible: the office picks a template
     * by name, which requires the names to exist somewhere it can read them.
     */
    public function up(): void
    {
        Schema::create('message_templates', function (Blueprint $table) {
            $table->uuidKey();

            // How the code refers to it. Stable: renaming one silently stops
            // the job that sends it, so this is not the editable label.
            $table->string('key', 60)->unique();

            $table->string('name', 120);
            $table->string('description', 255)->nullable();

            // whatsapp | sms
            $table->string('channel', 20)->default('whatsapp');

            /*
             * The provider's own template id. WhatsApp will not deliver
             * unregistered wording, so the body below is what we record and
             * show; this is what MSG91 actually sends.
             */
            $table->string('provider_template', 80)->nullable();

            // Our copy, with {placeholders}.
            $table->text('body');

            /*
             * Which placeholders this template understands, so the editor can
             * list them and a typo can be caught before it reaches a customer
             * as a literal "{nmae}".
             */
            $table->json('placeholders')->nullable();

            // Offered in the bulk send picker, as opposed to only sent by a job.
            $table->boolean('bulk_sendable')->default(false);

            $table->boolean('status')->default(true);

            $table->auditColumns();

            $table->index(['status', 'bulk_sendable']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_templates');
    }
};
