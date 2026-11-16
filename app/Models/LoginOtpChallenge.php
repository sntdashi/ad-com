<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $challenge_id
 * @property int|null $user_id
 * @property string $purpose
 * @property string|null $target_phone
 * @property string $code_hash
 * @property string|null $challenge_token_hash
 * @property string|null $device_name
 * @property string $security_fingerprint
 * @property int $attempts
 * @property int $resend_count
 * @property Carbon $expires_at
 * @property Carbon|null $resend_available_at
 * @property string $status
 * @property Carbon|null $verified_at
 * @property Carbon|null $locked_until
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 */
class LoginOtpChallenge extends Model
{
    protected $fillable = [
        'challenge_id',
        'user_id',
        'purpose',
        'target_phone',
        'code_hash',
        'challenge_token_hash',
        'device_name',
        'security_fingerprint',
        'attempts',
        'resend_count',
        'expires_at',
        'resend_available_at',
        'status',
        'verified_at',
        'locked_until',
    ];

    protected function casts(): array
    {
        return [
            'attempts' => 'integer',
            'resend_count' => 'integer',
            'expires_at' => 'datetime',
            'resend_available_at' => 'datetime',
            'verified_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
