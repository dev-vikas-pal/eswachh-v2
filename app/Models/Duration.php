<?php

namespace App\Models;

/**
 * Catalogue reference data. Shared across branches, so not branch scoped.
 * Money is held in paise as an integer.
 */
class Duration extends BaseModel
{
    protected $table = 'durations';

    protected $fillable = ['name', 'months', 'discount_paise', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }
}
