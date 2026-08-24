<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Ticket extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_PENDING_USER = 'pending_user';

    public const STATUS_ON_HOLD = 'on_hold';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_CLOSED = 'closed';

    public const PRIORITY_LOW = 'low';

    public const PRIORITY_MEDIUM = 'medium';

    public const PRIORITY_HIGH = 'high';

    public const PRIORITY_URGENT = 'urgent';

    public const SOURCE_PORTAL = 'portal';

    public const SOURCE_EMAIL = 'email';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_API = 'api';

    protected $fillable = [
        'ticket_number',
        'uuid',
        'user_id',
        'guest_name',
        'guest_email',
        'guest_phone',
        'category_id',
        'assigned_to_user_id',
        'priority',
        'status',
        'subject',
        'description',
        'source',
        'last_reply_at',
        'last_reply_by_user_id',
        'resolved_at',
        'closed_at',
        'first_response_due_at',
        'resolution_due_at',
        'first_responded_at',
        'is_sla_response_breached',
        'is_sla_resolution_breached',
        'satisfaction_rating',
        'satisfaction_feedback',
        'metadata',
        'deleted_by',
    ];

    protected $casts = [
        'metadata' => 'array',
        'last_reply_at' => 'datetime',
        'resolved_at' => 'datetime',
        'closed_at' => 'datetime',
        'first_response_due_at' => 'datetime',
        'resolution_due_at' => 'datetime',
        'first_responded_at' => 'datetime',
        'is_sla_response_breached' => 'boolean',
        'is_sla_resolution_breached' => 'boolean',
        'satisfaction_rating' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function lastReplyBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'last_reply_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(TicketMessage::class)->orderBy('created_at', 'asc');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(TicketAttachment::class);
    }

    public function submitterName(): string
    {
        return $this->user ? $this->user->name : ($this->guest_name ?? __('Guest'));
    }

    public function submitterEmail(): string
    {
        return $this->user ? $this->user->email : ($this->guest_email ?? '');
    }

    public function isClosed(): bool
    {
        return in_array($this->status, [self::STATUS_RESOLVED, self::STATUS_CLOSED], true);
    }

    public function isOpen(): bool
    {
        return ! $this->isClosed();
    }

    public function statusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => 'blue',
            self::STATUS_IN_PROGRESS => 'amber',
            self::STATUS_PENDING_USER => 'purple',
            self::STATUS_ON_HOLD => 'zinc',
            self::STATUS_RESOLVED => 'emerald',
            self::STATUS_CLOSED => 'secondary',
            default => 'secondary',
        };
    }

    public function priorityBadgeColor(): string
    {
        return match ($this->priority) {
            self::PRIORITY_URGENT => 'destructive',
            self::PRIORITY_HIGH => 'rose',
            self::PRIORITY_MEDIUM => 'amber',
            self::PRIORITY_LOW => 'emerald',
            default => 'secondary',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_OPEN => __('Open'),
            self::STATUS_IN_PROGRESS => __('In Progress'),
            self::STATUS_PENDING_USER => __('Awaiting Customer'),
            self::STATUS_ON_HOLD => __('On Hold'),
            self::STATUS_RESOLVED => __('Resolved'),
            self::STATUS_CLOSED => __('Closed'),
            default => ucfirst(str_replace('_', ' ', $this->status)),
        };
    }

    public function priorityLabel(): string
    {
        return match ($this->priority) {
            self::PRIORITY_URGENT => __('Urgent'),
            self::PRIORITY_HIGH => __('High'),
            self::PRIORITY_MEDIUM => __('Medium'),
            self::PRIORITY_LOW => __('Low'),
            default => ucfirst($this->priority),
        };
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNotIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function scopeClosed(Builder $query): Builder
    {
        return $query->whereIn('status', [self::STATUS_RESOLVED, self::STATUS_CLOSED]);
    }

    public function scopeForUser(Builder $query, User $user): Builder
    {
        return $query->where('user_id', $user->id);
    }
}
