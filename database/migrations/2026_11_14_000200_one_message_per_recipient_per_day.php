<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One message per plan, per purpose, per day - per person it goes to.
     *
     * The rule exists so a customer is never told the same thing twice in a
     * day, and a customer's messages all go to one number, so for them nothing
     * changes. What it also did was let only one person in the office hear
     * about a new plan: the alert number got it, and the franchise owner
     * covering the sector was refused as a repeat of the same message.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_one_per_day');
            $table->unique(['subscription_id', 'purpose', 'recipient', 'sent_on'], 'messages_one_per_day');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropUnique('messages_one_per_day');
            $table->unique(['subscription_id', 'purpose', 'sent_on'], 'messages_one_per_day');
        });
    }
};
