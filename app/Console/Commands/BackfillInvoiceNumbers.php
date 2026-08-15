<?php

namespace App\Console\Commands;

use App\Enums\PaymentStatus;
use App\Models\Payment;
use App\Support\Numbering\SeriesNumber;
use App\Support\Tenancy\BranchContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Gives imported payments the invoice number they never got.
 *
 * A number is normally issued at the moment money is captured. Payments brought
 * across from v1 skipped that path entirely - they arrived already captured -
 * so every one of them has a blank invoice column, no receipt can be printed
 * for them, and the payments screen shows a dash where a number should be.
 *
 * Numbered in the order they were paid, so each branch's series runs
 * chronologically rather than in whatever order the import happened to write
 * rows. Only captured payments get one: a gap in an invoice series is
 * questioned at audit, and numbering an abandoned checkout would create one
 * that stands for nothing.
 */
class BackfillInvoiceNumbers extends Command
{
    protected $signature = 'eswachh:backfill-invoice-numbers
                            {--dry-run : Report what would be numbered without writing anything}';

    protected $description = 'Issue invoice numbers for captured payments that have none';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        return BranchContext::withoutScope(function () use ($dryRun) {
            $payments = Payment::query()
                ->where('status', PaymentStatus::Captured)
                ->whereNull('invoice_number')
                // Oldest first, so the series reads in the order money arrived.
                ->orderBy('paid_at')
                ->orderBy('created_at')
                ->get();

            if ($payments->isEmpty()) {
                $this->info('Every captured payment already has an invoice number.');

                return self::SUCCESS;
            }

            $this->line($payments->count().' captured payment(s) have no invoice number.');

            if ($dryRun) {
                $this->warn('Dry run: nothing was written.');

                return self::SUCCESS;
            }

            $issued = 0;

            foreach ($payments as $payment) {
                /*
                 * The series is per branch per financial year, and these are
                 * historical, so the year has to come from when the money was
                 * taken rather than from today. Issuing them all under this
                 * year would put a 2023 payment in the 2026 series.
                 */
                $number = $this->numberFor($payment);

                // forceFill: invoice_number is not fillable, deliberately - it
                // must never be settable from a request.
                $payment->forceFill(['invoice_number' => $number])->saveQuietly();

                $issued++;
            }

            $this->info("Issued {$issued} invoice number(s).");

            $this->table(
                ['Branch', 'Numbers issued'],
                DB::table('payments')
                    ->join('branches', 'branches.id', '=', 'payments.branch_id')
                    ->whereNotNull('invoice_number')
                    ->groupBy('branches.name')
                    ->selectRaw('branches.name, count(*) as total')
                    ->get()
                    ->map(fn ($row) => [$row->name, $row->total])
                    ->all(),
            );

            return self::SUCCESS;
        });
    }

    /**
     * The next number in this payment's own branch and financial year.
     *
     * SeriesNumber works from today's financial year, so for historical rows
     * the prefix is rebuilt here from the payment's own date and the highest
     * existing number in that series is read directly.
     */
    private function numberFor(Payment $payment): string
    {
        $year = SeriesNumber::financialYear($payment->paid_at ?? $payment->created_at);

        $code = strtoupper(
            DB::table('branches')->where('id', $payment->branch_id)->value('code') ?: 'ESW'
        );

        $prefix = "{$code}/INV/{$year}/";

        $highest = Payment::query()
            ->withoutGlobalScopes()
            ->withTrashed()
            ->where('invoice_number', 'like', $prefix.'%')
            ->orderByDesc('invoice_number')
            ->value('invoice_number');

        $sequence = $highest ? ((int) substr($highest, strlen($prefix))) + 1 : 1;

        return $prefix.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }
}
