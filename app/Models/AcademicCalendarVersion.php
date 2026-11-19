<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $academic_calendar_id
 * @property string $version_code
 * @property int $revision_number
 * @property string $version_title
 * @property string $file_path
 * @property string $file_name
 * @property int $file_size
 * @property string|null $file_mime
 * @property string|null $change_summary
 * @property string|null $approved_by
 * @property Carbon|null $approval_date
 * @property Carbon|null $effective_from
 * @property bool $is_current
 * @property int $download_count
 * @property int|null $uploaded_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read AcademicCalendar $academicCalendar
 * @property-read Collection<int, AcademicCalendarActivity> $activities
 * @property-read User|null $uploader
 */
class AcademicCalendarVersion extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'academic_calendar_versions';

    protected $fillable = [
        'academic_calendar_id',
        'version_code',
        'revision_number',
        'version_title',
        'file_path',
        'file_name',
        'file_size',
        'file_mime',
        'change_summary',
        'approved_by',
        'approval_date',
        'effective_from',
        'is_current',
        'download_count',
        'uploaded_by',
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
            'approval_date' => 'date',
            'effective_from' => 'date',
            'is_current' => 'boolean',
            'revision_number' => 'integer',
            'file_size' => 'integer',
            'download_count' => 'integer',
        ];
    }

    /**
     * Belongs to master academic calendar.
     *
     * @return BelongsTo<AcademicCalendar, $this>
     */
    public function academicCalendar(): BelongsTo
    {
        return $this->belongsTo(AcademicCalendar::class, 'academic_calendar_id');
    }

    /**
     * Activities specifically introduced or updated in this revision.
     *
     * @return HasMany<AcademicCalendarActivity, $this>
     */
    public function activities(): HasMany
    {
        return $this->hasMany(AcademicCalendarActivity::class, 'academic_calendar_version_id');
    }

    /**
     * User who uploaded this version file.
     *
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Human-readable file size (e.g. 1.25 MB).
     */
    public function getHumanFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = (int) floor(log($bytes, 1024));

        return round($bytes / (1024 ** $i), 2).' '.$units[$i];
    }

    /**
     * Check if the version document is a PDF.
     */
    public function getIsPdfAttribute(): bool
    {
        return strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION)) === 'pdf'
            || str_contains($this->file_mime ?? '', 'pdf');
    }

    /**
     * File extension.
     */
    public function getFileExtensionAttribute(): string
    {
        return strtolower(pathinfo($this->file_name, PATHINFO_EXTENSION) ?: 'file');
    }

    /**
     * Storage download URL or route.
     */
    public function getFileUrlAttribute(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }

        if (Storage::disk('public')->exists($this->file_path)) {
            return Storage::disk('public')->url($this->file_path);
        }

        return null;
    }

    /**
     * Increment download counter safely.
     */
    public function incrementDownloads(): void
    {
        $this->timestamps = false;
        $this->increment('download_count');
        $this->timestamps = true;
    }
}
