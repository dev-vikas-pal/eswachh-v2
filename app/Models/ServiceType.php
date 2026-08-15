<?php

namespace App\Models;

/**
 * Catalogue reference data. Shared across branches, so not branch scoped.
 * Money is held in paise as an integer.
 */
class ServiceType extends BaseModel
{
    protected $table = 'service_types';

    protected $fillable = ['name', 'price_paise', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }
}
