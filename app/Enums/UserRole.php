<?php

namespace App\Enums;

/**
 * Who someone is, and what that lets them do.
 *
 * Roles are a small fixed set here, so they live in code rather than in the
 * database. That is a deliberate departure from v1, which stored roles and
 * permissions in tables and then cached the lookup - a cache that shadowed the
 * relation and left newly created users with no roles at all until somebody
 * cleared it by hand. Abilities declared in code cannot drift out of sync with
 * themselves, cannot be cached wrongly, and are visible in review.
 *
 * If per-user overrides are ever needed, add a permissions table beside this;
 * do not replace it.
 */
enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case FranchiseOwner = 'franchise_owner';
    case Cleaner = 'cleaner';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::FranchiseOwner => 'Franchise Owner',
            self::Cleaner => 'Cleaner',
            self::Customer => 'Customer',
        };
    }

    /**
     * Does this role see across every branch?
     *
     * Only the super admin. Everyone else is held to their own branch by the
     * global scope, with no exceptions and no "else" branch that means
     * unrestricted.
     */
    public function seesAllBranches(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * Abilities granted to this role.
     *
     * Named {verb}.{resource}. A super admin is not listed: it is granted
     * everything by a Gate::before hook, which keeps this table readable.
     *
     * @return array<int, string>
     */
    public function abilities(): array
    {
        return match ($this) {
            self::SuperAdmin => ['*'],

            self::FranchiseOwner => [
                'view.dashboard',
                'view.subscription', 'create.subscription', 'update.subscription',
                'view.vehicle', 'create.vehicle', 'update.vehicle',
                'view.customer', 'create.customer', 'update.customer',
                'view.staff', 'create.staff', 'update.staff',
                'assign.cleaner',
                // Recording a payment taken in cash, not taking one online.
                // The receipt too: the office prints it for the customer, so
                // giving only the customer that ability was an oversight.
                'view.payment', 'create.payment', 'view.invoice',
                'view.report',

                // The owner runs the complaint queue: hands it to someone,
                // signs it off. They do not raise one on a customer's behalf
                // without it being visible that they did.
                'view.complaint', 'create.complaint',
                'assign.complaint', 'resolve.complaint', 'close.complaint',

                'view.attendance', 'record.attendance',
                'view.round', 'record.service',
                'view.cloth', 'update.cloth',
            ],

            self::Cleaner => [
                'view.dashboard',
                // Their own round, and what happened at each car on it.
                'view.round', 'record.service',
                'record.attendance',
                // They can work a complaint that is theirs and say it is done.
                // They cannot close one: that is the customer's word to give.
                'view.complaint', 'resolve.complaint',
                'record.cloth',
            ],

            /*
             * A customer's abilities read the same as everyone else's, without
             * an "own" variant.
             *
             * An ability answers "may this person use this feature". Which rows
             * they then see is a separate question, and it has to be answered
             * by a query in the controller, because no permission string can
             * filter rows. Naming an ability view.own.complaint implied it did,
             * and left routes checking view.complaint locking customers out of
             * their own complaints entirely.
             */
            self::Customer => [
                'view.dashboard',
                'view.subscription', 'renew.subscription',
                'view.payment', 'view.invoice',
                'view.complaint', 'create.complaint',
                'buy.cloth.topup',
            ],
        };
    }

    public function can(string $ability): bool
    {
        $abilities = $this->abilities();

        return in_array('*', $abilities, true) || in_array($ability, $abilities, true);
    }

    /**
     * Roles a given role is allowed to create.
     *
     * A franchise owner runs their branch; they do not appoint administrators
     * or other franchises.
     *
     * @return array<int, self>
     */
    public function canCreate(): array
    {
        return match ($this) {
            self::SuperAdmin => self::cases(),
            self::FranchiseOwner => [self::Cleaner, self::Customer],
            default => [],
        };
    }

    /**
     * Is this somebody who works here?
     *
     * Staff and customers are managed on different screens, because they are
     * different things: a cleaner is a login and a branch, while a customer is
     * an address, a car and a plan. Creating a customer through the staff form
     * would make an account with no address and no vehicle - a record that
     * looks like a customer and cannot be serviced.
     */
    public function isStaff(): bool
    {
        return $this !== self::Customer;
    }

    /**
     * Roles that may be created from the staff screen.
     *
     * @return array<int, self>
     */
    public function canCreateStaff(): array
    {
        return array_values(array_filter(
            $this->canCreate(),
            fn (self $role) => $role->isStaff(),
        ));
    }
}
