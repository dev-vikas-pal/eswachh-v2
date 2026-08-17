<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Territory replaces the franchise.
 *
 * A sector *is* the territory. Staff are assigned sectors, and a customer is
 * visible to whoever holds the sector the customer sits in. There is no
 * franchise entity in the middle and no branch copied onto the customer, so
 * reassigning a sector is one pivot row and nothing else moves.
 *
 * What replaced what:
 *
 *   users.branch_id   ->  user_sector, one row per sector a person covers
 *   customers.branch_id -> customers.sector_id, which was always there
 *   payments.branch_id  -> payments.sector_id, stamped and never re-derived
 *
 * The old branch_id columns are left in place, still populated, and no longer
 * read. That is the way back if this turns out to be wrong; a later migration
 * drops them once it has not been.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Who covers which territory.
         *
         * A real pivot rather than a list on the user: it is a many to many, it
         * has to be indexed from both ends - "whose sectors are these" and "who
         * covers this sector" are both asked - and a JSON column can answer
         * neither with an index, nor stop a deleted sector leaving a dangling id
         * behind with nothing to notice.
         */
        Schema::create('user_sector', function (Blueprint $table) {
            $table->char('user_id', 36);
            $table->char('sector_id', 36);

            $table->timestamps();

            /*
             * The pair is the key. No surrogate id: a pivot row has no identity
             * of its own beyond the two things it joins, and giving it one means
             * sync() has to invent a value it has no way to generate.
             *
             * This also makes assigning the same sector twice impossible rather
             * than merely wasteful - a duplicate would be counted twice by
             * anything that totals the pivot.
             */
            $table->primary(['user_id', 'sector_id']);

            // The scope reads this on every request, from the user's side.
            $table->index('sector_id');

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('sector_id')->references('id')->on('sectors')->cascadeOnDelete();
        });

        /*
         * The one thing that is stamped rather than derived.
         *
         * A payment records something that happened. Who took the money does
         * not change because the territory was rearranged afterwards, so this
         * is written once, at the moment of capture, and never recomputed - the
         * same reason the cloth ledger is corrected with new entries instead of
         * edits.
         */
        Schema::table('payments', function (Blueprint $table) {
            $table->uuidRef('sector_id')->after('branch_id');
            $table->index('sector_id');
        });

        $this->backfill();
    }

    /**
     * Carry the existing arrangement across, so nobody's screen changes.
     */
    private function backfill(): void
    {
        /*
         * Staff get the sectors of the branch they were on, plus the sectors
         * they are demonstrably already working.
         *
         * The second half is not tidiness. Branch membership and real work had
         * already drifted apart: a hundred and ninety two cars were assigned to
         * cleaners whose branch did not hold the sector those cars sit in,
         * because the sector had been handed to another franchise and the
         * cleaners had not moved with it. Backfilling from branch alone would
         * have emptied six cleaners' rounds on the morning this shipped.
         *
         * Where somebody is actually working is the better evidence of what
         * they cover, so both sources count.
         *
         * Customers are not in the pivot: they see their own records by
         * ownership, which is a different question answered elsewhere.
         */
        $staff = DB::table('users')->whereNot('role', 'customer')->pluck('id');

        $now = now();
        $rows = [];

        foreach ($staff as $userId) {
            $fromBranch = DB::table('sectors')
                ->whereNotNull('branch_id')
                ->whereIn('branch_id', DB::table('users')->select('branch_id')->where('id', $userId))
                ->pluck('id');

            $fromTheirRound = DB::table('vehicles')
                ->join('customers', 'vehicles.customer_id', '=', 'customers.id')
                ->where('vehicles.assigned_cleaner_id', $userId)
                ->whereNotNull('customers.sector_id')
                ->distinct()
                ->pluck('customers.sector_id');

            foreach ($fromBranch->merge($fromTheirRound)->unique() as $sectorId) {
                $rows[] = [
                    'user_id' => $userId,
                    'sector_id' => $sectorId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 500) as $chunk) {
            DB::table('user_sector')->insert($chunk);
        }

        /*
         * Stamp historic payments with the sector their customer was in.
         *
         * Done from the customer rather than the payment's branch_id, because
         * the customer's sector is the fact and the branch was the copy of it.
         */
        DB::statement('
            UPDATE payments
            JOIN customers ON customers.id = payments.customer_id
            SET payments.sector_id = customers.sector_id
            WHERE payments.sector_id IS NULL
        ');
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['sector_id']);
            $table->dropColumn('sector_id');
        });

        Schema::dropIfExists('user_sector');
    }
};
