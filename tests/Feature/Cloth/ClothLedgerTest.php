<?php

namespace Tests\Feature\Cloth;

use App\Domain\Billing\RazorpaySignature;
use App\Domain\Billing\RecordPayment;
use App\Domain\Cloth\ClothLedger;
use App\Domain\Operations\DailyRound;
use App\Enums\ClothEntryType;
use App\Enums\PaymentPurpose;
use App\Enums\ServiceOutcome;
use App\Models\Branch;
use App\Models\ClothBundle;
use App\Models\ClothEntry;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

/**
 * The cloth balance can never disagree with its ledger.
 *
 * v1 had no ledger and let anything write the number, which is why all 22 of
 * its cloth top-up payments - twenty three thousand rupees - left the balance
 * at zero on every single subscription.
 */
class ClothLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test_secret_key';

    private Branch $branch;

    private ClothLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        config()->set('services.razorpay.secret', self::SECRET);
        config()->set('services.razorpay.enabled', false);

        $this->branch = Branch::factory()->create();
        $this->ledger = app(ClothLedger::class);
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    public function test_buying_a_bundle_credits_the_balance_and_turns_the_service_on(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => false, 'cloth_balance' => 0]);
            $bundle = $this->bundle(100, 80000);

            $this->ledger->purchase($subscription, $bundle);

            $subscription->refresh();
            $this->assertSame(100, $subscription->cloth_balance);
            // v1 took the money and left the flag off on every top-up it sold.
            $this->assertTrue($subscription->cloth_service);
        });
    }

    public function test_a_clean_uses_exactly_one_cloth(): void
    {
        BranchContext::withoutScope(function () {
            [$vehicle, $subscription] = $this->carWithCloths(10);

            app(DailyRound::class)->record($vehicle, $this->cleaner(), ServiceOutcome::Cleaned);

            $this->assertSame(9, $subscription->fresh()->cloth_balance);
            $this->assertSame(1, ClothEntry::query()->where('type', ClothEntryType::Issue)->count());
        });
    }

    public function test_a_car_that_was_not_cleaned_uses_no_cloth(): void
    {
        BranchContext::withoutScope(function () {
            [$vehicle, $subscription] = $this->carWithCloths(10);

            app(DailyRound::class)->record($vehicle, $this->cleaner(), ServiceOutcome::CarAbsent);

            $this->assertSame(10, $subscription->fresh()->cloth_balance);
        });
    }

    public function test_correcting_an_outcome_does_not_take_a_second_cloth(): void
    {
        BranchContext::withoutScope(function () {
            [$vehicle, $subscription] = $this->carWithCloths(10);
            $round = app(DailyRound::class);
            $cleaner = $this->cleaner();

            $round->record($vehicle, $cleaner, ServiceOutcome::Cleaned);
            // Same day, corrected and corrected back. The unique key on
            // service_log_id means one clean can only ever cost one cloth.
            $round->record($vehicle, $cleaner, ServiceOutcome::Missed);
            $round->record($vehicle, $cleaner, ServiceOutcome::Cleaned);

            $this->assertSame(9, $subscription->fresh()->cloth_balance);
            $this->assertSame(1, ClothEntry::query()->where('type', ClothEntryType::Issue)->count());
        });
    }

    public function test_a_subscription_without_the_service_never_touches_the_ledger(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => false, 'cloth_balance' => 0]);
            $vehicle = Vehicle::query()->findOrFail($subscription->vehicle_id);

            app(DailyRound::class)->record($vehicle, $this->cleaner(), ServiceOutcome::Cleaned);

            $this->assertSame(0, ClothEntry::query()->count());
        });
    }

    public function test_running_out_does_not_stop_the_car_being_cleaned(): void
    {
        BranchContext::withoutScope(function () {
            [$vehicle, $subscription] = $this->carWithCloths(0);

            $log = app(DailyRound::class)->record($vehicle, $this->cleaner(), ServiceOutcome::Cleaned);

            // Refusing to clean a car because a cloth count ran out would be
            // the wrong way round. This is a billing problem, not a service one.
            $this->assertSame(ServiceOutcome::Cleaned, $log->outcome);
            $this->assertSame(0, $subscription->fresh()->cloth_balance);
            $this->assertSame(0, ClothEntry::query()->count());
        });
    }

    public function test_the_balance_can_never_go_negative(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => true, 'cloth_balance' => 3]);

            $this->expectException(LogicException::class);

            // A negative balance is not a thing that can happen in the world,
            // so it must not be a thing that can happen in the database.
            $this->ledger->adjust($subscription, -10, 'Trying to overdraw.');
        });
    }

    public function test_an_adjustment_without_a_reason_is_refused(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => true, 'cloth_balance' => 5]);

            $this->expectException(LogicException::class);

            // A balance changed by hand with no explanation is the exact thing
            // the ledger exists to prevent.
            $this->ledger->adjust($subscription, 5, '   ');
        });
    }

    public function test_an_entry_cannot_be_edited_or_deleted(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => true, 'cloth_balance' => 0]);
            $entry = $this->ledger->purchase($subscription, $this->bundle(50, 50000));

            try {
                $entry->update(['quantity' => 500]);
                $this->fail('A ledger entry was allowed to be edited.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be changed', $e->getMessage());
            }

            try {
                $entry->delete();
                $this->fail('A ledger entry was allowed to be deleted.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('cannot be deleted', $e->getMessage());
            }
        });
    }

    public function test_the_balance_always_equals_the_ledger(): void
    {
        BranchContext::withoutScope(function () {
            [$vehicle, $subscription] = $this->carWithCloths(0);
            $round = app(DailyRound::class);
            $cleaner = $this->cleaner();

            $this->ledger->purchase($subscription, $this->bundle(100, 80000));
            $round->record($vehicle, $cleaner, ServiceOutcome::Cleaned);
            $this->ledger->adjust($subscription, -5, 'Five spoiled in the van.');
            $this->ledger->purchase($subscription, $this->bundle(20, 20000));

            $subscription->refresh();

            $this->assertSame(114, $subscription->cloth_balance);
            $this->assertSame(
                $this->ledger->balanceFromLedger($subscription),
                $subscription->cloth_balance,
                'The cached balance and the ledger must never disagree.'
            );

            // And the running total on each row reads like a statement.
            $this->assertSame(
                [100, 99, 94, 114],
                ClothEntry::query()->orderBy('created_at')->orderBy('id')->pluck('balance_after')->all()
            );
        });
    }

    public function test_a_paid_top_up_actually_credits_the_cloths(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => false, 'cloth_balance' => 0]);
            $this->bundle(100, 80000);

            $payment = Payment::factory()->forSubscription($subscription)->create([
                'purpose' => PaymentPurpose::ClothTopUp,
                'amount_paise' => 80000,
            ]);

            $outcome = app(RecordPayment::class)->complete([
                'razorpay_order_id' => $payment->gateway_order_id,
                'razorpay_payment_id' => 'pay_cloth',
                'razorpay_signature' => RazorpaySignature::sign(
                    (string) $payment->gateway_order_id, 'pay_cloth', self::SECRET
                ),
            ], []);

            $this->assertSame('captured', $outcome->result);

            // The whole point. In v1 the money arrived and this stayed at zero.
            $this->assertSame(100, $subscription->fresh()->cloth_balance);
            $this->assertSame(
                $payment->id,
                ClothEntry::query()->where('type', ClothEntryType::Purchase)->value('payment_id')
            );
        });
    }

    public function test_a_top_up_for_an_unknown_amount_still_banks_the_money(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => false, 'cloth_balance' => 0]);
            $this->bundle(100, 80000);

            $payment = Payment::factory()->forSubscription($subscription)->create([
                'purpose' => PaymentPurpose::ClothTopUp,
                // No bundle costs this.
                'amount_paise' => 12345,
            ]);

            $outcome = app(RecordPayment::class)->complete([
                'razorpay_order_id' => $payment->gateway_order_id,
                'razorpay_payment_id' => 'pay_odd',
                'razorpay_signature' => RazorpaySignature::sign(
                    (string) $payment->gateway_order_id, 'pay_odd', self::SECRET
                ),
            ], []);

            // Rather than guess a quantity, it is left for a person - but the
            // money is on the books either way, and the outcome says so.
            $this->assertSame('captured_incomplete', $outcome->result);
            $this->assertTrue($outcome->needsAttention());
            $this->assertSame(0, $subscription->fresh()->cloth_balance);
            $this->assertSame(
                \App\Enums\PaymentStatus::Captured,
                $payment->fresh()->status
            );
        });
    }

    public function test_the_check_command_notices_a_balance_written_behind_the_ledgers_back(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => true, 'cloth_balance' => 0]);
            $this->ledger->purchase($subscription, $this->bundle(50, 40000));

            $this->artisan('eswachh:check-cloth-balances')->assertSuccessful();

            // Something writes the column directly, which is the failure this
            // command exists to catch.
            $subscription->forceFill(['cloth_balance' => 999])->saveQuietly();

            $this->artisan('eswachh:check-cloth-balances')->assertFailed();

            $this->artisan('eswachh:check-cloth-balances --repair')->assertSuccessful();

            // Repaired from the ledger, and the ledger itself is untouched.
            $this->assertSame(50, $subscription->fresh()->cloth_balance);
            $this->assertSame(1, ClothEntry::query()->count());
        });
    }

    public function test_ending_a_subscription_writes_off_what_is_left(): void
    {
        BranchContext::withoutScope(function () {
            $subscription = $this->subscription(['cloth_service' => true, 'cloth_balance' => 0]);
            $this->ledger->purchase($subscription, $this->bundle(30, 25000));

            $this->ledger->expire($subscription);

            $subscription->refresh();
            $this->assertSame(0, $subscription->cloth_balance);
            // Written off visibly, not quietly zeroed.
            $this->assertSame(
                -30,
                ClothEntry::query()->where('type', ClothEntryType::Expiry)->value('quantity')
            );
        });
    }

    // ---------------------------------------------------------------- helpers

    private function subscription(array $attributes = []): Subscription
    {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $vehicle = Vehicle::factory()->forCustomer($customer)->create();

        return Subscription::factory()->forVehicle($vehicle)->create(array_merge([
            'duration_id' => Duration::factory()->create()->id,
        ], $attributes));
    }

    /**
     * @return array{0: Vehicle, 1: Subscription}
     */
    private function carWithCloths(int $balance): array
    {
        $subscription = $this->subscription([
            'cloth_service' => true,
            'cloth_balance' => $balance,
        ]);

        // The starting balance has to come through the ledger too, or the very
        // first check would report a mismatch.
        if ($balance > 0) {
            $subscription->forceFill(['cloth_balance' => 0])->saveQuietly();
            $this->ledger->purchase($subscription, $this->bundle($balance, $balance * 800));
        }

        return [Vehicle::query()->findOrFail($subscription->vehicle_id), $subscription->fresh()];
    }

    private function bundle(int $count, int $pricePaise): ClothBundle
    {
        return ClothBundle::create([
            'name' => "{$count} Cloths",
            'cloth_count' => $count,
            'price_paise' => $pricePaise,
            'status' => true,
        ]);
    }

    private function cleaner(): User
    {
        return User::factory()->cleaner($this->branch)->create();
    }
}
