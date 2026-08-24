<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role as SpatieRole;

/**
 * @property int $id
 * @property string $name
 * @property string|null $description
 * @property string $color
 * @property bool $is_system
 * @property string $guard_name
 * @property int|null $team_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Role extends SpatieRole
{
    use Auditable;

    protected $fillable = [
        'name',
        'guard_name',
        'description',
        'color',
        'is_system',
        'team_id',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    /**
     * Scope for non-system roles (custom user roles).
     */
    public function scopeCustom(Builder $query): Builder
    {
        return $query->where('is_system', false);
    }

    /**
     * Scope for system roles.
     */
    public function scopeSystem(Builder $query): Builder
    {
        return $query->where('is_system', true);
    }

    /**
     * Determine if this role can be safely edited or deleted.
     */
    public function isDeletable(): bool
    {
        return ! $this->is_system && $this->users()->count() === 0;
    }
}
