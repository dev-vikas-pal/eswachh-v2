<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Give money taken by hand the sector stamp it was always meant to carry.
     *
     * Recording a cash payment wrote no sector_id, and payments are scoped by
     * that stamp alone, so every one of them was invisible to the franchise
     * that took it. The code now stamps them; this fills in the ones already
     * written.
     *
     * Taken from where the customer lives today. That is the best that can be
     * known now, and it is what the stamp would have said for any payment
     * recorded since the customer last moved.
     *
     * Only rows with no stamp at all are touched, so a payment that was ever
     * stamped keeps the territory it was taken in.
     */
    public function up(): void
    {
        DB::statement('
            UPDATE payments
            JOIN customers ON customers.id = payments.customer_id
            SET payments.sector_id = customers.sector_id
            WHERE payments.sector_id IS NULL
              AND payments.gateway = \'manual\'
              AND customers.sector_id IS NOT NULL
        ');
    }

    /**
     * Not reversed. Taking the stamp away again would only hide the money.
     */
    public function down(): void
    {
        //
    }
};
