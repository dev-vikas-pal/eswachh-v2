<?php

namespace App\Models;

use App\Enums\CommentStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A reader's comment on an article.
 *
 * Arrives from the public, so it is stored as plain text and shown as plain
 * text - never as markup. There is no formatting worth the risk of letting
 * anonymous visitors put tags on your website.
 */
class Comment extends BaseModel
{
    protected $attributes = ['status' => 'pending'];

    protected $fillable = [
        'post_id', 'user_id', 'author_name', 'author_email',
        'body', 'status', 'ip_address', 'moderated_by', 'moderated_at',
    ];

    protected $hidden = ['ip_address', 'author_email'];

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            'status' => CommentStatus::class,
            'moderated_at' => 'datetime',
        ]);
    }

    protected static function booted(): void
    {
        static::saving(function (self $comment) {
            // Tags stripped outright rather than whitelisted: a comment box has
            // no legitimate use for markup at all.
            $comment->body = trim(strip_tags((string) $comment->body));
            $comment->author_name = trim(strip_tags((string) $comment->author_name));
        });
    }

    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    public function moderator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'moderated_by');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', CommentStatus::Approved);
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', CommentStatus::Pending);
    }
}
