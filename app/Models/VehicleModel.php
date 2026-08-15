<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A make and model of car. Its category carries the size-based price.
 */
class VehicleModel extends BaseModel
{
    protected $table = 'vehicle_models';

    protected $fillable = ['vehicle_category_id', 'name', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id');
    }
}
