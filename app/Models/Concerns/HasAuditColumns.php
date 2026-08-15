<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Records who created, changed and removed a row.
 *
 * When there is no authenticated user - a scheduled job, a queue worker, the
 * console - these are left null, and null means "the system did it". That is a
 * deliberate choice rather than an accident: the previous system wrote
 * Auth::id() blindly, so every row touched by the weekly hold job claimed to
 * have been changed by nobody, with no way to tell that apart from a bug.
 */
trait HasAuditColumns
{
    public static function bootHasAuditColumns(): void
    {
        static::creating(function ($model) {
            $actor = auth()->id();
            $model->created_by ??= $actor;
            $model->updated_by ??= $actor;
        });

        static::updating(function ($model) {
            $model->updated_by = auth()->id();
        });

        static::deleting(function ($model) {
            // Only meaningful for a soft delete; a force delete removes the row.
            if (method_exists($model, 'isForceDeleting') && $model->isForceDeleting()) {
                return;
            }

            $model->deleted_by = auth()->id();
            $model->saveQuietly();
        });
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'updated_by');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'deleted_by');
    }
}
