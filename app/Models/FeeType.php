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
 * @property string $code
 * @property string $name
 * @property string $category
 * @property string $frequency
 * @property string|null $description
 * @property float $default_amount
 * @property bool $is_refundable
 * @property string $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Collection<int, FeeSchedule> $schedules
 * @property-read Collection<int, StudentFeeInvoice> $invoices
 * @property-read User|null $creator
 */
class FeeType extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const CATEGORY_ACADEMIC = 'academic';

    public const CATEGORY_LIBRARY = 'library';

    public const CATEGORY_ADMISSION = 'admission';

    public const CATEGORY_MISCELLANEOUS = 'miscellaneous';

    public const FREQUENCY_MONTHLY = 'monthly';

    public const FREQUENCY_ONE_TIME = 'one_time';

    public const FREQUENCY_QUARTERLY = 'quarterly';

    public const FREQUENCY_YEARLY = 'yearly';

    public const FREQUENCY_WEEKLY = 'weekly';

    protected $fillable = [
        'code',
        'name',
        'category',
        'frequency',
        'description',
        'default_amount',
        'is_refundable',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'default_amount' => 'decimal:2',
            'is_refundable' => 'boolean',
        ];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(FeeSchedule::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(StudentFeeInvoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }
}
