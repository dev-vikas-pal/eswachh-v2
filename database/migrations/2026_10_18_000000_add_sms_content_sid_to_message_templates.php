<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The Twilio Content SID for this wording as an SMS template.
     *
     * Kept off provider_template on purpose. That column is the MSG91 WhatsApp
     * name, and writing an HX id into it would make WhatsApp send the wrong
     * template.
     */
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('sms_content_sid', 34)->nullable()->after('provider_template');
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn('sms_content_sid');
        });
    }
};
