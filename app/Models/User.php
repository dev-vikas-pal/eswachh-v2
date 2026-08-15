<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\HasAuditColumns;
use App\Support\Preferences\UserPreferences;
use App\Support\Tenancy\BranchContext;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
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

    /** Only a super admin sees every branch. */
    public function seesAllBranches(): bool
    {
        return $this->role?->seesAllBranches() ?? false;
    }

    public function hasRole(UserRole $role): bool
    {
        return $this->role === $role;
    }

    /**
     * Read straight from the role enum. There is no cache in front of this,
     * on purpose.
     */
    public function hasAbility(string $ability): bool
    {
        return $this->role?->can($ability) ?? false;
    }

    /**
     * @return array<int, string>
     */
    public function abilities(): array
    {
        return $this->role?->abilities() ?? [];
    }

    public function scopeInBranch($query, ?string $branchId)
    {
        return $branchId === null ? $query : $query->where('branch_id', $branchId);
    }

    public function scopeRole($query, UserRole $role)
    {
        return $query->where('role', $role->value);
    }

    /**
     * People the signed in user is allowed to see.
     *
     * User deliberately does NOT carry the branch global scope, unlike every
     * other branch-owned model. Authentication looks a user up by email before
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
        if (! BranchContext::isRestricted()) {
            return $query;
        }

        $branchId = BranchContext::currentBranchId();

        // Same rule as the global scope: restricted with no branch sees
        // nobody, never everybody.
        return $branchId === null
            ? $query->whereRaw('1 = 0')
            : $query->where('branch_id', $branchId);
    }
}
