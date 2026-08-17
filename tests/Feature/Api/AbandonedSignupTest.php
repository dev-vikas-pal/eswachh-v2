<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Tenancy\SectorContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * People who reached the payment page and stopped.
 *
 * Signup writes the customer, the car and the plan before the payment window
 * opens, so an abandoned checkout leaves a record rather than vanishing.
 * Nothing read those records until this screen, which is what makes writing
 * them worth anything.
 */
class AbandonedSignupTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        SectorContext::reset();

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->franchiseOwner($this->branch)->create();
    }

    protected function tearDown(): void
    {
        SectorContext::reset();
        parent::tearDown();
    }

    private function attempt(PaymentStatus $status, SubscriptionStatus $planStatus): Payment
    {
        return SectorContext::withoutScope(function () use ($status, $planStatus) {
            $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

            $plan = Subscription::factory()->create([
                'branch_id' => $this->branch->id,
                'customer_id' => $customer->id,
                'status' => $planStatus,
            ]);

            return Payment::factory()->forSubscription($plan)->create(['status' => $status]);
        });
    }

    public function test_an_abandoned_attempt_is_listed_with_a_number_to_call(): void
    {
        $payment = $this->attempt(PaymentStatus::Initiated, SubscriptionStatus::Pending);

        $row = $this->actingAs($this->owner)
            ->getJson('/api/v1/abandoned-signups')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->json('data.0');

        // The whole point of the screen is the phone number.
        $this->assertSame($payment->customer->phone, $row['phone']);
        $this->assertSame($payment->customer->name, $row['name']);
    }

    public function test_somebody_who_paid_in_the_end_is_not_chased(): void
    {
        // One abandoned attempt, then a successful one. A phone call about the
        // first would be a call to a paying customer asking why they had not
        // paid.
        $this->attempt(PaymentStatus::Failed, SubscriptionStatus::Active);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/abandoned-signups')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_a_captured_payment_is_not_listed(): void
    {
        $this->attempt(PaymentStatus::Captured, SubscriptionStatus::Pending);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/abandoned-signups')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);
    }

    public function test_only_attempts_in_your_own_sectors_are_listed(): void
    {
        $this->attempt(PaymentStatus::Initiated, SubscriptionStatus::Pending);

        $elsewhere = SectorContext::withoutScope(fn () => Sector::factory()->create());

        SectorContext::withoutScope(function () use ($elsewhere) {
            $customer = Customer::factory()->create(['sector_id' => $elsewhere->id]);
            $plan = Subscription::factory()->create(['customer_id' => $customer->id, 'status' => SubscriptionStatus::Pending]);
            Payment::factory()->forSubscription($plan)->create(['status' => PaymentStatus::Initiated]);
        });

        // The payment's stamped sector does the filtering, as everywhere else.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/abandoned-signups')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }

    public function test_the_window_can_be_narrowed(): void
    {
        $old = $this->attempt(PaymentStatus::Initiated, SubscriptionStatus::Pending);
        $old->forceFill(['created_at' => now()->subDays(20)])->save();

        // Somebody who gave up three weeks ago is a different call from
        // somebody who gave up this morning, and usually not worth making.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/abandoned-signups?days=7')
            ->assertOk()
            ->assertJsonPath('meta.total', 0);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/abandoned-signups?days=30')
            ->assertOk()
            ->assertJsonPath('meta.total', 1);
    }
}
