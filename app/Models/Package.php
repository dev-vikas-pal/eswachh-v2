<?php

namespace App\Models;

/**
 * Catalogue reference data. Shared across branches, so not branch scoped.
 * Money is held in paise as an integer.
 */
class Package extends BaseModel
{
    protected $table = 'packages';

    protected $fillable = ['name', 'description', 'price_paise', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }
}
