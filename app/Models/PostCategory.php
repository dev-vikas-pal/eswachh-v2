<?php

namespace App\Models;

use App\Models\Concerns\HasDerivedSlug;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PostCategory extends BaseModel
{
    use HasDerivedSlug;

    protected $attributes = ['sort_order' => 0, 'status' => true];

    protected $fillable = ['name', 'slug', 'description', 'sort_order', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }

    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }
}
