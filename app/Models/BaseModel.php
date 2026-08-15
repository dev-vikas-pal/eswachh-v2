<?php

namespace App\Models;

use App\Models\Concerns\HasAuditColumns;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The shape every table in this application shares.
 *
 * HasUuids on Laravel 12 generates version 7 UUIDs, which carry a timestamp
 * prefix and therefore sort by creation order. That matters more than it
 * sounds: in InnoDB the primary key is the physical row order, so random keys
 * scatter inserts across pages and fragment the table as it grows.
 */
abstract class BaseModel extends Model
{
    use HasAuditColumns;
    use HasFactory;
    use HasUuids;
    use SoftDeletes;

    /**
     * Nothing is mass assignable by accident; each model opts in with $fillable.
     */
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'deleted_at' => 'datetime',
        ];
    }
}
