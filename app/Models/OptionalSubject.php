<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $code
 * @property string $category
 * @property string|null $description
 * @property bool $is_active
 * @property int|null $max_capacity
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, OptionalSubjectChangeRequest> $changeRequests
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read User|null $deleter
 */
class OptionalSubject extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'optional_subjects';

    protected $fillable = [
        'name',
        'code',
        'category',
        'description',
        'is_active',
        'max_capacity',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * Standard categories for UPSC CSE optional subjects.
     *
     * @return array<string>
     */
    public static function categories(): array
    {
        return [
            'Humanities & Social Sciences',
            'Literature of Languages',
            'Science & Mathematics',
            'Engineering',
            'Commerce & Management',
        ];
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'max_capacity' => 'integer',
        ];
    }

    public function changeRequests(): HasMany
    {
        return $this->hasMany(OptionalSubjectChangeRequest::class, 'optional_subject_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Scope active optional subjects.
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope search by keyword.
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (empty($term)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%")
                ->orWhere('category', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%");
        });
    }

    /**
     * Get number of students who currently selected this subject.
     */
    public function getStudentsCountAttribute(): int
    {
        return StudentOtherDetail::where('optional_subject', $this->name)->count();
    }
}
