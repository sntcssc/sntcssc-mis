<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property int|null $course_id
 * @property string $title
 * @property string|null $slug
 * @property string|null $subject
 * @property string|null $topic
 * @property string|null $module_code
 * @property string $material_type
 * @property string|null $description
 * @property string|null $content
 * @property string|null $external_url
 * @property string|null $thumbnail_path
 * @property string|null $thumbnail_url
 * @property Carbon|null $class_date
 * @property int $display_order
 * @property string $access_level
 * @property string|null $share_token
 * @property Carbon|null $share_expires_at
 * @property string|null $share_password
 * @property bool $is_pinned
 * @property string $status
 * @property int $view_count
 * @property int $download_count
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Course|null $course
 * @property-read Collection<int, Batch> $batches
 * @property-read Collection<int, StudyMaterialFile> $files
 * @property-read Collection<int, User> $students
 * @property-read Collection<int, StudyMaterialShare> $shares
 * @property-read Collection<int, StudyMaterialAccessLog> $accessLogs
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read string|null $thumbnail_image_url
 */
class StudyMaterial extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const TYPE_CURRENT_AFFAIRS = 'current_affairs';

    public const TYPE_PIB_SANDHAN = 'pib_sandhan';

    public const TYPE_LECTURE_NOTES = 'lecture_notes';

    public const TYPE_E_BOOK_PDF = 'e_book_pdf';

    public const TYPE_SLIDE_DECK = 'slide_deck';

    public const TYPE_VIDEO_RECORDING = 'video_recording';

    public const TYPE_AUDIO_LECTURE = 'audio_lecture';

    public const TYPE_QUESTION_BANK = 'question_bank';

    public const TYPE_SYLLABUS = 'syllabus';

    public const TYPE_ASSIGNMENT_PRACTICE = 'assignment_practice';

    public const TYPE_HANDOUT_REFERENCE = 'handout_reference';

    public const STATUS_PUBLISHED = 'published';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ARCHIVED = 'archived';

    public const ACCESS_ENROLLED_ONLY = 'enrolled_only';

    public const ACCESS_PUBLIC_LINK = 'public_link';

    public const ACCESS_RESTRICTED_STUDENTS = 'restricted_students';

    protected $fillable = [
        'uuid',
        'course_id',
        'title',
        'slug',
        'subject',
        'topic',
        'module_code',
        'material_type',
        'description',
        'content',
        'external_url',
        'thumbnail_path',
        'thumbnail_url',
        'class_date',
        'display_order',
        'access_level',
        'share_token',
        'share_expires_at',
        'share_password',
        'is_pinned',
        'status',
        'view_count',
        'download_count',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'share_expires_at' => 'datetime',
            'class_date' => 'date',
            'display_order' => 'integer',
            'view_count' => 'integer',
            'download_count' => 'integer',
        ];
    }

    /**
     * Get the resolved public URL for the study material thumbnail image.
     */
    public function getThumbnailImageUrlAttribute(): ?string
    {
        if (! empty($this->thumbnail_path)) {
            return Storage::disk('public')->url($this->thumbnail_path);
        }

        return $this->thumbnail_url ?: null;
    }

    protected static function booted(): void
    {
        static::creating(function (StudyMaterial $material) {
            if (empty($material->uuid)) {
                $material->uuid = (string) Str::uuid();
            }
            if (empty($material->slug)) {
                $material->slug = Str::slug($material->title).'-'.Str::random(6);
            }
            if (empty($material->share_token)) {
                $material->share_token = Str::random(40);
            }
            if (auth()->check() && empty($material->created_by)) {
                $material->created_by = auth()->id();
            }
        });

        static::updating(function (StudyMaterial $material) {
            if (auth()->check()) {
                $material->updated_by = auth()->id();
            }
        });

        static::deleting(function (StudyMaterial $material) {
            if (auth()->check() && ! $material->isForceDeleting() && empty($material->deleted_by)) {
                $material->deleted_by = auth()->id();
                $material->saveQuietly();
            }
        });
    }

    public static function materialTypes(): array
    {
        $dynamic = StudyMaterialType::activeOptions();

        if (! empty($dynamic)) {
            return $dynamic;
        }

        return [
            self::TYPE_CURRENT_AFFAIRS => __('Monthly Current Affairs'),
            self::TYPE_PIB_SANDHAN => __('PIB Sandhan (In-House Initiative of SNTCSSC)'),
            self::TYPE_LECTURE_NOTES => __('Lecture Notes / Handouts'),
            self::TYPE_E_BOOK_PDF => __('E-Book / Reference PDF'),
            self::TYPE_SLIDE_DECK => __('Presentation / Slide Deck'),
            self::TYPE_VIDEO_RECORDING => __('Video Lecture / Recording'),
            self::TYPE_AUDIO_LECTURE => __('Audio Lecture / Podcast'),
            self::TYPE_QUESTION_BANK => __('Question Bank / Mock Test'),
            self::TYPE_SYLLABUS => __('Syllabus & Blueprint'),
            self::TYPE_ASSIGNMENT_PRACTICE => __('Assignment / Worksheet'),
            self::TYPE_HANDOUT_REFERENCE => __('Compendium / Reference'),
        ];
    }

    public function typeDefinition(): BelongsTo
    {
        return $this->belongsTo(StudyMaterialType::class, 'material_type', 'code');
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    public function batches(): BelongsToMany
    {
        return $this->belongsToMany(Batch::class, 'study_material_batches')
            ->withTimestamps();
    }

    public function materialBatches(): HasMany
    {
        return $this->hasMany(StudyMaterialBatch::class);
    }

    public function files(): HasMany
    {
        return $this->hasMany(StudyMaterialFile::class)->orderBy('display_order')->orderBy('id');
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'study_material_students')
            ->withTimestamps();
    }

    public function shares(): HasMany
    {
        return $this->hasMany(StudyMaterialShare::class)->latest();
    }

    public function accessLogs(): HasMany
    {
        return $this->hasMany(StudyMaterialAccessLog::class)->latest();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    // Scopes
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PUBLISHED);
    }

    public function scopePinned(Builder $query): Builder
    {
        return $query->where('is_pinned', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderByDesc('is_pinned')
            ->orderBy('display_order')
            ->orderByDesc('class_date')
            ->latest('id');
    }

    /**
     * Scope query to materials accessible by a specific user.
     */
    public function scopeForUser(Builder $query, User $user): Builder
    {
        // Admin or staff with edit/manage permission can view everything
        if ($user->can('study_materials.edit') || $user->hasRole(['Super Administrator', 'Administrator', 'Faculty', 'Staff'])) {
            return $query;
        }

        // If user has no permission to view study materials, restrict strictly to public publications
        if (! $user->can('study_materials.view') && ! $user->hasRole('Super Administrator')) {
            return $query->where('status', self::STATUS_PUBLISHED)
                ->where('access_level', '!=', self::ACCESS_RESTRICTED_STUDENTS)
                ->where(function (Builder $q) {
                    $q->where('access_level', self::ACCESS_PUBLIC_LINK)
                        ->orWhereNull('course_id')
                        ->orWhereIn('material_type', [self::TYPE_CURRENT_AFFAIRS, self::TYPE_PIB_SANDHAN])
                        ->orWhere(function (Builder $pub) {
                            $pub->where('access_level', '!=', self::ACCESS_ENROLLED_ONLY)
                                ->whereHas('typeDefinition', fn ($td) => $td->where('is_public', true));
                        });
                });
        }

        // For student, determine enrolled courses and batches
        $enrollments = Enrollment::where('user_id', $user->id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->get();

        $enrolledCourseIds = $enrollments->pluck('course_id')->unique()->values()->all();
        $enrolledBatchIds = $enrollments->pluck('batch_id')->unique()->values()->all();

        return $query->where('status', self::STATUS_PUBLISHED)
            ->where(function (Builder $q) use ($user, $enrolledCourseIds, $enrolledBatchIds) {
                // 1. Matched course and batch
                $q->where(function (Builder $sub) use ($enrolledCourseIds, $enrolledBatchIds) {
                    $sub->whereIn('course_id', $enrolledCourseIds)
                        ->where(function (Builder $batchSub) use ($enrolledBatchIds) {
                            // Either linked to student's batch, OR not linked to any specific batch (applies to all batches of course)
                            $batchSub->whereHas('batches', fn ($b) => $b->whereIn('batches.id', $enrolledBatchIds))
                                ->orDoesntHave('batches');
                        });
                })
                // 2. Or specifically targeted to this student
                    ->orWhereHas('students', fn ($s) => $s->where('users.id', $user->id))
                // 3. Or publicly accessible without enrollment restrictions (public link, general publication, or public type)
                    ->orWhere(function (Builder $pub) {
                        $pub->where('access_level', '!=', self::ACCESS_RESTRICTED_STUDENTS)
                            ->where(function (Builder $inner) {
                                $inner->where('access_level', self::ACCESS_PUBLIC_LINK)
                                    ->orWhereNull('course_id')
                                    ->orWhereIn('material_type', [self::TYPE_CURRENT_AFFAIRS, self::TYPE_PIB_SANDHAN])
                                    ->orWhere(function (Builder $tdQuery) {
                                        $tdQuery->where('access_level', '!=', self::ACCESS_ENROLLED_ONLY)
                                            ->whereHas('typeDefinition', fn ($td) => $td->where('is_public', true));
                                    });
                            });
                    });
            });
    }

    // Accessors & Helpers
    public function getMaterialTypeLabelAttribute(): string
    {
        return self::materialTypes()[$this->material_type] ?? ucfirst(str_replace('_', ' ', $this->material_type));
    }

    public function getMaterialTypeIconAttribute(): string
    {
        if ($this->relationLoaded('typeDefinition') && $this->typeDefinition?->icon) {
            return $this->typeDefinition->icon;
        }

        return match ($this->material_type) {
            self::TYPE_CURRENT_AFFAIRS => 'newspaper',
            self::TYPE_PIB_SANDHAN => 'book-marked',
            self::TYPE_LECTURE_NOTES => 'file-text',
            self::TYPE_E_BOOK_PDF => 'book-open',
            self::TYPE_SLIDE_DECK => 'presentation',
            self::TYPE_VIDEO_RECORDING => 'video',
            self::TYPE_AUDIO_LECTURE => 'headphones',
            self::TYPE_QUESTION_BANK => 'clipboard-check',
            self::TYPE_SYLLABUS => 'bookmark',
            self::TYPE_ASSIGNMENT_PRACTICE => 'edit-3',
            self::TYPE_HANDOUT_REFERENCE => 'folder',
            default => 'file-text',
        };
    }

    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            self::STATUS_PUBLISHED => 'emerald',
            self::STATUS_DRAFT => 'amber',
            self::STATUS_ARCHIVED => 'zinc',
            default => 'slate',
        };
    }

    public function getBatchesSummaryAttribute(): string
    {
        if ($this->batches->isEmpty()) {
            return __('All Batches');
        }

        return $this->batches->pluck('name')->implode(', ');
    }

    public function getPrimaryFileAttribute(): ?StudyMaterialFile
    {
        return $this->files->first();
    }

    public function getTotalFilesCountAttribute(): int
    {
        return $this->files->count();
    }

    public function getTotalFilesSizeAttribute(): int
    {
        return (int) $this->files->sum('file_size');
    }

    public function getFormattedTotalSizeAttribute(): string
    {
        $bytes = $this->total_files_size;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $power), 2).' '.($units[$power] ?? 'B');
    }

    public function getPublicShareUrlAttribute(): string
    {
        return route('study-materials.public-share', ['token' => $this->share_token]);
    }

    public function isPublic(): bool
    {
        return $this->isPubliclyAvailable();
    }

    public function isPubliclyAvailable(): bool
    {
        // 1. Explicitly restricted to specific individual students: never public
        if ($this->access_level === self::ACCESS_RESTRICTED_STUDENTS) {
            return false;
        }

        // 2. Explicitly made public via unexpired share link
        if ($this->access_level === self::ACCESS_PUBLIC_LINK && ! $this->isExpired()) {
            return true;
        }

        // 3. Open daily/monthly publications (Current Affairs and PIB Sandhan)
        if (in_array($this->material_type, [self::TYPE_CURRENT_AFFAIRS, self::TYPE_PIB_SANDHAN], true)) {
            return true;
        }

        // 4. Materials tied to a course with enrolled_only access are never public
        if ($this->access_level === self::ACCESS_ENROLLED_ONLY && $this->course_id !== null) {
            return false;
        }

        // 5. Standalone institutional publications not tied to any course
        if ($this->course_id === null) {
            return true;
        }

        // 6. Dynamic type definition marked as public
        $typeDef = $this->relationLoaded('typeDefinition') ? $this->typeDefinition : $this->typeDefinition;
        if ($typeDef && $typeDef->is_public) {
            return true;
        }

        return false;
    }

    public function isExpired(): bool
    {
        return $this->share_expires_at && $this->share_expires_at->isPast();
    }

    public function isAccessibleBy(?User $user): bool
    {
        if ($user && ($user->can('study_materials.edit') || $user->hasRole(['Super Administrator', 'Administrator', 'Faculty', 'Staff']))) {
            return true;
        }

        if ($this->status !== self::STATUS_PUBLISHED) {
            return false;
        }

        // Publicly available materials can be accessed and downloaded by anyone (guests & students) without restriction
        if ($this->isPubliclyAvailable()) {
            return true;
        }

        if (! $user) {
            return false;
        }

        if (! $user->can('study_materials.view') && ! $user->hasRole('Super Administrator')) {
            return false;
        }

        if ($this->students()->where('users.id', $user->id)->exists()) {
            return true;
        }

        // If no course assigned, and not public, return false
        if (! $this->course_id) {
            return false;
        }

        // Check enrollment in course & batch
        $activeEnrollment = Enrollment::where('user_id', $user->id)
            ->where('course_id', $this->course_id)
            ->where('status', Enrollment::STATUS_ACTIVE)
            ->where(function ($q) {
                if ($this->batches()->exists()) {
                    $q->whereIn('batch_id', $this->batches()->pluck('batches.id'));
                }
            })
            ->exists();

        return $activeEnrollment;
    }
}
