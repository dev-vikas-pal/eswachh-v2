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
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * The three things v1 had that v2 did not: a receipt, a way to change your own
 * password, and policy pages a payment gateway will ask to see.
 */
class AccountAndInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        $this->branch = Branch::factory()->create();
        $this->owner = User::factory()->franchiseOwner($this->branch)->create();
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    // -------------------------------------------------------------- password

    public function test_somebody_can_change_their_own_password(): void
    {
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'password',
                'password' => 'a-much-longer-secret-42',
                'password_confirmation' => 'a-much-longer-secret-42',
            ])
            ->assertOk();

        $this->assertTrue(Hash::check('a-much-longer-secret-42', $this->owner->fresh()->password));
    }

    public function test_the_current_password_has_to_be_right(): void
    {
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'not-it',
                'password' => 'a-much-longer-secret-42',
                'password_confirmation' => 'a-much-longer-secret-42',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_password');

        // A signed in session is not a reason to let somebody who wandered up
        // to an unlocked screen set a new password.
        $this->assertTrue(Hash::check('password', $this->owner->fresh()->password));
    }

    public function test_a_new_password_has_to_be_confirmed(): void
    {
        $this->actingAs($this->owner)
            ->patchJson('/api/v1/me/password', [
                'current_password' => 'password',
                'password' => 'a-much-longer-secret-42',
                'password_confirmation' => 'something-else-entirely',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('password');
    }

    // --------------------------------------------------------------- receipt

    public function test_a_receipt_reads_the_payment_and_the_business(): void
    {
        SiteSettings::put(['legal_name' => 'Eswachh Services Pvt Ltd', 'gstin' => '09ABCDE1234F1Z5']);

        $payment = $this->capturedPayment();

        $this->actingAs($this->owner)
            ->getJson('/api/v1/payments/'.$payment->id.'/invoice')
            ->assertOk()
            ->assertJsonPath('data.number', $payment->invoice_number)
            ->assertJsonPath('data.from.name', 'Eswachh Services Pvt Ltd')
            ->assertJsonPath('data.from.gstin', '09ABCDE1234F1Z5')
            ->assertJsonPath('data.total', 600);
    }

    public function test_there_is_no_receipt_for_a_payment_that_never_completed(): void
    {
        $payment = $this->capturedPayment();
        $payment->forceFill(['status' => PaymentStatus::Initiated, 'paid_at' => null])->save();

        // A receipt for an abandoned checkout is a document saying money
        // changed hands when it did not.
        $this->actingAs($this->owner)
            ->getJson('/api/v1/payments/'.$payment->id.'/invoice')
            ->assertNotFound();
    }

    public function test_a_customer_cannot_read_someone_elses_receipt(): void
    {
        $payment = $this->capturedPayment();

        $stranger = User::factory()->customer($this->branch)->create();
        Customer::factory()->create(['branch_id' => $this->branch->id, 'user_id' => $stranger->id]);

        $this->actingAs($stranger)
            ->getJson('/api/v1/payments/'.$payment->id.'/invoice')
            ->assertNotFound();
    }

    public function test_a_customer_can_read_their_own_receipt(): void
    {
        $payment = $this->capturedPayment();

        $account = User::factory()->customer($this->branch)->create();
        Customer::withoutGlobalScopes()->whereKey($payment->customer_id)->first()
            ->forceFill(['user_id' => $account->id])->save();

        $this->actingAs($account)
            ->getJson('/api/v1/payments/'.$payment->id.'/invoice')
            ->assertOk()
            ->assertJsonPath('data.number', $payment->invoice_number);
    }

    // -------------------------------------------------------------- policies

    public function test_an_edited_policy_replaces_the_shipped_wording(): void
    {
        // There is a page from the start - see PolicyText - so this is about
        // an edit taking over, not about the page appearing.
        $before = $this->getJson('/api/v1/public/policy/privacy')
            ->assertOk()
            ->assertJsonPath('data.title', 'Privacy policy')
            ->json('data');

        $this->assertNull($before['updated_at'], 'Nobody has agreed to a date on the default wording.');

        SiteSettings::put(['privacy_policy' => '<p>We keep your number and nothing else.</p>']);

        $after = $this->getJson('/api/v1/public/policy/privacy')->assertOk()->json('data');

        $this->assertStringContainsString('nothing else', $after['body']);
        $this->assertNotNull($after['updated_at'], 'An edited page carries the date it was changed.');
    }

    public function test_a_policy_emptied_on_purpose_is_a_404_rather_than_a_blank_page(): void
    {
        SiteSettings::put(['terms' => '']);

        $this->getJson('/api/v1/public/policy/terms')->assertNotFound();
    }

    public function test_only_the_three_policy_pages_exist(): void
    {
        $this->getJson('/api/v1/public/policy/anything-else')->assertNotFound();
    }

    // --------------------------------------------------------------- helpers

    private function capturedPayment(): Payment
    {
        $customer = Customer::factory()->create(['branch_id' => $this->branch->id]);

        $vehicle = Vehicle::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
        ]);

        $plan = Subscription::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
        ]);

        return Payment::factory()->create([
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'subscription_id' => $plan->id,
            'purpose' => PaymentPurpose::Subscription,
            'status' => PaymentStatus::Captured,
            'amount_paise' => 60000,
            'invoice_number' => 'ESW-0001',
            'paid_at' => now(),
        ]);
    }
}
