<?php

namespace App\Models\Concerns;

use Illuminate\Support\Str;

/**
 * Gives a record a web address derived from its name.
 *
 * So the generic master form never has to ask an administrator to invent a
 * slug, and two categories with similar names cannot collide on the unique
 * index. An explicitly set slug is left alone.
 */
trait HasDerivedSlug
{
    public static function bootHasDerivedSlug(): void
    {
        static::saving(function ($model) {
            if (! empty($model->slug)) {
                return;
            }

            $source = $model->{static::slugSource()} ?? '';
            $base = Str::slug((string) $source) ?: 'item';
            $slug = $base;
            $suffix = 2;

            while (static::withTrashed()
                ->where('slug', $slug)
                ->when($model->getKey(), fn ($q) => $q->whereKeyNot($model->getKey()))
                ->exists()
            ) {
                $slug = $base.'-'.$suffix++;
            }

            $model->slug = $slug;
        });
    }

    /** Which field the address is built from. */
    protected static function slugSource(): string
    {
        return 'name';
    }
}
