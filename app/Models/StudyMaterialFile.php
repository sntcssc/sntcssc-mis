<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;

/**
 * @property int $id
 * @property int $study_material_id
 * @property string $file_name
 * @property string $file_path
 * @property int $file_size
 * @property string|null $file_type
 * @property string|null $mime_type
 * @property string $disk
 * @property int $download_count
 * @property int $display_order
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read StudyMaterial $studyMaterial
 */
class StudyMaterialFile extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'study_material_id',
        'file_name',
        'file_path',
        'file_size',
        'file_type',
        'mime_type',
        'disk',
        'download_count',
        'display_order',
    ];

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'download_count' => 'integer',
            'display_order' => 'integer',
        ];
    }

    public function studyMaterial(): BelongsTo
    {
        return $this->belongsTo(StudyMaterial::class);
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = (int) $this->file_size;
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = floor(log($bytes, 1024));

        return round($bytes / pow(1024, $power), 2).' '.($units[$power] ?? 'B');
    }

    public function getFileIconAttribute(): string
    {
        $ext = strtolower($this->file_type ?? pathinfo($this->file_name, PATHINFO_EXTENSION));

        return match ($ext) {
            'pdf' => 'file-text',
            'doc', 'docx' => 'file-text',
            'xls', 'xlsx', 'csv' => 'file-spreadsheet',
            'ppt', 'pptx' => 'presentation',
            'zip', 'rar', '7z', 'tar', 'gz' => 'archive',
            'jpg', 'jpeg', 'png', 'webp', 'gif', 'svg' => 'image',
            'mp4', 'mkv', 'avi', 'mov', 'webm' => 'video',
            'mp3', 'wav', 'aac', 'ogg', 'm4a' => 'volume-2',
            default => 'file',
        };
    }

    public function getDownloadUrlAttribute(): string
    {
        return route('study-materials.download', [
            'studyMaterial' => $this->study_material_id,
            'fileIndex' => $this->id,
        ]);
    }

    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk ?? 'public')->exists($this->file_path);
    }
}
