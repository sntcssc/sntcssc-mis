<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelExtensionRequest extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'request_no',
        'hostel_allocation_id',
        'user_id',
        'current_end_date',
        'requested_end_date',
        'extension_duration_type',
        'extension_duration_value',
        'reason',
        'status',
        'admin_notes',
        'reviewed_by',
        'reviewed_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'current_end_date' => 'date',
            'requested_end_date' => 'date',
            'extension_duration_value' => 'integer',
            'reviewed_at' => 'datetime',
        ];
    }

    public function allocation(): BelongsTo
    {
        return $this->belongsTo(HostelAllocation::class, 'hostel_allocation_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isApproved(): bool
    {
        return $this->status === 'approved';
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected';
    }

    public function getRejectionReasonAttribute(): ?string
    {
        return $this->admin_notes;
    }

    public function setRejectionReasonAttribute(?string $value): void
    {
        $this->attributes['admin_notes'] = $value;
    }
}
