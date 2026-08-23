<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'team_id',
        'event',
        'auditable_type',
        'auditable_id',
        'ip_address',
        'user_agent',
        'url',
        'method',
        'old_values',
        'new_values',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
        ];
    }

    /* ----------------------------------------------------------------- *
     *  Relationships
     * ----------------------------------------------------------------- */

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /* ----------------------------------------------------------------- *
     *  Scopes
     * ----------------------------------------------------------------- */

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForEvent(Builder $query, string $event): Builder
    {
        return $query->where('event', $event);
    }

    public function scopeInDateRange(Builder $query, ?string $from, ?string $to): Builder
    {
        return $query
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to));
    }

    /* ----------------------------------------------------------------- *
     *  Presentation Helpers
     * ----------------------------------------------------------------- */

    public function eventBadgeColor(): string
    {
        return match ($this->event) {
            'created' => 'success',
            'updated', 'setting_updated', 'theme_changed', 'translations_updated' => 'primary',
            'deleted' => 'destructive',
            'restored' => 'info',
            'login' => 'emerald',
            'logout' => 'secondary',
            'failed_login' => 'destructive',
            'password_reset' => 'warning',
            'maintenance_mode_toggled' => 'amber',
            'language_created', 'language_updated', 'language_deleted' => 'violet',
            default => 'secondary',
        };
    }

    public function eventIcon(): string
    {
        return match ($this->event) {
            'created', 'language_created' => 'plus',
            'updated', 'setting_updated', 'translations_updated', 'language_updated' => 'pencil',
            'deleted', 'language_deleted' => 'trash-2',
            'restored' => 'rotate-ccw',
            'login' => 'log-in',
            'logout' => 'log-out',
            'failed_login' => 'shield-alert',
            'password_reset' => 'key',
            'theme_changed' => 'palette',
            'maintenance_mode_toggled' => 'alert-triangle',
            default => 'activity',
        };
    }

    public function targetLabel(): string
    {
        if ($this->auditable_type) {
            $class = class_basename($this->auditable_type);

            return "{$class} #{$this->auditable_id}";
        }

        return '—';
    }
}
