<?php

namespace App\Models;

use App\Support\Content\HtmlSanitizer;
use App\Support\Content\RichText;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * An article on the public site.
 *
 * Publishing is a date rather than a flag: a future date is a scheduled post,
 * no date is a draft, and a past date is live. One column, no chance of a
 * "published" tick disagreeing with a "published on" date.
 */
class Post extends BaseModel
{
    protected $attributes = ['comments_open' => true, 'view_count' => 0];

    protected $fillable = [
        'title', 'slug', 'excerpt', 'body',
        'post_category_id', 'author_id', 'cover_image',
        'published_at', 'comments_open',
    ];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'published_at' => 'datetime',
            'comments_open' => 'boolean',
        ]);
    }

    protected static function booted(): void
    {
        static::saving(function (self $post) {
            // The body is cleaned on the way in, like every other formatted
            // field. An article is the one place a WYSIWYG paste is most
            // likely, and the public blog is the worst place to render it raw.
            $post->body = HtmlSanitizer::clean($post->body) ?? '';

            if (empty($post->slug)) {
                $post->slug = self::uniqueSlug($post->title, $post->id);
            }

            if (empty($post->excerpt)) {
                // Derived rather than left blank, because the excerpt is also
                // the page description a search engine shows.
                $post->excerpt = RichText::summary($post->body, 200);
            }
        });
    }

    /**
     * A web address for this title that nothing else is using.
     */
    public static function uniqueSlug(string $title, ?string $ignoreId = null): string
    {
        $base = Str::slug($title) ?: 'post';
        $slug = $base;
        $suffix = 2;

        while (self::withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId, fn ($q) => $q->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(PostCategory::class, 'post_category_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(PostTag::class, 'post_tag');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /** Live on the site: dated, and that date has passed. */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', Carbon::now())
            ->orderByDesc('published_at');
    }

    /** Written but not yet dated. */
    public function scopeDraft(Builder $query): Builder
    {
        return $query->whereNull('published_at');
    }

    /** Dated, but the date has not arrived. */
    public function scopeScheduled(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '>', Carbon::now());
    }

    public function state(): string
    {
        if ($this->published_at === null) {
            return 'draft';
        }

        return $this->published_at->isFuture() ? 'scheduled' : 'published';
    }

    public function readingMinutes(): int
    {
        $words = str_word_count(RichText::plain($this->body));

        return max(1, (int) ceil($words / 200));
    }
}
