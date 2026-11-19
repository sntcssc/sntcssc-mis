<?php

namespace App\Models;

use App\Concerns\Auditable;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CaptchaLog extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PASSED = 'passed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EXPIRED = 'expired';

    public const STATUS_BLOCKED = 'blocked';

    public const DRIVER_GOOGLE = 'google';

    public const DRIVER_CUSTOM = 'custom';

    public const ACTION_LOGIN = 'login';

    public const ACTION_REGISTER = 'register';

    public const ACTION_FORGOT_PASSWORD = 'forgot_password';

    public const ACTION_CONTACT_US = 'contact_us';

    public const ACTION_API = 'api';

    public const ACTION_TEST = 'test';

    protected $fillable = [
        'session_id',
        'driver',
        'action',
        'ip_address',
        'user_agent',
        'status',
        'score',
        'error_code',
        'response_time_ms',
        'user_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'score' => 'float',
            'response_time_ms' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /* ----------------------------------------------------------------- *
     *  Query Scopes
     * ----------------------------------------------------------------- */

    public function scopePassed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PASSED);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_FAILED, self::STATUS_BLOCKED, self::STATUS_EXPIRED]);
    }

    public function scopeToday(Builder $query): Builder
    {
        return $query->whereDate('created_at', Carbon::today());
    }

    public function scopeDriver(Builder $query, ?string $driver): Builder
    {
        return $driver ? $query->where('driver', $driver) : $query;
    }

    public function scopeAction(Builder $query, ?string $action): Builder
    {
        return $action ? $query->where('action', $action) : $query;
    }

    /* ----------------------------------------------------------------- *
     *  Helpers & Accessors
     * ----------------------------------------------------------------- */

    public function getStatusBadgeColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PASSED => 'emerald',
            self::STATUS_FAILED => 'rose',
            self::STATUS_BLOCKED => 'violet',
            self::STATUS_EXPIRED => 'amber',
            default => 'secondary',
        };
    }

    public function getFormattedActionAttribute(): string
    {
        return match ($this->action) {
            self::ACTION_LOGIN => 'Sign In',
            self::ACTION_REGISTER => 'Registration',
            self::ACTION_FORGOT_PASSWORD => 'Forgot Password',
            self::ACTION_CONTACT_US => 'Contact Form',
            self::ACTION_API => 'API Verification',
            self::ACTION_TEST => 'Admin Test',
            default => ucwords(str_replace('_', ' ', $this->action)),
        };
    }
}
