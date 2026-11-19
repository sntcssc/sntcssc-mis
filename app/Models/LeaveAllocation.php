<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveAllocation extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id',
        'leave_category_id',
        'year',
        'total_days',
        'used_days',
        'pending_days',
        'carried_forward_days',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'total_days' => 'decimal:1',
            'used_days' => 'decimal:1',
            'pending_days' => 'decimal:1',
            'carried_forward_days' => 'decimal:1',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(LeaveCategory::class, 'leave_category_id');
    }

    public function remainingDays(): float
    {
        return max(0, (float) ($this->total_days + $this->carried_forward_days - $this->used_days - $this->pending_days));
    }

    public function scopeForYear(Builder $query, int $year): Builder
    {
        return $query->where('year', $year);
    }
}
