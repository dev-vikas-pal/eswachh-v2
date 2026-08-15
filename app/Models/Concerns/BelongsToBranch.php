<?php

namespace App\Models\Concerns;

use App\Support\Tenancy\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Applied to every model that belongs to a branch.
 *
 * The scope is global, so a query is filtered whether or not the person who
 * wrote it remembered to. That is the whole point: in the previous system
 * scoping was applied per query, so any screen that forgot it leaked across
 * franchises.
 */
trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $query) {
            if (! BranchContext::isRestricted()) {
                return;
            }

            $branchId = BranchContext::currentBranchId();

            if ($branchId === null) {
                // Restricted, but with no branch to restrict to. Return
                // nothing rather than everything.
                $query->whereRaw('1 = 0');

                return;
            }

            $query->where($query->getModel()->qualifyColumn('branch_id'), $branchId);
        });

        // New records land in the current branch unless one was set explicitly.
        static::creating(function ($model) {
            if (empty($model->branch_id)) {
                $model->branch_id = BranchContext::currentBranchId();
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Branch::class);
    }

    /**
     * Escape the branch scope for one query.
     *
     * Named so it is obvious in review. Prefer BranchContext::withoutScope()
     * for a block of work.
     */
    public function scopeAcrossBranches(Builder $query): Builder
    {
        return $query->withoutGlobalScope('branch');
    }
}
