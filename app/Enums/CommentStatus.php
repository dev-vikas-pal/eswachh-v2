<?php

namespace App\Enums;

/**
 * Whether a comment is on the site.
 *
 * Nothing from the public appears until somebody says so. Spam is kept rather
 * than deleted, because the pattern of what was rejected is what tells you a
 * post has become a target.
 */
enum CommentStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Spam = 'spam';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'On the site',
            self::Spam => 'Rejected',
        };
    }

    public function isPublic(): bool
    {
        return $this === self::Approved;
    }
}
