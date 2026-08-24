<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string $identifier
 * @property string $type
 * @property string $code_hash
 * @property int $attempts
 * @property int $max_attempts
 * @property Carbon $expires_at
 * @property Carbon|null $verified_at
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 */
class OtpCode extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const TYPE_REGISTRATION_SMS = 'registration_sms';

    public const TYPE_REGISTRATION_EMAIL = 'registration_email';

    public const TYPE_LOGIN_SMS = 'login_sms';

    public const TYPE_LOGIN_EMAIL = 'login_email';

    public const TYPE_PASSWORD_RESET_SMS = 'password_reset_sms';

    public const TYPE_PASSWORD_RESET_EMAIL = 'password_reset_email';

    public const TYPE_VERIFY_PHONE = 'verify_phone';

    public const TYPE_VERIFY_EMAIL = 'verify_email';

    protected $fillable = [
        'user_id',
        'identifier',
        'type',
        'code_hash',
        'attempts',
        'max_attempts',
        'expires_at',
        'verified_at',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'verified_at' => 'datetime',
            'metadata' => 'array',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isVerified(): bool
    {
        return ! is_null($this->verified_at);
    }

    public function hasExceededAttempts(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function verifyCode(string $plainCode): bool
    {
        if ($this->isExpired() || $this->isVerified() || $this->hasExceededAttempts()) {
            return false;
        }

        $this->increment('attempts');

        if (Hash::check($plainCode, $this->code_hash)) {
            $this->forceFill([
                'verified_at' => now(),
            ])->save();

            return true;
        }

        return false;
    }
}
