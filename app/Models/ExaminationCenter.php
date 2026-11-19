<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ExaminationCenter extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'code',
        'name',
        'address',
        'city',
        'district',
        'state',
        'pincode',
        'contact_person',
        'contact_phone',
        'contact_email',
        'total_capacity',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'total_capacity' => 'integer',
        ];
    }

    public function rooms(): HasMany
    {
        return $this->hasMany(ExaminationCenterRoom::class, 'examination_center_id');
    }

    public function exams(): BelongsToMany
    {
        return $this->belongsToMany(Exam::class, 'exam_center_mappings', 'examination_center_id', 'exam_id')
            ->withPivot('allocated_seats', 'is_active')
            ->withTimestamps();
    }

    public function candidates(): HasMany
    {
        return $this->hasMany(ExamCandidate::class, 'examination_center_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
