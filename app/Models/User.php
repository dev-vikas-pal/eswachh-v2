<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasAuditColumns;
use App\Support\Preferences\UserPreferences;
use App\Support\Tenancy\SectorContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * One identity for everybody: administrators, franchise owners, cleaners and
 * customers all authenticate the same way and differ only by role.
 *
 * A user belongs to at most one branch. A super admin belongs to none and sees
 * all of them. This model is deliberately not branch scoped itself - the scope
 * needs to read the current user, and scoping the user would be circular.
 * Queries that list users apply the branch filter explicitly.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasAuditColumns;
    use HasUuids;
    use Notifiable;
    use SoftDeletes;

    protected $guarded = ['id'];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'role' => UserRole::class,
            'email_verified_at' => 'datetime',
            'phone_verified_at' => 'datetime',
            'deleted_at' => 'datetime',
            'status' => 'boolean',
            'password' => 'hashed',
            'preferences' => 'array',
        ];
    }

    /** Alerts this person has already looked at. */
    public function alertReads(): BelongsToMany
    {
        return $this->belongsToMany(Alert::class, 'alert_reads')->withPivot('read_at');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /**
     * How this person likes the interface arranged.
     *
     * Always complete: anything unset comes back as its default, so callers
     * never have to guess what an absent theme means.
     *
     * Deliberately not called preferences(): a method sharing a name with a
     * column is the sort of thing that resolves correctly today and breaks the
     * day somebody adds a relation by that name.
     *
     * @return array<string, string>
     */
    public function settings(): array
    {
        return UserPreferences::resolve($this->preferences);
    }

    /**
     * Change some settings and leave the rest alone.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, string>
     */
    public function updateSettings(array $changes): array
    {
        $merged = UserPreferences::resolve(array_merge($this->settings(), $changes));

        // saveQuietly: choosing a dark theme is not an edit to the person's
        // record, and should not stamp updated_by or bump updated_at as though
        // an administrator had changed their details.
        $this->forceFill(['preferences' => $merged])->saveQuietly();

        return $merged;
    }

    /**
     * The territory this person covers.
     *
     * Many to many, because a franchise user commonly runs several sectors and
     * a sector may be covered by more than one person - an owner and the
     * cleaners working it. This is the only thing that decides which customers
     * they can see; there is no franchise entity above it and nothing is copied
     * onto the customer.
     */
    public function sectors(): BelongsToMany
    {
        /*
         * Read outside the sector scope, deliberately.
         *
         * Sector is itself scoped, so without this the relation answers "which
         * of your sectors can *I* see" - which is empty for an administrator
         * looking at somebody else's assignments, and empty again for the scope
         * that is trying to establish what this person covers in the first
         * place. What somebody is assigned is a fact about them, not a view of
         * the world from where the reader happens to stand.
         */
        return $this->belongsToMany(Sector::class, 'user_sector')
            ->withoutGlobalScope('sector')
            ->withTimestamps();
    }

    /**
     * Only a super admin sees every sector.
     *
     * Read from the built-in role and nowhere else. A custom role deliberately
     * cannot affect this: it is the one thing that, granted by accident from a
     * permissions screen, would show one franchise another's customers.
     */
    public function seesAllSectors(): bool
    {
        return $this->role?->seesAllSectors() ?? false;
    }

    /** The role the business defined, if this account has been given one. */
    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CustomRole::class);
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Read straight from the role, with no cache in front of it, on purpose.
     *
     * A custom role replaces the built-in list rather than adding to it, which
     * is what makes it useful for a Supervisor: the whole point is to grant
     * *fewer* things than a franchise owner, and a role that could only ever
     * add would not be able to express that.
     */
    public function hasAbility(string $ability): bool
    {
        if ($custom = $this->activeCustomRole()) {
            return $custom->can($ability);
        }

        return $this->role?->can($ability) ?? false;
    }

    /**
     * @return array<int, string>
     */
    public function abilities(): array
    {
        if ($custom = $this->activeCustomRole()) {
            return $custom->grants();
        }

        return $this->role?->abilities() ?? [];
    }

    /**
     * The custom role, if it is one that should be honoured.
     *
     * A switched-off or deleted role is ignored and the account falls back to
     * its built-in role. Somebody whose role was disabled overnight should
     * arrive to a smaller set of screens, not a locked account and a phone
     * call - and the built-in role is always a safe thing to fall back to
     * because it is what they had before anybody customised anything.
     */
    private function activeCustomRole(): ?CustomRole
    {
        if (! $this->custom_role_id) {
            return null;
        }

        $role = $this->relationLoaded('customRole') ? $this->customRole : $this->customRole()->first();

        return $role && $role->status ? $role : null;
    }

    /**
     * People covering any of these sectors.
     *
     * @param  array<int, string>|string|null  $sectorIds
     */
    public function scopeInSectors($query, array|string|null $sectorIds)
    {
        /*
         * Null and empty mean opposite things, and confusing them is a leak.
         *
         * Null is "no territory filter", which is what an administrator gets.
         * An empty array is "covers nothing", and must return nobody - the same
         * fail-closed rule as the global scope. Treating the second as the
         * first would show somebody with no sectors every member of staff.
         */
        if ($sectorIds === null) {
            return $query;
        }

        if ($sectorIds === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn(
            'id',
            DB::table('user_sector')->select('user_id')->whereIn('sector_id', (array) $sectorIds),
        );
    }

    public function scopeRole($query, UserRole $role)
    {
        return $query->where('role', $role->value);
    }

    /**
     * People the signed in user is allowed to see.
     *
     * User deliberately does NOT carry the sector global scope, unlike every
     * other scoped model. Authentication looks a user up by email before
     * anybody is signed in, and a fail-closed scope in that moment matches
     * nobody - so adding the trait here would lock every account out of the
     * site, including the one needed to fix it.
     *
     * The scope is therefore applied by hand, here, and every place that
     * resolves a user from client input must go through this. Anything using
     * a bare User::find() with an id from a request is a bug.
     */
    public function scopeVisible($query)
    {
        if (! SectorContext::isRestricted()) {
            return $query;
        }

        $sectorIds = SectorContext::currentSectorIds();

        // Same rule as the global scope: restricted while covering nothing
        // sees nobody, never everybody.
        if ($sectorIds === null || $sectorIds === []) {
            return $query->whereRaw('1 = 0');
        }

        /*
         * Colleagues, plus yourself.
         *
         * Yourself explicitly, because somebody has to be able to open their
         * own account page, and a person newly created with no sectors yet
         * would otherwise be unable to see the record they are signed in as.
         */
        return $query->where(function ($q) use ($sectorIds) {
            $q->whereIn('id', DB::table('user_sector')->select('user_id')->whereIn('sector_id', $sectorIds))
                ->orWhere('id', auth()->id());
        });
    }
}
