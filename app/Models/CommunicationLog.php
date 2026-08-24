<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class CommunicationLog extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const STATUS_SENT = 'sent';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_PENDING = 'pending';

    public const TYPE_OTP = 'otp';

    public const TYPE_NOTIFICATION = 'notification';

    public const TYPE_NOTICE = 'notice';

    public const TYPE_BROADCAST = 'broadcast';

    public const TYPE_CUSTOM_INDIVIDUAL = 'custom_individual';

    public const TYPE_CUSTOM_BULK = 'custom_bulk';

    protected $fillable = [
        'channel',
        'type',
        'recipient',
        'recipient_name',
        'user_id',
        'template_code',
        'subject',
        'content',
        'variables',
        'metadata',
        'status',
        'error_message',
        'sent_by',
        'campaign_id',
        'sent_at',
        'delivered_at',
        'failed_at',
        'resend_count',
        'last_resent_at',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'metadata' => 'array',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'failed_at' => 'datetime',
            'last_resent_at' => 'datetime',
            'resend_count' => 'integer',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(CommunicationCampaign::class, 'campaign_id');
    }

    /* ----------------------------------------------------------------- *
     *  Scopes
     * ----------------------------------------------------------------- */

    public function scopeSms(Builder $query): Builder
    {
        return $query->where('channel', self::CHANNEL_SMS);
    }

    public function scopeEmail(Builder $query): Builder
    {
        return $query->where('channel', self::CHANNEL_EMAIL);
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_FAILED);
    }

    public function scopeChannel(Builder $query, ?string $channel): Builder
    {
        return $channel && $channel !== 'all' ? $query->where('channel', $channel) : $query;
    }

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $status && $status !== 'all' ? $query->where('status', $status) : $query;
    }

    public function scopeInDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
    }

    /* ----------------------------------------------------------------- *
     *  Helpers
     * ----------------------------------------------------------------- */

    public function isEmail(): bool
    {
        return $this->channel === self::CHANNEL_EMAIL;
    }

    public function isSms(): bool
    {
        return $this->channel === self::CHANNEL_SMS;
    }

    public function isFailed(): bool
    {
        return $this->status === self::STATUS_FAILED;
    }

    public function isDelivered(): bool
    {
        return in_array($this->status, [self::STATUS_SENT, self::STATUS_DELIVERED]);
    }
}
