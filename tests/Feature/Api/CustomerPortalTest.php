<?php

namespace Tests\Feature\Api;

use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\LoginCode;
use App\Models\Payment;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\Tenancy\BranchContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * A customer signing in to look at their own plan.
 *
 * The thing worth testing hardest is not that they can see theirs - it is that
 * they cannot see the neighbour's. A customer holds view.subscription and
 * view.payment, and the branch scope does not separate them from anybody else
 * of the same franchise, so the only thing standing between them and the whole
 * branch is the row filter these tests pin down.
 */
class CustomerPortalTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private User $account;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        BranchContext::reset();

        $this->branch = Branch::factory()->create();

        $this->account = User::factory()->customer($this->branch)->create([
            'phone' => '9876543210',
        ]);

        $this->customer = Customer::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->account->id,
            'name' => 'Asha Rao',
        ]);
    }

    protected function tearDown(): void
    {
        BranchContext::reset();
        parent::tearDown();
    }

    // -------------------------------------------------------------- the pages

    public function test_the_portal_shows_a_customers_own_plans(): void
    {
        $plan = $this->planFor($this->customer, 'KA01AB1234');

        $this->actingAs($this->account)
            ->getJson('/api/v1/portal/overview')
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'Asha Rao')
            ->assertJsonCount(1, 'data.plans')
            ->assertJsonPath('data.plans.0.id', $plan->id)
            ->assertJsonPath('data.plans.0.vehicle.registration', 'KA01AB1234');
    }

    public function test_the_portal_never_shows_another_customers_plan(): void
    {
        // Same branch, same franchise, different person. The branch scope does
        // nothing here, which is exactly why this test exists.
        $neighbour = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $this->planFor($neighbour, 'KA01ZZ9999');
        $mine = $this->planFor($this->customer, 'KA01AB1234');

        $body = $this->actingAs($this->account)
            ->getJson('/api/v1/portal/overview')
            ->assertOk()
            ->json('data.plans');

        $this->assertCount(1, $body);
        $this->assertSame($mine->id, $body[0]['id']);
    }

    public function test_a_login_with_no_customer_record_reads_as_empty_rather_than_broken(): void
    {
        $orphan = User::factory()->customer($this->branch)->create();

        $this->actingAs($orphan)
            ->getJson('/api/v1/portal/overview')
            ->assertOk()
            ->assertJsonCount(0, 'data.plans');
    }

    public function test_only_captured_payments_appear(): void
    {
        $plan = $this->planFor($this->customer, 'KA01AB1234');

        $this->paymentFor($plan, PaymentStatus::Captured);
        $this->paymentFor($plan, PaymentStatus::Failed);

        $this->actingAs($this->account)
            ->getJson('/api/v1/portal/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_customer_can_correct_their_own_details(): void
    {
        $this->actingAs($this->account)
            ->patchJson('/api/v1/portal/profile', [
                'name' => 'Asha R Rao',
                'preferred_time' => '08:30',
            ])
            ->assertOk()
            ->assertJsonPath('data.name', 'Asha R Rao');

        // Carried onto the login too, so the greeting does not keep the old one.
        $this->assertSame('Asha R Rao', $this->account->fresh()->name);
    }

    public function test_a_customer_cannot_move_themselves_to_another_sector(): void
    {
        $before = $this->customer->sector_id;

        $this->actingAs($this->account)
            ->patchJson('/api/v1/portal/profile', ['sector_id' => \App\Models\Sector::factory()->create()->id])
            ->assertOk();

        // Where somebody lives decides which franchise services them and what
        // the plan costs. It is not theirs to change.
        $this->assertSame($before, $this->customer->fresh()->sector_id);
    }

    public function test_staff_have_no_portal(): void
    {
        $owner = User::factory()->franchiseOwner($this->branch)->create();

        $this->actingAs($owner)->getJson('/api/v1/portal/overview')->assertForbidden();
    }

    // ------------------------------------------------- the office's own lists

    public function test_a_customer_reading_the_subscription_list_sees_only_their_own(): void
    {
        $neighbour = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $this->planFor($neighbour, 'KA01ZZ9999');
        $mine = $this->planFor($this->customer, 'KA01AB1234');

        $rows = $this->actingAs($this->account)
            ->getJson('/api/v1/subscriptions')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($mine->id, $rows[0]['id']);
    }

    public function test_a_customer_asking_for_a_neighbours_plan_by_id_gets_a_404(): void
    {
        $neighbour = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $theirs = $this->planFor($neighbour, 'KA01ZZ9999');

        // A 404 rather than a 403: refusing must not confirm the plan exists.
        $this->actingAs($this->account)
            ->getJson('/api/v1/subscriptions/'.$theirs->id)
            ->assertNotFound();
    }

    public function test_a_customer_reading_payments_sees_only_their_own(): void
    {
        $neighbour = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $this->paymentFor($this->planFor($neighbour, 'KA01ZZ9999'), PaymentStatus::Captured);
        $this->paymentFor($this->planFor($this->customer, 'KA01AB1234'), PaymentStatus::Captured);

        $this->actingAs($this->account)
            ->getJson('/api/v1/payments')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_customer_cannot_read_the_branchs_takings(): void
    {
        $this->actingAs($this->account)
            ->getJson('/api/v1/payments/summary')
            ->assertForbidden();
    }

    public function test_a_customer_cannot_start_a_payment_on_someone_elses_plan(): void
    {
        $neighbour = Customer::factory()->create(['branch_id' => $this->branch->id]);
        $theirs = $this->planFor($neighbour, 'KA01ZZ9999');

        $this->actingAs($this->account)
            ->postJson('/api/v1/subscriptions/'.$theirs->id.'/renew')
            ->assertNotFound();
    }

    // -------------------------------------------------------- signing in by phone

    public function test_asking_for_a_code_says_the_same_thing_for_an_unknown_number(): void
    {
        $known = $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210']);
        $unknown = $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9000000001']);

        $known->assertOk();
        $unknown->assertOk();

        // Identical replies: the form must not answer "is this person a
        // customer of yours". v1 replied "User does not exist".
        $this->assertSame($known->json('message'), $unknown->json('message'));

        // And no code is made for a number that has nobody behind it.
        $this->assertDatabaseCount('login_codes', 1);
    }

    public function test_a_code_signs_a_customer_in(): void
    {
        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210'])->assertOk();

        // Read the way the customer does - from the message - by putting a
        // known code in its place.
        $record = LoginCode::query()->firstOrFail();
        $record->forceFill(['code_hash' => Hash::make('123456')])->save();

        $this->fromSpa()
            ->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '123456'])
            ->assertOk()
            ->assertJsonPath('data.role.value', 'customer');

        $this->assertAuthenticatedAs($this->account);
    }

    public function test_asking_again_replaces_the_previous_code(): void
    {
        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210'])->assertOk();

        LoginCode::query()->latest('created_at')->firstOrFail()
            ->forceFill(['code_hash' => Hash::make('111111')])->save();

        // Send it again, as the Resend button does.
        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210'])->assertOk();

        LoginCode::query()->whereNull('consumed_at')->latest('created_at')->firstOrFail()
            ->forceFill(['code_hash' => Hash::make('222222')])->save();

        // The first is spent the moment a second is asked for, so a slow
        // message cannot be typed in after its replacement has arrived.
        $this->fromSpa()
            ->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '111111'])
            ->assertStatus(422);

        $this->fromSpa()
            ->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '222222'])
            ->assertOk();

        $this->assertAuthenticatedAs($this->account);
    }

    public function test_there_is_room_to_resend_a_few_times(): void
    {
        // Three was too tight: a slow message is exactly when somebody presses
        // the button twice, and they were then locked out for ten minutes.
        for ($i = 0; $i < 5; $i++) {
            $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210'])->assertOk();
        }

        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210'])->assertStatus(429);
    }

    public function test_a_code_only_works_once(): void
    {
        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210']);
        LoginCode::query()->firstOrFail()->forceFill(['code_hash' => Hash::make('123456')])->save();

        $this->fromSpa()->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '123456'])->assertOk();

        $this->post('/api/v1/logout');

        $this->fromSpa()
            ->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '123456'])
            ->assertStatus(422);
    }

    public function test_the_code_112233_is_not_a_master_key(): void
    {
        // v1 accepted this for any number, in any environment, forever.
        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210']);

        $this->fromSpa()
            ->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '112233'])
            ->assertStatus(422);

        $this->assertGuest();
    }

    public function test_a_code_is_burned_after_a_handful_of_wrong_guesses(): void
    {
        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210']);
        LoginCode::query()->firstOrFail()->forceFill(['code_hash' => Hash::make('123456')])->save();

        for ($i = 0; $i < LoginCode::MAX_ATTEMPTS; $i++) {
            $this->fromSpa()
                ->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '000000'])
                ->assertStatus(422);
        }

        // Six digits is only strong while the number of guesses is small.
        $this->fromSpa()
            ->postJson('/api/v1/login/code/verify', ['phone' => '9876543210', 'code' => '123456'])
            ->assertStatus(422);

        $this->assertGuest();
    }

    public function test_a_code_is_stored_hashed(): void
    {
        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9876543210']);

        $stored = LoginCode::query()->firstOrFail()->code_hash;

        $this->assertNotEmpty($stored);
        $this->assertDoesNotMatchRegularExpression('/^\d{6}$/', $stored, 'Codes must not be stored in plain text.');
    }

    public function test_staff_cannot_sign_in_with_a_code(): void
    {
        $owner = User::factory()->franchiseOwner($this->branch)->create(['phone' => '9111111111']);

        $this->fromSpa()->postJson('/api/v1/login/code', ['phone' => '9111111111'])->assertOk();

        // A code to a mobile is a weaker door, and it must not be one that
        // opens onto the whole branch's data.
        $this->assertDatabaseCount('login_codes', 0);
        $this->assertGuest();
        $this->assertNotNull($owner->id);
    }

    // --------------------------------------------------------------- helpers

    private function planFor(Customer $customer, string $registration): Subscription
    {
        $vehicle = Vehicle::factory()->create([
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'registration' => $registration,
        ]);

        return Subscription::factory()->create([
            'branch_id' => $customer->branch_id,
            'customer_id' => $customer->id,
            'vehicle_id' => $vehicle->id,
            'status' => SubscriptionStatus::Active,
            'period_start' => now()->subMonth(),
            'period_end' => now()->addMonth(),
        ]);
    }

    private function paymentFor(Subscription $plan, PaymentStatus $status): Payment
    {
        return Payment::factory()->create([
            'branch_id' => $plan->branch_id,
            'customer_id' => $plan->customer_id,
            'subscription_id' => $plan->id,
            'purpose' => PaymentPurpose::Subscription,
            'status' => $status,
            'paid_at' => $status === PaymentStatus::Captured ? now() : null,
        ]);
    }
}
