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

class HostelBuilding extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'gender_type',
        'total_floors',
        'warden_name',
        'warden_contact',
        'warden_email',
        'manager_name',
        'manager_contact',
        'manager_email',
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
            'total_floors' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function floors(): HasMany
    {
        return $this->hasMany(HostelFloor::class)->orderBy('floor_number');
    }

    public function caretakers(): HasMany
    {
        return $this->hasMany(HostelCaretaker::class);
    }

    public function activeCaretakers(): HasMany
    {
        return $this->hasMany(HostelCaretaker::class)->where('status', 'active');
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(HostelRoom::class);
    }

    public function beds(): HasManyThrough
    {
        return $this->hasManyThrough(HostelBed::class, HostelRoom::class);
    }

    public function allocations(): HasManyThrough
    {
        return $this->hasManyThrough(HostelAllocation::class, HostelRoom::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeGender(Builder $query, string $gender): Builder
    {
        return $query->where('gender_type', $gender);
    }

    public function getGenderLabelAttribute(): string
    {
        return match ($this->gender_type) {
            'boys_only' => __('Boys Only'),
            'girls_only' => __('Girls Only'),
            'coed' => __('Co-Ed / All Wings'),
            default => ucfirst(str_replace('_', ' ', (string) $this->gender_type)),
        };
    }
}
