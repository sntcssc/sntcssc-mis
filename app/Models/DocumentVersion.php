<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $document_id
 * @property string $version_number
 * @property int $revision_sequence
 * @property string|null $version_title
 * @property string $file_path
 * @property string $original_file_name
 * @property string $file_type
 * @property string $mime_type
 * @property int $file_size
 * @property string|null $file_hash
 * @property string|null $change_summary
 * @property string|null $change_description
 * @property string|null $remarks
 * @property bool $is_current
 * @property int $download_count
 * @property int|null $uploaded_by
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $effective_date
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read Document $document
 * @property-read User|null $uploader
 * @property-read User|null $creator
 */
class DocumentVersion extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'document_versions';

    protected $fillable = [
        'document_id',
        'version_number',
        'revision_sequence',
        'version_title',
        'file_path',
        'original_file_name',
        'file_type',
        'mime_type',
        'file_size',
        'file_hash',
        'change_summary',
        'change_description',
        'remarks',
        'is_current',
        'download_count',
        'uploaded_by',
        'created_by',
        'updated_by',
        'deleted_by',
        'effective_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_current' => 'boolean',
            'revision_sequence' => 'integer',
            'file_size' => 'integer',
            'download_count' => 'integer',
            'effective_date' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Format file size in human readable unit.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        $bytes = $this->file_size;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $i), 2).' '.$units[$i];
    }

    /**
     * Determine Lucide icon for file type.
     */
    public function getFileTypeIconAttribute(): string
    {
        $type = strtolower($this->file_type);

        return match ($type) {
            'pdf' => 'file-text',
            'doc', 'docx', 'rtf', 'txt' => 'file-text',
            'xls', 'xlsx', 'csv' => 'file-spreadsheet',
            'ppt', 'pptx' => 'file-bar-chart',
            'jpg', 'jpeg', 'png', 'webp', 'svg', 'gif', 'bmp' => 'image',
            'zip', 'rar', '7z', 'tar', 'gz' => 'file-archive',
            default => 'file',
        };
    }

    /**
     * Determine UI badge styling for file type.
     *
     * @return array{badge_bg: string, badge_text: string, icon: string, label: string}
     */
    public function getFileTypeBadgeAttribute(): array
    {
        $type = strtoupper($this->file_type);

        return match (strtolower($this->file_type)) {
            'pdf' => [
                'badge_bg' => 'bg-red-500/10 dark:bg-red-500/20 border-red-500/30',
                'badge_text' => 'text-red-700 dark:text-red-400',
                'icon' => 'file-text',
                'label' => 'PDF',
            ],
            'doc', 'docx' => [
                'badge_bg' => 'bg-blue-500/10 dark:bg-blue-500/20 border-blue-500/30',
                'badge_text' => 'text-blue-700 dark:text-blue-400',
                'icon' => 'file-text',
                'label' => 'Word',
            ],
            'xls', 'xlsx', 'csv' => [
                'badge_bg' => 'bg-emerald-500/10 dark:bg-emerald-500/20 border-emerald-500/30',
                'badge_text' => 'text-emerald-700 dark:text-emerald-400',
                'icon' => 'file-spreadsheet',
                'label' => $type === 'CSV' ? 'CSV' : 'Excel',
            ],
            'ppt', 'pptx' => [
                'badge_bg' => 'bg-amber-500/10 dark:bg-amber-500/20 border-amber-500/30',
                'badge_text' => 'text-amber-700 dark:text-amber-400',
                'icon' => 'file-bar-chart',
                'label' => 'PowerPoint',
            ],
            'jpg', 'jpeg', 'png', 'webp', 'svg', 'gif' => [
                'badge_bg' => 'bg-purple-500/10 dark:bg-purple-500/20 border-purple-500/30',
                'badge_text' => 'text-purple-700 dark:text-purple-400',
                'icon' => 'image',
                'label' => 'Image',
            ],
            'zip', 'rar', '7z', 'tar', 'gz' => [
                'badge_bg' => 'bg-orange-500/10 dark:bg-orange-500/20 border-orange-500/30',
                'badge_text' => 'text-orange-700 dark:text-orange-400',
                'icon' => 'file-archive',
                'label' => 'Archive',
            ],
            default => [
                'badge_bg' => 'bg-secondary border-border',
                'badge_text' => 'text-foreground',
                'icon' => 'file',
                'label' => $type ?: 'FILE',
            ],
        };
    }

    /**
     * Verify whether file exists in public storage disk.
     */
    public function storageExists(): bool
    {
        return Storage::disk('public')->exists($this->file_path);
    }
}
