<?php

namespace App\Models;

use App\Concerns\Auditable;
use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatConversation extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const TYPE_DIRECT = 'direct';

    public const TYPE_GROUP = 'group';

    public const TYPE_CHANNEL = 'channel';

    protected $fillable = [
        'uuid',
        'type',
        'title',
        'description',
        'avatar',
        'invite_code',
        'is_broadcast_only',
        'team_id',
        'created_by',
        'updated_by',
        'deleted_by',
        'last_message_at',
        'last_message_id',
        'settings',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $conversation) {
            if (empty($conversation->uuid)) {
                $conversation->uuid = (string) Str::uuid();
            }

            if (empty($conversation->invite_code) && in_array($conversation->type, [self::TYPE_GROUP, self::TYPE_CHANNEL], true)) {
                $conversation->invite_code = Str::random(16);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'is_broadcast_only' => 'boolean',
            'last_message_at' => 'datetime',
            'settings' => 'array',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(ChatParticipant::class, 'conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatMessage::class, 'conversation_id');
    }

    public function lastMessage(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'last_message_id');
    }

    public function calls(): HasMany
    {
        return $this->hasMany(ChatCall::class, 'conversation_id');
    }

    /* ----------------------------------------------------------------- *
     *  Scopes
     * ----------------------------------------------------------------- */

    public function scopeDirect(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_DIRECT);
    }

    public function scopeGroup(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_GROUP);
    }

    public function scopeChannel(Builder $query): Builder
    {
        return $query->where('type', self::TYPE_CHANNEL);
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->whereHas('participants', function (Builder $q) use ($userId) {
            $q->where('user_id', $userId)->whereNull('left_at');
        });
    }

    /* ----------------------------------------------------------------- *
     *  Helpers & Accessors
     * ----------------------------------------------------------------- */

    public function isDirect(): bool
    {
        return $this->type === self::TYPE_DIRECT;
    }

    public function isGroup(): bool
    {
        return $this->type === self::TYPE_GROUP;
    }

    public function isChannel(): bool
    {
        return $this->type === self::TYPE_CHANNEL;
    }

    public function isGroupOrChannel(): bool
    {
        return in_array($this->type, [self::TYPE_GROUP, self::TYPE_CHANNEL], true);
    }

    /**
     * Resolve the other user in a direct 1-on-1 conversation.
     */
    public function otherParticipant(?User $currentUser = null): ?User
    {
        $currentUser = $currentUser ?? auth()->user();
        if (! $currentUser) {
            return null;
        }

        $other = $this->participants
            ->firstWhere('user_id', '!=', $currentUser->id);

        return $other?->user;
    }

    /**
     * Get display name of conversation for a specific user.
     */
    public function displayNameFor(?User $currentUser = null): string
    {
        if ($this->isDirect()) {
            $other = $this->otherParticipant($currentUser);

            return $other?->name ?? __('Direct Message');
        }

        return $this->title ?? __('Untitled Conversation');
    }

    /**
     * Get display avatar URL of conversation for a specific user.
     */
    public function displayAvatarFor(?User $currentUser = null): ?string
    {
        if ($this->isDirect()) {
            $other = $this->otherParticipant($currentUser);

            return $other?->avatarUrl();
        }

        if ($this->avatar) {
            return FileUploadService::url($this->avatar);
        }

        return null;
    }

    /**
     * Get initials for placeholder avatar.
     */
    public function displayInitialsFor(?User $currentUser = null): string
    {
        $name = $this->displayNameFor($currentUser);
        $words = preg_split('/\s+/', trim($name));

        if (empty($words)) {
            return 'C';
        }

        if (count($words) === 1) {
            return strtoupper(mb_substr($words[0], 0, 2));
        }

        return strtoupper(mb_substr($words[0], 0, 1).mb_substr(end($words), 0, 1));
    }

    /**
     * Count unread messages in this conversation for given user.
     */
    public function unreadCountFor(int $userId): int
    {
        return ChatMessageStatus::where('user_id', $userId)
            ->where('is_read', false)
            ->where('is_deleted_for_me', false)
            ->whereHas('message', fn ($q) => $q->where('conversation_id', $this->id)->where('is_deleted_for_everyone', false))
            ->count();
    }

    /**
     * Check if a specific user is authorized to post messages here.
     */
    public function canPost(?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole('Super Administrator')) {
            return true;
        }

        $participant = $this->participants->firstWhere('user_id', $user->id);
        if (! $participant || $participant->left_at !== null) {
            return false;
        }

        // Check conversation settings for posting permissions: 'all', 'admins_only', or 'permitted_only'
        $postingRule = $this->settings['posting_permission'] ?? ($this->isChannel() && $this->is_broadcast_only ? 'admins_only' : 'all');

        if ($postingRule === 'admins_only') {
            return in_array($participant->role, [ChatParticipant::ROLE_OWNER, ChatParticipant::ROLE_ADMIN], true);
        }

        if ($postingRule === 'permitted_only') {
            $allowedUserIds = $this->settings['allowed_poster_ids'] ?? [];

            return in_array($participant->role, [ChatParticipant::ROLE_OWNER, ChatParticipant::ROLE_ADMIN], true)
                || in_array($user->id, $allowedUserIds, true);
        }

        return true;
    }

    /**
     * Check if a user is an admin or owner of this conversation.
     */
    public function isUserAdmin(int $userId): bool
    {
        $participant = $this->participants->firstWhere('user_id', $userId);

        return $participant && in_array($participant->role, [ChatParticipant::ROLE_OWNER, ChatParticipant::ROLE_ADMIN], true);
    }

    /**
     * Check if a user has permission to modify group/channel title, description, avatar, settings.
     */
    public function canModifyProfile(?User $user = null): bool
    {
        $user = $user ?? auth()->user();
        if (! $user) {
            return false;
        }

        if ($user->hasRole('Super Administrator') || $user->can('chat.manage_settings')) {
            return true;
        }

        return $this->isUserAdmin($user->id);
    }

    /**
     * Generate or ensure invite code for group/channel.
     */
    public function ensureInviteCode(): string
    {
        if (empty($this->invite_code)) {
            $this->forceFill(['invite_code' => Str::random(16)])->save();
        }

        return $this->invite_code;
    }

    /**
     * Get full sharable invite link URL.
     */
    public function getInviteUrlAttribute(): string
    {
        $code = $this->ensureInviteCode();

        return url('/live-chat/join/'.$code);
    }
}
