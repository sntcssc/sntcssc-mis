<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelBed extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'hostel_room_id',
        'bed_number',
        'status',
        'notes',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id');
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(HostelAllocation::class);
    }

    public function currentAllocation(): HasOne
    {
        return $this->hasOne(HostelAllocation::class)
            ->whereIn('status', ['active', 'extended'])
            ->latestOfMany();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->where('status', 'available');
    }

    public function scopeOccupied(Builder $query): Builder
    {
        return $query->where('status', 'occupied');
    }

    public function isAvailable(): bool
    {
        return $this->status === 'available';
    }

    public function isOccupied(): bool
    {
        return $this->status === 'occupied';
    }
}
