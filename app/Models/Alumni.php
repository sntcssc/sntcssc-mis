<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $user_id
 * @property int|null $student_profile_id
 * @property string $first_name
 * @property string $last_name
 * @property string $full_name
 * @property string|null $email
 * @property string|null $phone
 * @property string|null $alternate_phone
 * @property string|null $photo_url
 * @property string|null $gender
 * @property Carbon|null $dob
 * @property int|null $passing_year
 * @property string $service_type
 * @property string $service_name
 * @property string $designation
 * @property string|null $cadre
 * @property int|null $all_india_rank
 * @property int|null $state_rank
 * @property int|null $exam_year
 * @property string|null $roll_number
 * @property string|null $posting_city
 * @property string|null $posting_district
 * @property string|null $posting_state
 * @property string $posting_country
 * @property string|null $department_ministry
 * @property Carbon|null $posting_date
 * @property string|null $office_address
 * @property string|null $current_address
 * @property string|null $permanent_address
 * @property string|null $linkedin_url
 * @property string|null $twitter_url
 * @property string|null $website_url
 * @property string|null $short_bio
 * @property string|null $detailed_bio
 * @property string|null $success_story
 * @property string|null $testimonial
 * @property array|null $achievements
 * @property bool $is_featured
 * @property bool $is_verified
 * @property string $status
 * @property string $source
 * @property int $display_order
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $user
 * @property-read StudentProfile|null $studentProfile
 * @property-read Collection<int, AlumniCourseBatch> $courseBatches
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read User|null $deleter
 */
