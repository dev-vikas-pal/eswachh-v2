<?php

namespace App\Models;

/**
 * Catalogue reference data. Shared across branches, so not branch scoped.
 * Money is held in paise as an integer.
 */
class ClothBundle extends BaseModel
{
    protected $table = 'cloth_bundles';

    protected $fillable = ['name', 'cloth_count', 'price_paise', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }
}
