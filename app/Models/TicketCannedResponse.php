<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TicketCannedResponse extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'title',
        'shortcut',
        'category_id',
        'content',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(TicketCategory::class, 'category_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Replace dynamic template placeholders.
     */
    public function renderContent(Ticket $ticket, ?User $agent = null): string
    {
        $vars = [
            '{user_name}' => $ticket->submitterName(),
            '{ticket_number}' => $ticket->ticket_number,
            '{ticket_subject}' => $ticket->subject,
            '{category}' => $ticket->category?->name ?? 'Support',
            '{agent_name}' => $agent?->name ?? auth()->user()?->name ?? 'Support Team',
            '{app_name}' => Setting::appName(),
        ];

        return strtr($this->content, $vars);
    }
}
