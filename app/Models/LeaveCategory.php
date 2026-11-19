<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LeaveCategory extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'name',
        'code',
        'applicable_to',
        'color',
        'annual_quota',
        'monthly_limit',
        'requires_attachment',
        'requires_approval',
        'is_paid',
        'is_active',
        'description',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'annual_quota' => 'decimal:1',
            'monthly_limit' => 'decimal:1',
            'requires_attachment' => 'boolean',
            'requires_approval' => 'boolean',
            'is_paid' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function applications(): HasMany
    {
        return $this->hasMany(LeaveApplication::class);
    }

    public function allocations(): HasMany
    {
        return $this->hasMany(LeaveAllocation::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeForStaff(Builder $query): Builder
    {
        return $query->whereIn('applicable_to', ['staff', 'both']);
    }

    public function scopeForStudent(Builder $query): Builder
    {
        return $query->whereIn('applicable_to', ['student', 'both']);
    }

    public function badgeColorClass(): string
    {
        return match ($this->color) {
            'emerald', 'green' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
            'amber', 'yellow' => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
            'rose', 'red' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
            'purple', 'violet' => 'bg-purple-500/10 text-purple-600 dark:text-purple-400 border-purple-500/20',
            'teal', 'cyan' => 'bg-teal-500/10 text-teal-600 dark:text-teal-400 border-teal-500/20',
            default => 'bg-blue-500/10 text-blue-600 dark:text-blue-400 border-blue-500/20',
        };
    }
}
