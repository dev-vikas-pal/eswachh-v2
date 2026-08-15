<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * A one time code sent to a phone.
 *
 * Not a BaseModel: it has no branch, is never audited and is meant to be swept
 * away rather than soft deleted. Keeping a spent login code around under
 * deleted_at would be a liability with no use.
 */
class LoginCode extends Model
{
    use HasUuids;

    /** How many wrong guesses a code survives. */
    public const MAX_ATTEMPTS = 5;

    protected $fillable = ['phone', 'purpose', 'code_hash', 'attempts', 'expires_at', 'consumed_at', 'requested_ip'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null
            && $this->attempts < self::MAX_ATTEMPTS
            && $this->expires_at->isFuture();
    }
}
