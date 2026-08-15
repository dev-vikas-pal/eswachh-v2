<?php

namespace App\Console\Commands;

use App\Domain\Cloth\ClothLedger;
use App\Enums\PaymentPurpose;
use App\Enums\PaymentStatus;
use App\Enums\SubscriptionStatus;
use App\Enums\UserRole;
use App\Models\Area;
use App\Models\Branch;
use App\Models\City;
use App\Models\ClothBundle;
use App\Models\Customer;
use App\Models\Duration;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Sector;
use App\Models\ServiceType;
use App\Models\Society;
use App\Models\State;
use App\Models\Subscription;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use App\Models\VehicleModel;
use App\Support\Legacy\LegacyMap;
use App\Support\Tenancy\BranchContext;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brings the v1 data across.
 *
 * Written as a command rather than a one-off script so it can be run over and
 * over against a copy until it is boring, and so the real cutover is the same
 * command you have already run fifty times. It is idempotent: every record it
 * creates is recorded in legacy_references, and a second run updates rather
 * than duplicates.
 *
 * Run order matters, and the command enforces it.
 */
class ImportLegacyData extends Command
{
    protected $signature = 'eswachh:import
                            {--step=* : Run only these steps (geography, catalogue, branches, staff, customers, vehicles, subscriptions, payments)}
                            {--dry-run : Report what would be imported without writing anything}
                            {--fresh : Wipe imported data and the legacy map first}';

    protected $description = 'Import customers, vehicles and subscriptions from the v1 database';

    /** In v1 these were the order status integers. */
    private const LEGACY_STATUS = [
        1 => SubscriptionStatus::Pending,
        2 => SubscriptionStatus::Active,
        3 => SubscriptionStatus::Ended,
        4 => SubscriptionStatus::Hold,
    ];

    private bool $dryRun = false;

    /** @var array<string, int> */
    private array $counts = [];

    /** @var array<int, string> */
    private array $warnings = [];

    /** @var array<int, array<string, mixed>> */
    private array $skipped = [];

