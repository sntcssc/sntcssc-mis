<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelCaretaker extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'hostel_building_id',
        'name',
        'phone',
        'email',
        'shift',
        'is_primary',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_primary' => 'boolean',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(HostelBuilding::class, 'hostel_building_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopePrimary(Builder $query): Builder
    {
        return $query->where('is_primary', true);
    }

    public function getShiftLabelAttribute(): string
    {
        return match ($this->shift) {
            'day' => __('Day Shift (6 AM - 2 PM)'),
            'evening' => __('Evening Shift (2 PM - 10 PM)'),
            'night' => __('Night Shift (10 PM - 6 AM)'),
            'general' => __('General / 24x7'),
            default => ucfirst($this->shift ?? 'Day'),
        };
    }
}
