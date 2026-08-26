<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property string $type
 * @property string $category
 * @property string $title
 * @property string $message
 * @property array<string, mixed>|null $data
 * @property string $channel
 * @property Carbon|null $read_at
 * @property Carbon|null $sound_played_at
 * @property int|null $created_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 */
class AppNotification extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const CATEGORY_TICKET = 'ticket';

    public const CATEGORY_SYSTEM = 'system';

    public const CATEGORY_CHAT = 'chat';

    public const CATEGORY_CALL = 'call';

    public const CATEGORY_SECURITY = 'security';

    public const CATEGORY_MARKETING = 'marketing';

    public const CHANNEL_DATABASE = 'database';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_TELEGRAM = 'telegram';

    protected $fillable = [
        'uuid',
        'user_id',
        'type',
        'category',
        'title',
        'message',
        'data',
        'channel',
        'read_at',
        'sound_played_at',
        'created_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $notification) {
            if (empty($notification->uuid)) {
                $notification->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'read_at' => 'datetime',
            'sound_played_at' => 'datetime',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /* ----------------------------------------------------------------- *
     *  Scopes
     * ----------------------------------------------------------------- */

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeUnread(Builder $query): Builder
    {
        return $query->whereNull('read_at');
    }

    public function scopeRead(Builder $query): Builder
    {
        return $query->whereNotNull('read_at');
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /* ----------------------------------------------------------------- *
     *  Helper Methods
     * ----------------------------------------------------------------- */

    public function isRead(): bool
    {
        return ! is_null($this->read_at);
    }

    public function markAsRead(): bool
    {
        if ($this->isRead()) {
            return true;
        }

        return $this->update(['read_at' => now()]);
    }

    public function markAsUnread(): bool
    {
        return $this->update(['read_at' => null]);
    }

    public function actionUrl(): ?string
    {
        return $this->data['action_url'] ?? null;
    }

    public function actionLabel(): string
    {
        return $this->data['action_label'] ?? __('View');
    }

    public function iconName(): string
    {
        return $this->data['icon'] ?? match ($this->category) {
            self::CATEGORY_TICKET => 'ticket',
            self::CATEGORY_CHAT => 'message-square',
            self::CATEGORY_CALL => 'phone',
            self::CATEGORY_SECURITY => 'shield-alert',
            self::CATEGORY_MARKETING => 'megaphone',
            default => 'bell',
        };
    }

    public function colorClass(): string
    {
        return $this->data['color'] ?? match ($this->category) {
            self::CATEGORY_TICKET => 'text-amber-500 bg-amber-500/10 border-amber-500/20',
            self::CATEGORY_CHAT => 'text-blue-500 bg-blue-500/10 border-blue-500/20',
            self::CATEGORY_CALL => 'text-emerald-500 bg-emerald-500/10 border-emerald-500/20',
            self::CATEGORY_SECURITY => 'text-rose-500 bg-rose-500/10 border-rose-500/20',
            self::CATEGORY_MARKETING => 'text-purple-500 bg-purple-500/10 border-purple-500/20',
            default => 'text-primary bg-primary/10 border-primary/20',
        };
    }

    public function categoryLabel(): string
    {
        return match ($this->category) {
            self::CATEGORY_TICKET => __('Ticket'),
            self::CATEGORY_CHAT => __('Chat'),
            self::CATEGORY_CALL => __('Call'),
            self::CATEGORY_SECURITY => __('Security'),
            self::CATEGORY_MARKETING => __('Marketing'),
            default => __('System'),
        };
    }
}
