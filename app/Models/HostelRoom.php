<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelRoom extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'hostel_building_id',
        'hostel_floor_id',
        'room_number',
        'room_type',
        'capacity',
        'gender_allowed',
        'status',
        'amenities',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'amenities' => 'array',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(HostelBuilding::class, 'hostel_building_id');
    }

    public function floor(): BelongsTo
    {
        return $this->belongsTo(HostelFloor::class, 'hostel_floor_id');
    }

    public function beds(): HasMany
    {
        return $this->hasMany(HostelBed::class)->orderBy('bed_number');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(HostelAllocation::class);
    }

    public function activeAllocations(): HasMany
    {
        return $this->hasMany(HostelAllocation::class)->whereIn('status', ['active', 'extended']);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', '!=', 'inactive');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available');
    }

    public function scopeGender(Builder $query, string $gender): Builder
    {
        return $query->where('gender_allowed', $gender);
    }

    public function isSharing(): bool
    {
        return $this->room_type === 'sharing';
    }

    public function occupiedBedsCount(): int
    {
        return $this->beds()->where('status', 'occupied')->count();
    }

    public function availableBedsCount(): int
    {
        return $this->beds()->where('status', 'available')->count();
    }

    public function isFull(): bool
    {
        return $this->availableBedsCount() === 0;
    }

    public function getGenderLabelAttribute(): string
    {
        return match ($this->gender_allowed) {
            'male_only' => __('Male Only'),
            'female_only' => __('Female Only'),
            'any' => __('Any Gender / Open'),
            default => ucfirst(str_replace('_', ' ', (string) $this->gender_allowed)),
        };
    }
}
