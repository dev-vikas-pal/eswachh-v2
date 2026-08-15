<?php

namespace App\Models;

use App\Models\Concerns\HasDerivedSlug;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class PostTag extends BaseModel
{
    use HasDerivedSlug;

    protected $attributes = ['status' => true];

    protected $fillable = ['name', 'slug', 'status'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), ['status' => 'boolean']);
    }

    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class, 'post_tag');
    }
}
