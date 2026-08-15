<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How this person likes the interface arranged.
     *
     * On the user rather than in the browser, so the choice follows them to
     * whatever machine they sign in on. A cleaner who set the navigation down
     * the side on the office computer gets it down the side on their phone too.
     *
     * One JSON column rather than a column per setting: these are preferences,
     * nothing queries or reports on them, and adding the next one should not
     * need a migration. Anything the business acts on gets a real column.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('preferences')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferences');
        });
    }
};
