<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatMessage extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const TYPE_TEXT = 'text';

    public const TYPE_IMAGE = 'image';

    public const TYPE_FILE = 'file';

    public const TYPE_AUDIO = 'audio';

    public const TYPE_VIDEO = 'video';

    public const TYPE_SYSTEM = 'system';

    protected $attributes = [
        'is_edited' => false,
        'is_deleted_for_everyone' => false,
        'is_pinned' => false,
        'type' => self::TYPE_TEXT,
    ];

    protected $fillable = [
        'uuid',
        'conversation_id',
        'user_id',
        'reply_to_id',
        'body',
        'type',
        'is_edited',
        'edited_at',
        'is_deleted_for_everyone',
        'is_pinned',
        'pinned_at',
        'pinned_by',
        'metadata',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $message) {
            if (empty($message->uuid)) {
                $message->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_edited' => 'boolean',
            'is_deleted_for_everyone' => 'boolean',
            'is_pinned' => 'boolean',
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatConversation::class, 'conversation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function pinnedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'reply_to_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ChatMessageAttachment::class, 'message_id');
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(ChatMessageStatus::class, 'message_id');
    }

    /* ----------------------------------------------------------------- *
     *  Scopes
     * ----------------------------------------------------------------- */

    public function scopePinned(Builder $query): Builder
    {
        return $query->where('is_pinned', true);
    }

    public function scopeVisibleForUser(Builder $query, int $userId): Builder
    {
        return $query->whereDoesntHave('statuses', function (Builder $q) use ($userId) {
            $q->where('user_id', $userId)->where('is_deleted_for_me', true);
        });
    }

    /* ----------------------------------------------------------------- *
     *  Delivery & Seen Status Helpers (Single/Double/Blue Ticks)
     * ----------------------------------------------------------------- */

    /**
     * Check whether message is sent (author perspective).
     */
    public function isSent(): bool
    {
        return $this->exists;
    }

    /**
     * Check whether all recipients have had this message delivered.
     */
    public function isDeliveredToAll(): bool
    {
        $otherCount = $this->conversation->participants()
            ->where('user_id', '!=', $this->user_id)
            ->whereNull('left_at')
            ->count();

        if ($otherCount === 0) {
            return true;
        }

        $deliveredCount = $this->statuses()
            ->where('user_id', '!=', $this->user_id)
            ->where('is_delivered', true)
            ->count();

        return $deliveredCount >= $otherCount;
    }

    /**
     * Check whether all recipients have read/seen this message (Blue tick).
     */
    public function isReadByAll(): bool
    {
        $otherCount = $this->conversation->participants()
            ->where('user_id', '!=', $this->user_id)
            ->whereNull('left_at')
            ->count();

        if ($otherCount === 0) {
            return true;
        }

        $readCount = $this->statuses()
            ->where('user_id', '!=', $this->user_id)
            ->where('is_read', true)
            ->count();

        return $readCount >= $otherCount;
    }

    /**
     * Get tick icon state for the sender:
     * - 'single_tick' (Sent)
     * - 'double_tick' (Delivered)
     * - 'blue_double_tick' (Seen / Read)
     */
    public function tickStatus(): string
    {
        if ($this->is_deleted_for_everyone) {
            return 'none';
        }

        if ($this->isReadByAll()) {
            return 'blue_double_tick';
        }

        if ($this->isDeliveredToAll()) {
            return 'double_tick';
        }

        return 'single_tick';
    }

    public function formattedTime(): string
    {
        return $this->created_at?->format('H:i') ?? '';
    }
}
