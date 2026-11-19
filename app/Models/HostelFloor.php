<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelFloor extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'hostel_building_id',
        'floor_number',
        'name',
        'gender_type',
        'description',
        'status',
        'display_order',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'floor_number' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function building(): BelongsTo
    {
        return $this->belongsTo(HostelBuilding::class, 'hostel_building_id');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class)->orderBy('room_number');
    }

    public function beds(): HasManyThrough
    {
        return $this->hasManyThrough(HostelBed::class, HostelRoom::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function getGenderLabelAttribute(): string
    {
        return match ($this->gender_type) {
            'boys_only' => __('Boys Only'),
            'girls_only' => __('Girls Only'),
            'coed' => __('Co-Ed / Open'),
            default => ucfirst(str_replace('_', ' ', (string) $this->gender_type)),
        };
    }
}
