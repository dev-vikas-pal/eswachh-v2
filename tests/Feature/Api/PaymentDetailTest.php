<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Settings\SiteSettings;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The payment detail screen, and the policy pages it sits alongside.
 */
class PaymentDetailTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    private Customer $customer;

    private Subscription $plan;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->franchiseOwner($this->branch)->create();
        $this->customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $vehicle = Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'registration' => 'UP42BJ9003',
        ]);

        $this->plan = Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'vehicle_id' => $vehicle->id,
            'amount_paise' => 74900,
            'paid_amount_paise' => 60000,
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    // ------------------------------------------------------------- the screen

    public function test_the_detail_carries_the_gateways_own_ids(): void
    {
        $payment = $this->payment([
            'gateway' => 'razorpay',
            'gateway_order_id' => 'order_ABC123',
            'gateway_payment_id' => 'pay_XYZ789',
        ]);

        // Support reads these down the phone to Razorpay; without them the
        // answer is "open the database".
        $this->actingAs($this->owner)
            ->getJson('/api/v1/payments/'.$payment->id.'/detail')
            ->assertOk()
            ->assertJsonPath('data.gateway.order_id', 'order_ABC123')
            ->assertJsonPath('data.gateway.payment_id', 'pay_XYZ789')
            ->assertJsonPath('data.channel', 'online');
    }

    public function test_a_payment_taken_by_hand_reads_as_offline(): void
    {
        $payment = $this->payment(['gateway' => 'manual', 'method' => 'cash']);

        // v1 stored an "order type" somebody set. Derived here, so it cannot
        // disagree with the record it describes.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/payments/'.$payment->id.'/detail')
            ->assertOk()
            ->assertJsonPath('data.channel', 'offline');
    }

    public function test_it_shows_what_is_still_owed_on_the_plan(): void
    {
        $payment = $this->payment();

        $this->actingAs($this->owner)
            ->getJson('/api/v1/payments/'.$payment->id.'/detail')
            ->assertOk()
            // 749 charged, 600 paid. The number somebody is looking for.
            ->assertJsonPath('data.subscription.outstanding', 149);
    }

    public function test_it_lists_the_other_payments_on_the_same_plan(): void
    {
        $first = $this->payment(['invoice_number' => 'ESW-0001']);
        $second = $this->payment(['invoice_number' => 'ESW-0002']);

        // Almost every argument about a payment is really about two of them.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/payments/'.$first->id.'/detail')
            ->assertOk()
            ->assertJsonCount(1, 'data.others_on_this_plan')
            ->assertJsonPath('data.others_on_this_plan.0.id', $second->id);
    }

    public function test_a_failed_payment_says_nothing_was_charged(): void
    {
        $payment = $this->payment(['status' => PaymentStatus::Failed, 'paid_at' => null]);

        $body = $this->actingAs($this->owner)
            ->getJson('/api/v1/payments/'.$payment->id.'/detail')
            ->assertOk()
            ->json('data');

        $this->assertFalse($body['has_receipt'], 'A failed payment has no receipt.');
        $this->assertStringContainsString('Nothing was charged', end($body['timeline'])['detail']);
    }

    public function test_a_customer_cannot_open_a_neighbours_payment(): void
    {
        $payment = $this->payment();

        $stranger = User::factory()->customer($this->branch)->create();
        Customer::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $stranger->id]);

        $this->actingAs($stranger)
            ->getJson('/api/v1/payments/'.$payment->id.'/detail')
            ->assertNotFound();
    }

    public function test_the_payment_list_can_be_narrowed_to_one_order(): void
    {
        $this->payment();
        $this->payment();

        // A second plan, whose payment must not appear.
        $other = Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'vehicle_id' => $this->plan->vehicle_id,
        ]);

        Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $other->id,
            'purpose' => PaymentPurpose::Subscription,
            'status' => PaymentStatus::Captured,
        ]);

        $this->actingAs($this->owner)
            ->getJson('/api/v1/payments?subscription_id='.$this->plan->id)
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    // ------------------------------------------------------------- the policies

    public function test_the_policy_pages_have_content_before_anybody_edits_them(): void
    {
        // A gateway asks to see these during onboarding, and an empty page
        // fails that check as surely as a missing one.
        foreach (['privacy', 'terms', 'refunds'] as $page) {
            $body = $this->getJson('/api/v1/public/policy/'.$page)
                ->assertOk()
                ->json('data.body');

            $this->assertGreaterThan(500, strlen($body), "The {$page} page is too short to be real.");
            $this->assertStringContainsString('<p>', $body);
        }
    }

    public function test_a_policy_is_cleaned_when_it_is_saved(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create())
            ->patchJson('/api/v1/site-settings', [
                'privacy_policy' => '<p>We keep your number.</p><script>alert(1)</script><p onclick="steal()">And nothing else.</p>',
            ])
            ->assertOk();

        $body = $this->getJson('/api/v1/public/policy/privacy')->assertOk()->json('data.body');

        // Cleaned on write, which is what makes rendering it as markup safe.
        // v1 stored whatever its editor produced and rendered it raw.
        $this->assertStringNotContainsString('<script', $body);
        $this->assertStringNotContainsString('alert(1)', $body);
        $this->assertStringNotContainsString('onclick', $body);
        $this->assertStringContainsString('We keep your number.', $body);
    }

    public function test_a_franchise_owner_cannot_rewrite_the_policies(): void
    {
        // One set of pages for the whole business, not per branch.
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/site-settings', ['privacy_policy' => '<p>Mine now.</p>'])
            ->assertForbidden();

        $this->assertStringNotContainsString('Mine now', (string) SiteSettings::get('privacy_policy'));
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function payment(array $overrides = []): Payment
    {
        return Payment::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'customer_id' => $this->customer->id,
            'subscription_id' => $this->plan->id,
            'purpose' => PaymentPurpose::Subscription,
            'status' => PaymentStatus::Captured,
            'amount_paise' => 60000,
            'paid_at' => now(),
        ], $overrides));
    }
}
