<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A franchise business unit.
 *
 * A branch is the tenant: everything a franchise owns hangs off it. The
 * geography it operates in - sectors, societies - is separate, because where a
 * customer lives and who services them are different questions. One branch
 * covers one or more sectors.
 *
 * Note this model is NOT branch scoped itself; the scope is applied to the
 * things a branch owns.
 */
class Branch extends BaseModel
{
    protected $fillable = [
        'name',
        'code',
        'status',
        'contact_name',
        'contact_phone',
        'contact_email',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => 'boolean',
        ]);
    }

    /** Users who belong to this branch: franchise owners, cleaners, customers. */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** The geographic sectors this branch services. */
    public function sectors(): HasMany
    {
        return $this->hasMany(Sector::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', true);
    }
}