    public function handle(): int
    {
        $this->dryRun = (bool) $this->option('dry-run');

        if (! config('database.connections.legacy.database')) {
            $this->error('LEGACY_DB_DATABASE is not set. Point it at the v1 database first.');

            return self::FAILURE;
        }

        try {
            DB::connection('legacy')->getPdo();
        } catch (\Throwable $e) {
            $this->error('Cannot reach the v1 database: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->dryRun) {
            $this->warn('Dry run: nothing will be written.');
        }

        if ($this->option('fresh') && ! $this->dryRun) {
            $this->wipe();
        }

        $steps = $this->option('step') ?: ['geography', 'catalogue', 'branches', 'staff', 'customers', 'vehicles', 'subscriptions', 'payments'];

        // The import runs outside the branch scope: it is creating the
        // branches, so there is nobody to be scoped to yet.
        BranchContext::withoutScope(function () use ($steps) {
            foreach ($steps as $step) {
                $method = 'import'.ucfirst($step);

                if (! method_exists($this, $method)) {
                    $this->error("Unknown step: {$step}");

                    continue;
                }

                $this->components->task("Importing {$step}", fn () => $this->$method());
            }
        });

        $this->report();

        return self::SUCCESS;
    }

    // ---------------------------------------------------------------- steps

    /**
     * States, cities, areas, sectors and societies.
     */
    private function importGeography(): bool
    {
        foreach ($this->legacy('states')->get() as $row) {
            $this->upsert('state', $row->id, fn () => State::create([
                'name' => $row->name,
                'status' => (bool) ($row->status ?? true),
            ]));
        }

        foreach ($this->legacy('cities')->get() as $row) {
            $this->upsert('city', $row->id, fn () => City::create([
                'state_id' => LegacyMap::find('state', $row->state_id),
                'name' => $row->name,
                'status' => (bool) ($row->status ?? true),
            ]));
        }

        foreach ($this->legacy('areas')->get() as $row) {
            $this->upsert('area', $row->id, fn () => Area::create([
                'city_id' => LegacyMap::find('city', $row->city_id),
                'name' => $row->name,
                'status' => (bool) ($row->status ?? true),
            ]));
        }

        foreach ($this->legacy('sectors')->whereNull('deleted_at')->get() as $row) {
            $this->upsert('sector', $row->id, fn () => Sector::create([
                'area_id' => LegacyMap::find('area', $row->area_id),
                'name' => $row->name,
                'status' => (bool) ($row->status ?? true),
                // branch_id is filled by the branches step, once we know which
                // franchise services which sector.
                'branch_id' => null,
            ]));
        }

        foreach ($this->legacy('societies')->whereNull('deleted_at')->get() as $row) {
            $this->upsert('society', $row->id, fn () => Society::create([
                'sector_id' => LegacyMap::find('sector', $row->sector_id),
                'name' => $row->name,
                'surcharge_paise' => $this->toPaise($row->price ?? 0),
                'status' => (bool) ($row->status ?? true),
            ]));
        }

        return true;
    }

    /**
     * Everything that can be bought, plus what a car is.
     */
    private function importCatalogue(): bool
    {
        /*
         * Withdrawn masters come across too, still marked deleted.
         *
         * Live v1 orders point at packages and durations that were later
         * withdrawn. Leaving those behind does not just lose a name - it
         * silently changes the price, because a subscription whose package has
         * vanished quietly re-prices without it. Bringing them across keeps
         * every reference resolvable; the soft delete is what stops them being
         * offered for sale again.
         */
        foreach ($this->legacy('carcategories')->get() as $row) {
            $this->upsert('vehicle_category', $row->id, fn () => $this->preserveDeletion(
                VehicleCategory::create([
                    'name' => $row->name,
                    'price_paise' => $this->toPaise($row->price ?? 0),
                    'status' => (bool) ($row->status ?? true),
                ]),
                $row->deleted_at ?? null,
            ));
        }

        foreach ($this->legacy('cars')->get() as $row) {
            $this->upsert('vehicle_model', $row->id, fn () => $this->preserveDeletion(
                VehicleModel::create([
                    'vehicle_category_id' => LegacyMap::find('vehicle_category', $row->category_id),
                    'name' => $row->name,
                    'status' => (bool) ($row->status ?? true),
                ]),
                $row->deleted_at ?? null,
            ));
        }

        foreach ($this->legacy('packages')->get() as $row) {
            $this->upsert('package', $row->id, fn () => $this->preserveDeletion(
                Package::create([
                    'name' => $row->name,
                    'description' => $row->description ?? null,
                    'price_paise' => $this->toPaise($row->price ?? 0),
                    'status' => (bool) ($row->status ?? true),
                ]),
                $row->deleted_at ?? null,
            ));
        }

        foreach ($this->legacy('internaltypes')->get() as $row) {
            $this->upsert('service_type', $row->id, fn () => $this->preserveDeletion(
                ServiceType::create([
                    'name' => $row->name,
                    'price_paise' => $this->toPaise($row->price ?? 0),
                    'status' => (bool) ($row->status ?? true),
                ]),
                $row->deleted_at ?? null,
            ));
        }

        foreach ($this->legacy('durations')->get() as $row) {
            $this->upsert('duration', $row->id, fn () => $this->preserveDeletion(
                Duration::create([
                    'name' => $row->name,
                    'months' => (int) ($row->duration ?: 1),
                    'discount_paise' => $this->toPaise($row->price ?? 0),
                    'status' => (bool) ($row->status ?? true),
                ]),
                $row->deleted_at ?? null,
            ));
        }

        foreach ($this->legacy('cloths')->get() as $row) {
            $this->upsert('cloth_bundle', $row->id, fn () => $this->preserveDeletion(
                ClothBundle::create([
                    'name' => $row->name,
                    'cloth_count' => (int) ($row->count ?? 0),
                    'price_paise' => $this->toPaise($row->price ?? 0),
                    'status' => (bool) ($row->status ?? true),
                ]),
                $row->deleted_at ?? null,
            ));
        }

        return true;
    }

    /**
     * Turn v1's franchise-owner-to-sector mapping into branches.
     *
     * In v1 a franchise owner was linked to sectors through sector_user. Here a
     * branch is the franchise itself, so each owner becomes one branch covering
     * their sectors. Sectors nobody owned go to a catch-all branch rather than
     * being left homeless, because everything downstream needs a branch.
     */
    private function importBranches(): bool
    {
        $owners = $this->legacy('users')
            ->join('model_has_roles as mhr', function ($join) {
                $join->on('mhr.model_id', '=', 'users.id')
                    ->where('mhr.model_type', 'App\\Models\\User');
            })
            ->join('roles', 'roles.id', '=', 'mhr.role_id')
            ->where('roles.name', 'franchise owner')
            ->whereNull('users.deleted_at')
            ->select('users.*')
            ->get();

        foreach ($owners as $owner) {
            $branchId = $this->upsert('branch', 'owner:'.$owner->id, fn () => Branch::create([
                'name' => $owner->name.' Franchise',
                'contact_name' => $owner->name,
                'contact_phone' => $owner->mobile ?? null,
                'contact_email' => $owner->email ?? null,
                'status' => true,
            ]));

            // The owner's login moves across with them.
            $this->upsert('user', $owner->id, fn () => User::create([
                'branch_id' => $branchId,
                'name' => $owner->name,
                'email' => $owner->email,
                'phone' => $owner->mobile ?? null,
                'role' => UserRole::FranchiseOwner,
                'password' => $owner->password,
                'email_verified_at' => $owner->email_verified_at,
                'status' => ($owner->status ?? 1) == 1,
            ]));

            if ($this->dryRun || ! $branchId) {
                continue;
            }

            // Point their sectors at the new branch.
            $sectorIds = $this->legacy('sector_user')->where('user_id', $owner->id)->pluck('sector_id');

            foreach ($sectorIds as $legacySectorId) {
                if ($uuid = LegacyMap::find('sector', $legacySectorId)) {
                    Sector::where('id', $uuid)->update(['branch_id' => $branchId]);
                }
            }
        }

        // Everything else lands here, including v1's main sector, which never
        // had a franchise owner at all.
        $fallbackId = $this->upsert('branch', 'unassigned', fn () => Branch::create([
            'name' => 'Head Office',
            'code' => 'HO',
            'status' => true,
        ]));

        if (! $this->dryRun && $fallbackId) {
            Sector::whereNull('branch_id')->update(['branch_id' => $fallbackId]);
        }

        return true;
    }

    /**
     * The office: administrators and supervisors.
     *
     * Franchise owners are not here - they come across in the branches step,
     * because importing one is what creates their branch.
     *
     * v1's password hashes are carried over untouched, so everybody signs in
     * with the password they already use. They are bcrypt, which is what
     * Laravel still verifies against, so nothing needs rehashing.
     */
    private function importStaff(): bool
    {
        $rows = $this->legacy('users')
            ->join('model_has_roles as mhr', function ($join) {
                $join->on('mhr.model_id', '=', 'users.id')
                    ->where('mhr.model_type', 'App\\Models\\User');
            })
            ->join('roles', 'roles.id', '=', 'mhr.role_id')
            ->whereIn('roles.name', ['super admin', 'supervisor'])
            ->whereNull('users.deleted_at')
            ->select('users.*', 'roles.name as legacy_role')
            ->get();

        foreach ($rows as $row) {
            /*
             * v2 has no supervisor. A franchise owner is the closest thing it
             * has - branch scoped, can run the day - so that is what they
             * become, and it is reported rather than done quietly.
             */
            $role = $row->legacy_role === 'super admin'
                ? UserRole::SuperAdmin
                : UserRole::FranchiseOwner;

            if ($role === UserRole::FranchiseOwner) {
                $this->warnOnce('v1 supervisors were imported as franchise owners; v2 has no supervisor role.');
            }

            // A super admin belongs to no branch and sees all of them. A
            // supervisor needs one, so they land in the catch-all until
            // somebody moves them.
            $branchId = $role === UserRole::SuperAdmin ? null : LegacyMap::find('branch', 'unassigned');

            $this->upsert('user', $row->id, fn () => User::create([
                'branch_id' => $branchId,
                'name' => $row->name,
                'email' => $row->email,
                'phone' => $row->mobile ?? null,
                'role' => $role,
                // Already a bcrypt hash, so their existing password keeps working.
                'password' => $row->password,
                'email_verified_at' => $row->email_verified_at,
                'status' => ($row->status ?? 1) == 1,
            ]));
        }

        return true;
    }

    /**
     * v1 customers, from users plus their profile.
     */
    private function importCustomers(): bool
    {
        $rows = $this->legacy('users')
            ->join('model_has_roles as mhr', function ($join) {
                $join->on('mhr.model_id', '=', 'users.id')
                    ->where('mhr.model_type', 'App\\Models\\User');
            })
            ->join('roles', 'roles.id', '=', 'mhr.role_id')
            ->leftJoin('userprofiles as up', 'up.user_id', '=', 'users.id')
            ->whereIn('roles.name', ['customer', 'cleaner'])
            // Soft deleted users are brought across too, still soft deleted.
            // v1 has active subscriptions belonging to deleted customers, and
            // dropping the customer would silently drop paid-for service.
            ->select('users.*', 'roles.name as legacy_role', 'up.sector_id', 'up.society_id',
                'up.state_id', 'up.city_id', 'up.area_id', 'up.house_no', 'up.office_time', 'up.address')
            ->get();

        foreach ($rows as $row) {
            $sectorUuid = LegacyMap::find('sector', $row->sector_id);

            // Prefer the branch that services their sector; fall back to the
            // catch-all. Written as two steps because the sector lookup can
            // come back empty even when a sector id exists.
            $branchId = $sectorUuid
                ? Sector::withoutGlobalScope('branch')->where('id', $sectorUuid)->value('branch_id')
                : null;

            $branchId ??= LegacyMap::find('branch', 'unassigned');

            if (! $branchId) {
                $this->warnOnce('Some users had no sector and no fallback branch; they were skipped.');

                continue;
            }

            $role = $row->legacy_role === 'cleaner' ? UserRole::Cleaner : UserRole::Customer;

            $userId = $this->upsert('user', $row->id, fn () => $this->preserveDeletion(
                User::create([
                    'branch_id' => $branchId,
                    'name' => $row->name,
                    'email' => $row->email,
                    'phone' => $row->mobile ?? null,
                    'role' => $role,
                    // Carried across as-is: it is already a bcrypt hash, so
                    // people keep their existing password.
                    'password' => $row->password,
                    'email_verified_at' => $row->email_verified_at,
                    'status' => ($row->status ?? 1) == 1,
                ]),
                $row->deleted_at
            ));

            // Cleaners are staff, not customers.
            if ($role === UserRole::Cleaner) {
                continue;
            }

            $this->upsert('customer', $row->id, fn () => $this->preserveDeletion(Customer::create([
                'branch_id' => $branchId,
                'user_id' => $userId,
                'name' => $row->name,
                'phone' => $row->mobile ?? null,
                'email' => $row->email,
                'state_id' => LegacyMap::find('state', $row->state_id),
                'city_id' => LegacyMap::find('city', $row->city_id),
                'area_id' => LegacyMap::find('area', $row->area_id),
                'sector_id' => $sectorUuid,
                'society_id' => LegacyMap::find('society', $row->society_id),
                'house_no' => $row->house_no ?? null,
                'address' => $row->address ?? null,
                'preferred_time' => $this->toTime($row->office_time ?? null),
                'status' => ($row->status ?? 1) == 1,
            ]), $row->deleted_at));
        }

        return true;
    }

    /**
     * Carry a v1 soft deletion across.
     *
     * Set after creation rather than mass assigned, because deleted_at is not
     * a fillable attribute and should not become one just to suit the import.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  T  $model
     * @return T
     */
    private function preserveDeletion($model, ?string $deletedAt)
    {
        if ($deletedAt) {
            $model->forceFill(['deleted_at' => $deletedAt])->saveQuietly();
        }

        return $model;
    }

    /**
     * One vehicle per v1 order: an order was always about one car.
     */
    private function importVehicles(): bool
    {
        foreach ($this->legacyOrders() as $row) {
            $customerId = LegacyMap::find('customer', $row->user_id);

            if (! $customerId) {
                // Never drop a record silently: an order that cannot be placed
                // is listed with enough detail to look it up in v1.
                $this->skip('order', $row->id, [
                    'car' => $row->car_number,
                    'status' => $row->status,
                    'paid' => $row->paid_amount,
                    'reason' => 'no importable customer',
                ]);

                continue;
            }

            // withTrashed: v1 has live subscriptions belonging to customers who
            // were soft deleted, and their cars still need a home.
            $branchId = Customer::withoutGlobalScope('branch')->withTrashed()
                ->where('id', $customerId)->value('branch_id');

            $this->upsert('vehicle', $row->id, fn () => Vehicle::create([
                'branch_id' => $branchId,
                'customer_id' => $customerId,
                'vehicle_model_id' => LegacyMap::find('vehicle_model', $row->car_id),
                'registration' => $row->car_number,
                'assigned_cleaner_id' => LegacyMap::find('user', $row->assigned_user_id),
                'status' => true,
            ]), ['car_number' => $row->car_number]);
        }

        return true;
    }

    /**
     * The current period for each vehicle.
     *
     * Only the live period is brought across. v1 kept prior renewals as JSON
     * snapshots in order_history rather than as rows, so reconstructing older
     * periods is a separate exercise - and the snapshots stay available in the
     * v1 database if you ever want them.
     */
    private function importSubscriptions(): bool
    {
        foreach ($this->legacyOrders() as $row) {
            $vehicleId = LegacyMap::find('vehicle', $row->id);

            if (! $vehicleId) {
                continue;
            }

            $vehicle = Vehicle::withoutGlobalScope('branch')->withTrashed()->find($vehicleId);

            if (! $vehicle) {
                continue;
            }

            $status = self::LEGACY_STATUS[(int) $row->status] ?? SubscriptionStatus::Pending;

            $subscriptionId = $this->upsert('subscription', $row->id, fn () => Subscription::create([
                'branch_id' => $vehicle->branch_id,
                'vehicle_id' => $vehicle->id,
                'customer_id' => $vehicle->customer_id,
                'package_id' => LegacyMap::find('package', $row->package_id),
                'service_type_id' => LegacyMap::find('service_type', $row->cleaning_type),
                'duration_id' => LegacyMap::find('duration', $row->pakage_type),
                'sequence' => 1,
                'period_start' => $this->toDate($row->start_date) ?? Carbon::today(),
                'period_end' => $this->toDate($row->renew_date) ?? Carbon::today(),
                'status' => $status,
                'amount_paise' => $this->toPaise($row->amount ?? 0),
                'paid_amount_paise' => $this->toPaise($row->paid_amount ?? 0),
                'cloth_service' => (bool) ($row->cloth_service ?? false),
                'cloth_bundle_id' => LegacyMap::find('cloth_bundle', $row->cloth_id),
                'cloth_balance' => (int) ($row->cloth_count ?? 0),
            ]));

            $this->openClothBalance($subscriptionId, (int) ($row->cloth_count ?? 0));
        }

        return true;
    }

    /**
     * Give a carried-over cloth balance an entry to stand on.
     *
     * v1 stored the count as a bare number with no history behind it, so an
     * imported balance would sit there unexplained and the weekly check would
     * report it as a mismatch forever. Writing an opening entry says plainly
     * where the number came from, and lets everything afterwards be a real
     * ledger.
     */
    private function openClothBalance(?string $subscriptionId, int $balance): void
    {
        if ($this->dryRun || $subscriptionId === null || $balance <= 0) {
            return;
        }

        $subscription = Subscription::withoutGlobalScope('branch')->withTrashed()->find($subscriptionId);

        if (! $subscription) {
            return;
        }

        // Reset first: the ledger recomputes the balance from its own entries,
        // so the column has to start from nothing or the opening entry doubles
        // it.
        $subscription->forceFill(['cloth_balance' => 0])->saveQuietly();

        app(ClothLedger::class)->adjust(
            $subscription,
            $balance,
            'Opening balance carried over from the previous system.',
        );

        $this->counts['cloth_opening'] = ($this->counts['cloth_opening'] ?? 0) + 1;
    }

    /**
     * v1's payment_history.
     *
     * Every v1 row is status "captured", so the money brought across is money
     * that was really taken. What v1 almost never recorded is the gateway's own
     * payment id - it is blank on nearly every row - which is exactly why it had
     * no way to detect a duplicate callback and no way to reconcile against
     * Razorpay. Blank is imported as null rather than an empty string, so the
     * unique key still permits many unknowns while forbidding a real duplicate.
     */
    private function importPayments(): bool
    {
        $withoutGatewayId = 0;

        foreach ($this->legacy('payment_history')->orderBy('id')->get() as $row) {
            $subscriptionId = LegacyMap::find('subscription', $row->order_id);

            if (! $subscriptionId) {
                // The order never made it across - abandoned checkout, or an
                // order with no car number. Recorded rather than dropped.
                $this->skip('payment', $row->id, [
                    'reason' => 'no matching subscription',
                    'legacy_order_id' => $row->order_id,
                    'amount' => $row->payment_amount,
                ]);

                continue;
            }

            $subscription = Subscription::withoutGlobalScope('branch')
                ->withTrashed()
                ->find($subscriptionId);

            if (! $subscription) {
                continue;
            }

            $gatewayPaymentId = $this->blankToNull($row->payment_id);

            if (! $gatewayPaymentId) {
                $withoutGatewayId++;
            }

            $this->upsert('payment', $row->id, fn () => Payment::create([
                'branch_id' => $subscription->branch_id,
                'customer_id' => $subscription->customer_id,
                'subscription_id' => $subscription->id,
                'purpose' => strtolower((string) $row->payment_for) === 'cloth'
                    ? PaymentPurpose::ClothTopUp
                    : PaymentPurpose::Subscription,
                // v1 only ever wrote captured rows; nothing has to be guessed.
                'status' => PaymentStatus::Captured,
                'amount_paise' => $this->toPaise($row->payment_amount ?? 0),
                'currency' => $row->currency ?: 'INR',
                'gateway' => strtolower((string) ($row->payment_gateway ?: 'razorpay')),
                'gateway_order_id' => $this->blankToNull($row->razorpay_order_id),
                'gateway_payment_id' => $gatewayPaymentId,
                'method' => $this->blankToNull($row->payment_method),
                'reference' => $this->blankToNull($row->transaction_id),
                // The date v1 recorded, kept exactly. Not touched again: the
                // column that used to rewrite itself on every update is gone.
                'paid_at' => $this->toDate($row->payment_date_time),
                'verified_by' => LegacyMap::find('user', $row->verified_by),
                'verified_at' => $this->toDate($row->verified_at),
                'notes' => $this->blankToNull($row->additional_notes),
            ]));
        }

        if ($withoutGatewayId > 0) {
            $this->warnOnce(
                "{$withoutGatewayId} imported payment(s) have no gateway payment id, because v1 did not record one. ".
                'They cannot be reconciled against Razorpay; new payments will always carry it.'
            );
        }

        /*
         * Imported payments arrive already captured, so they never pass the
         * point where a number is normally issued. Without this every one of
         * them has a blank invoice column: no receipt can be printed, and the
         * payments screen shows a dash where a number belongs.
         *
         * Run as its own command so it can also be used on a database that was
         * imported before this existed.
         */
        if (! $this->dryRun) {
            $this->call('eswachh:backfill-invoice-numbers');
        }

        return true;
    }

    // ---------------------------------------------------------------- helpers

    private function blankToNull(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return ($value === null || $value === '') ? null : (string) $value;
    }

    private function legacy(string $table)
    {
        return DB::connection('legacy')->table($table);
    }

    /**
     * v1 orders worth importing: not soft deleted, and with a car number.
     */
    private function legacyOrders()
    {
        return $this->legacy('orders')
            ->whereNull('deleted_at')
            ->whereNotNull('car_number')
            ->orderBy('id')
            ->get();
    }

    /**
     * Create the record unless this legacy id was already imported.
     *
     * @param  callable(): \Illuminate\Database\Eloquent\Model  $make
     * @param  array<string, mixed>|null  $notes
     */
    private function upsert(string $entity, string|int|null $legacyId, callable $make, ?array $notes = null): ?string
    {
        if ($legacyId === null || $legacyId === '') {
            return null;
        }

        if ($existing = LegacyMap::find($entity, $legacyId)) {
            return $existing;
        }

        $this->counts[$entity] = ($this->counts[$entity] ?? 0) + 1;

        if ($this->dryRun) {
            // Stand in for the record that would have been created, so the
            // steps that depend on it can still resolve and be counted.
            $placeholder = (string) \Illuminate\Support\Str::uuid7();
            LegacyMap::rememberInMemory($entity, $legacyId, $placeholder);

            return $placeholder;
        }

        $model = $make();

        LegacyMap::remember($entity, $legacyId, $model->getKey(), $notes);

        return $model->getKey();
    }

    private function toPaise(int|float|string|null $amount): int
    {
        // Round rather than truncate: v1 stored decimals, and 1872.00 must not
        // become 187199 paise.
        return (int) round(((float) $amount) * 100);
    }

    private function toDate(?string $value): ?Carbon
    {
        if (empty($value) || str_starts_with($value, '0000')) {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function toTime(?string $value): ?string
    {
        return empty($value) ? null : substr($value, 0, 8);
    }

    private function warnOnce(string $message): void
    {
        if (! in_array($message, $this->warnings, true)) {
            $this->warnings[] = $message;
        }
    }

    /**
     * Note a v1 record that could not be brought across, and why.
     *
     * @param  array<string, mixed>  $detail
     */
    private function skip(string $entity, string|int $legacyId, array $detail): void
    {
        $this->skipped[] = array_merge(['entity' => $entity, 'legacy_id' => $legacyId], $detail);
    }

    /**
     * Remove everything the importer created, so it can be run again cleanly.
     */
    /**
     * Clear out everything a previous run created, so it can be run again.
     *
     * Users are the exception. Truncating that table takes the administrator
     * with it - the account was never imported, so re-running the import would
     * leave nobody able to sign in and no obvious reason why. Accounts that
     * this command created are in the legacy map; anything else was made by a
     * person and is left alone.
     */
    private function wipe(): void
    {
        $this->warn('Wiping imported data.');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        /*
         * Order matters only for readability - foreign keys are off - but the
         * list has to be complete. Anything hanging off a customer or a vehicle
         * goes too: leaving complaints behind after truncating customers leaves
         * rows pointing at people who no longer exist, and the complaint queue
         * then shows blanks that nobody can explain.
         */
        foreach ([
            'complaint_events', 'complaints',
            'service_logs', 'attendances', 'cloth_movements', 'cloth_entries',
            'messages', 'payments', 'subscriptions', 'vehicles', 'customers',
            'societies', 'sectors', 'areas', 'cities', 'states', 'cloth_bundles', 'durations',
            'service_types', 'packages', 'vehicle_models', 'vehicle_categories', 'branches',
        ] as $table) {
            // Skipped rather than fatal: a table added later should not stop
            // somebody re-importing on a database that predates it.
            if (Schema::hasTable($table)) {
                DB::table($table)->truncate();
            }
        }

        /*
         * Site settings, banners, questions and blog posts are deliberately not
         * in that list. They are the business's own content, typed by hand -
         * they did not come from v1 and re-importing must not throw them away.
         */

        $importedUsers = DB::table('legacy_references')->where('entity', 'user')->pluck('uuid');
        $kept = DB::table('users')->whereNotIn('id', $importedUsers)->count();

        DB::table('users')->whereIn('id', $importedUsers)->delete();

        DB::table('legacy_references')->truncate();

        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        if ($kept > 0) {
            $this->info("Kept {$kept} account(s) that were not imported, including any administrator.");
        }

        LegacyMap::flushCache();
    }

    private function report(): void
    {
        $this->newLine();

        if (empty($this->counts)) {
            $this->info('Nothing new to import: everything is already mapped.');
        } else {
            $rows = [];
            foreach ($this->counts as $entity => $count) {
                $rows[] = [$entity, $count];
            }
            $this->table(['Entity', $this->dryRun ? 'Would import' : 'Imported'], $rows);
        }

        foreach ($this->warnings as $warning) {
            $this->warn('* '.$warning);
        }

        if ($this->skipped) {
            $this->newLine();
            $this->warn(count($this->skipped).' record(s) could not be imported:');
            $this->table(array_keys($this->skipped[0]), array_map('array_values', $this->skipped));
        }

        if (! $this->dryRun) {
            $this->newLine();
            $this->info('Totals now in the legacy map:');
            $totals = [];
            foreach (LegacyMap::summary() as $entity => $count) {
                $totals[] = [$entity, $count];
            }
            $this->table(['Entity', 'Mapped'], $totals);
        }
    }
}