class Alumni extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'alumni';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_RETIRED = 'retired';

    public const STATUS_DECEASED = 'deceased';

    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_STUDENT = 'student_conversion';

    public const SOURCE_IMPORT = 'import';

    public const SERVICE_TYPES = [
        'UPSC CSE' => 'Union Public Service Commission (UPSC Civil Services)',
        'West Bengal Civil Service (WBCS)' => 'West Bengal Civil Service (Exe)',
        'West Bengal Police Service (WBPS)' => 'West Bengal Police Service',
        'Indian Forest Service (IFoS)' => 'Indian Forest Service',
        'Judicial Services' => 'West Bengal Judicial Service / Higher Judicial',
        'Central Services / Allied' => 'Central Armed Police Forces / Allied Services',
        'Banking & Finance' => 'RBI / Public Sector Bank PO / Regulatory Bodies',
        'PSU / Central Govt' => 'Public Sector Undertaking / Central Autonomous',
        'Other State Civil Services' => 'Other State Civil / Police Services',
        'Corporate / Private' => 'Private Sector / Corporate Leadership',
        'Academia & Research' => 'Higher Education & Research / University Faculty',
        'Other' => 'Other Service / Sector',
    ];

    protected $fillable = [
        'uuid',
        'user_id',
        'student_profile_id',
        'first_name',
        'last_name',
        'full_name',
        'email',
        'phone',
        'alternate_phone',
        'photo_url',
        'gender',
        'dob',
        'passing_year',
        'service_category_id',
        'service_type',
        'service_name',
        'designation',
        'cadre',
        'all_india_rank',
        'state_rank',
        'exam_year',
        'roll_number',
        'posting_city',
        'posting_district',
        'posting_state',
        'posting_country',
        'department_ministry',
        'posting_date',
        'office_address',
        'current_address',
        'permanent_address',
        'linkedin_url',
        'twitter_url',
        'website_url',
        'short_bio',
        'detailed_bio',
        'success_story',
        'testimonial',
        'achievements',
        'is_featured',
        'is_verified',
        'status',
        'source',
        'display_order',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'dob' => 'date',
            'posting_date' => 'date',
            'passing_year' => 'integer',
            'exam_year' => 'integer',
            'all_india_rank' => 'integer',
            'state_rank' => 'integer',
            'display_order' => 'integer',
            'is_featured' => 'boolean',
            'is_verified' => 'boolean',
            'achievements' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Alumni $alumni) {
            if (empty($alumni->uuid)) {
                $alumni->uuid = (string) Str::uuid();
            }

            if (empty($alumni->full_name)) {
                $alumni->full_name = trim($alumni->first_name.' '.$alumni->last_name);
            }

            if (auth()->check() && empty($alumni->created_by)) {
                $alumni->created_by = auth()->id();
            }
        });

        static::updating(function (Alumni $alumni) {
            $alumni->full_name = trim($alumni->first_name.' '.$alumni->last_name);

            if (auth()->check()) {
                $alumni->updated_by = auth()->id();
            }
        });

        static::deleting(function (Alumni $alumni) {
            if (auth()->check()) {
                $alumni->deleted_by = auth()->id();
                $alumni->saveQuietly();
            }
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function studentProfile(): BelongsTo
    {
        return $this->belongsTo(StudentProfile::class, 'student_profile_id');
    }

    public function serviceCategory(): BelongsTo
    {
        return $this->belongsTo(AlumniServiceCategory::class, 'service_category_id');
    }

    public function courseBatches(): HasMany
    {
        return $this->hasMany(AlumniCourseBatch::class, 'alumni_id');
    }

    public function examinations(): HasMany
    {
        return $this->hasMany(AlumniExamination::class, 'alumni_id')->orderByDesc('exam_year');
    }

    public function primaryExamination(): HasOne
    {
        return $this->hasOne(AlumniExamination::class, 'alumni_id')->where('is_primary', true)->latestOfMany();
    }

    public function postings(): HasMany
    {
        return $this->hasMany(AlumniPosting::class, 'alumni_id')->orderByDesc('from_date');
    }

    public function currentPosting(): HasOne
    {
        return $this->hasOne(AlumniPosting::class, 'alumni_id')->where('is_current', true)->latestOfMany();
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
     * Get avatar / photo URL with fallback to UI Avatar.
     */
    public function getPhotoDisplayUrlAttribute(): string
    {
        if ($this->photo_url) {
            if (str_starts_with($this->photo_url, 'http://') || str_starts_with($this->photo_url, 'https://')) {
                return $this->photo_url;
            }

            return Storage::disk('public')->url($this->photo_url);
        }

        $name = urlencode($this->full_name ?: 'Alumni');

        return "https://ui-avatars.com/api/?name={$name}&color=7F9CF5&background=EBF4FF";
    }

    /**
     * Get a color theme for the service badge.
     */
    public function getServiceBadgeColorAttribute(): string
    {
        $type = strtoupper($this->service_type ?? '');
        $service = strtoupper($this->service_name ?? '');

        if (str_contains($type, 'UPSC') || str_contains($service, 'IAS') || str_contains($service, 'IFS')) {
            return 'violet';
        }

        if (str_contains($service, 'IPS') || str_contains($type, 'POLICE') || str_contains($service, 'WBPS')) {
            return 'blue';
        }

        if (str_contains($type, 'WBCS') || str_contains($service, 'WBCS')) {
            return 'emerald';
        }

        if (str_contains($type, 'BANKING') || str_contains($service, 'RBI')) {
            return 'amber';
        }

        if (str_contains($type, 'JUDICIAL')) {
            return 'purple';
        }

        return 'sky';
    }

    /**
     * Get comma-separated list of all enrolled courses.
     */
    public function getCoursesSummaryAttribute(): string
    {
        return $this->courseBatches->pluck('course_name')->filter()->unique()->implode(', ') ?: 'N/A';
    }

    /**
     * Get comma-separated list of all enrolled batches.
     */
    public function getBatchesSummaryAttribute(): string
    {
        return $this->courseBatches->pluck('batch_name')->filter()->unique()->implode(', ') ?: 'N/A';
    }

    /**
     * Scope search query across common fields.
     */
    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (empty($search)) {
            return $query;
        }

        $term = '%'.trim($search).'%';

        return $query->where(function (Builder $q) use ($term) {
            $q->where('full_name', 'like', $term)
                ->orWhere('first_name', 'like', $term)
                ->orWhere('last_name', 'like', $term)
                ->orWhere('email', 'like', $term)
                ->orWhere('phone', 'like', $term)
                ->orWhere('service_type', 'like', $term)
                ->orWhere('service_name', 'like', $term)
                ->orWhere('designation', 'like', $term)
                ->orWhere('cadre', 'like', $term)
                ->orWhere('roll_number', 'like', $term)
                ->orWhere('posting_city', 'like', $term)
                ->orWhere('posting_state', 'like', $term)
                ->orWhere('department_ministry', 'like', $term)
                ->orWhereHas('courseBatches', function (Builder $cbq) use ($term) {
                    $cbq->where('course_name', 'like', $term)
                        ->orWhere('batch_name', 'like', $term)
                        ->orWhere('enrollment_no', 'like', $term);
                });
        });
    }

    public function scopeFeatured(Builder $query): Builder
    {
        return $query->where('is_featured', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function scopeServiceType(Builder $query, ?string $type): Builder
    {
        if (empty($type) || $type === 'all') {
            return $query;
        }

        return $query->where('service_type', $type);
    }

    /**
     * Get dynamic list of available active service categories.
     *
     * @return array<string, string>
     */
    public static function getServiceTypes(): array
    {
        try {
            $categories = AlumniServiceCategory::active()->ordered()->get();
            if ($categories->isNotEmpty()) {
                return $categories->pluck('name', 'code')->toArray();
            }
        } catch (\Throwable) {
            // Fallback if table is not available
        }

        return self::SERVICE_TYPES;
    }
}
