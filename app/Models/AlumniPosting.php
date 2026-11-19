<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $alumni_id
 * @property string $designation
 * @property string|null $department_ministry
 * @property string|null $posting_city
 * @property string|null $posting_district
 * @property string|null $posting_state
 * @property string $posting_country
 * @property string|null $office_address
 * @property Carbon|null $from_date
 * @property Carbon|null $to_date
 * @property bool $is_current
 * @property string|null $order_number
 * @property string|null $responsibilities
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Alumni $alumni
 */
class AlumniPosting extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'alumni_postings';

    protected $fillable = [
        'alumni_id',
        'designation',
        'department_ministry',
        'posting_city',
        'posting_district',
        'posting_state',
        'posting_country',
        'office_address',
        'from_date',
        'to_date',
        'is_current',
        'order_number',
        'responsibilities',
        'remarks',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_date' => 'date',
            'to_date' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function alumni(): BelongsTo
    {
        return $this->belongsTo(Alumni::class, 'alumni_id');
    }

    /**
     * Get human readable period string, e.g. "Aug 2021 – May 2023" or "Jun 2024 – Present".
     */
    public function getDurationAttribute(): string
    {
        $from = $this->from_date ? $this->from_date->format('M Y') : 'N/A';
        $to = $this->is_current ? 'Present' : ($this->to_date ? $this->to_date->format('M Y') : 'Ongoing');

        return "{$from} – {$to}";
    }
}
