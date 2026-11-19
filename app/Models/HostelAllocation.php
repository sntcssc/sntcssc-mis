<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class HostelAllocation extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'allocation_no',
        'user_id',
        'resident_type',
        'hostel_room_id',
        'hostel_bed_id',
        'start_date',
        'end_date',
        'duration_type',
        'duration_value',
        'actual_vacated_date',
        'status',
        'remarks',
        'hard_copy_submitted',
        'hard_copy_submitted_at',
        'hard_copy_received_by',
        'hard_copy_notes',
        'soft_copy_path',
        'soft_copy_uploaded_at',
        'soft_copy_uploaded_by',
        'soft_copy_verified',
        'soft_copy_verified_at',
        'soft_copy_verified_by',
        'is_previous_batch_student',
        'previous_batch_name',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'actual_vacated_date' => 'date',
            'duration_value' => 'integer',
            'hard_copy_submitted' => 'boolean',
            'hard_copy_submitted_at' => 'date',
            'soft_copy_uploaded_at' => 'datetime',
            'soft_copy_verified' => 'boolean',
            'soft_copy_verified_at' => 'datetime',
            'is_previous_batch_student' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(HostelRoom::class, 'hostel_room_id');
    }

    public function bed(): BelongsTo
    {
        return $this->belongsTo(HostelBed::class, 'hostel_bed_id');
    }

    public function hardCopyReceiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'hard_copy_received_by');
    }

    public function softCopyUploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soft_copy_uploaded_by');
    }

    public function softCopyVerifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'soft_copy_verified_by');
    }

    public function extensionRequests(): HasMany
    {
        return $this->hasMany(HostelExtensionRequest::class)->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn($this->qualifyColumn('status'), ['active', 'extended']);
    }

    public function scopeVacated(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('status'), 'vacated');
    }

    public function scopeExpiringSoon(Builder $query, int $days = 15): Builder
    {
        return $query->active()
            ->whereNotNull('end_date')
            ->whereBetween('end_date', [now()->startOfDay(), now()->addDays($days)->endOfDay()]);
    }

    public function isActive(): bool
    {
        return in_array($this->status, ['active', 'extended'], true);
    }

    public function isResidentStudent(): bool
    {
        return $this->resident_type === 'student'
            || (bool) $this->is_previous_batch_student
            || (bool) $this->user?->hasRole('Student')
            || (bool) $this->user?->studentProfile
            || (bool) $this->user?->activeEnrollment;
    }

    public function isExpiringSoon(int $days = 15): bool
    {
        if (! $this->isActive() || ! $this->end_date) {
            return false;
        }

        return $this->end_date->isFuture() && $this->end_date->diffInDays(now()) <= $days;
    }

    public function isOverdue(): bool
    {
        if (! $this->isActive() || ! $this->end_date) {
            return false;
        }

        return $this->end_date->isPast();
    }

    public function daysRemaining(): ?int
    {
        if (! $this->end_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($this->end_date, false);
    }

    public function getDurationFormattedAttribute(): string
    {
        if (! $this->duration_type || $this->duration_type === 'indefinite') {
            return __('Indefinite / Open Stay');
        }

        if ($this->duration_type === 'custom' && $this->start_date && $this->end_date) {
            return $this->start_date->format('d M Y').' - '.$this->end_date->format('d M Y');
        }

        $val = $this->duration_value ?? 1;

        return match ($this->duration_type) {
            'days' => $val.' '.__('Days'),
            'months' => $val.' '.__('Months'),
            'years' => $val.' '.__('Years'),
            default => $val.' '.ucfirst($this->duration_type),
        };
    }

    public function getPreviousBatchIdentifierAttribute(): ?string
    {
        return $this->previous_batch_name;
    }

    public function setPreviousBatchIdentifierAttribute(?string $value): void
    {
        $this->attributes['previous_batch_name'] = $value;
    }

    public function getVacatedAtAttribute(): mixed
    {
        return $this->actual_vacated_date;
    }

    public function setVacatedAtAttribute($value): void
    {
        $this->attributes['actual_vacated_date'] = $value;
    }

    public function getHardCopyFormSubmittedAttribute(): bool
    {
        return (bool) $this->hard_copy_submitted;
    }

    public function setHardCopyFormSubmittedAttribute($value): void
    {
        $this->attributes['hard_copy_submitted'] = (bool) $value;
    }

    public function getFormSubmittedAttribute(): bool
    {
        return (bool) $this->hard_copy_submitted;
    }

    public function setFormSubmittedAttribute($value): void
    {
        $this->attributes['hard_copy_submitted'] = (bool) $value;
    }

    public function getStudentIdentifierAttribute(): ?string
    {
        return $this->user?->student_id ?? $this->user?->urn ?? $this->user?->studentProfile?->urn;
    }

    public function getEnrollmentNoAttribute(): ?string
    {
        return $this->user?->activeEnrollment?->enrollment_no;
    }

    public function getCourseNameAttribute(): ?string
    {
        return $this->user?->activeEnrollment?->course?->name;
    }

    public function getCourseCodeAttribute(): ?string
    {
        return $this->user?->activeEnrollment?->course?->code;
    }

    public function getBatchNameAttribute(): ?string
    {
        if ($this->is_previous_batch_student && $this->previous_batch_name) {
            return $this->previous_batch_name;
        }

        return $this->user?->activeEnrollment?->batch?->name;
    }

    public function getSectionNameAttribute(): ?string
    {
        return $this->user?->activeEnrollment?->section?->name;
    }
}
