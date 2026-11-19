<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExaminationCenterRoom extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'examination_center_id',
        'room_number',
        'floor',
        'building_name',
        'capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function center(): BelongsTo
    {
        return $this->belongsTo(ExaminationCenter::class, 'examination_center_id');
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ExamCandidate::class, 'examination_center_room_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
