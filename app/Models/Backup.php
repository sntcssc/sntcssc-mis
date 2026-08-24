<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * @property int $id
 * @property string $uuid
 * @property string $filename
 * @property string $disk
 * @property string $path
 * @property string $type
 * @property string $db_driver
 * @property int $size_bytes
 * @property int $tables_count
 * @property int $records_count
 * @property int $files_count
 * @property string|null $checksum
 * @property string $trigger_type
 * @property string $status
 * @property string|null $error_message
 * @property float $duration_seconds
 * @property array<string, mixed>|null $metadata
 * @property bool $email_sent
 * @property string|null $email_recipient
 * @property int|null $created_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $creator
 * @property-read User|null $deletedByUser
 */
class Backup extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const TYPE_DATABASE_ONLY = 'database_only';

    public const TYPE_FULL_WITH_MEDIA = 'full_with_media';

    public const TRIGGER_MANUAL = 'manual';

    public const TRIGGER_SCHEDULED = 'scheduled';

    public const TRIGGER_PRE_RESTORE = 'pre_restore';

    public const TRIGGER_API = 'api';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const STATUS_RUNNING = 'running';

    public const STATUS_RESTORED = 'restored';

    protected $fillable = [
        'uuid',
        'filename',
        'disk',
        'path',
        'type',
        'db_driver',
        'size_bytes',
        'tables_count',
        'records_count',
        'files_count',
        'checksum',
        'trigger_type',
        'status',
        'error_message',
        'duration_seconds',
        'metadata',
        'email_sent',
        'email_recipient',
        'created_by',
        'deleted_by',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'tables_count' => 'integer',
        'records_count' => 'integer',
        'files_count' => 'integer',
        'duration_seconds' => 'float',
        'metadata' => 'array',
        'email_sent' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (Backup $backup) {
            if (empty($backup->uuid)) {
                $backup->uuid = (string) Str::uuid();
            }

            if (auth()->check() && empty($backup->created_by)) {
                $backup->created_by = auth()->id();
            }
        });

        static::deleting(function (Backup $backup) {
            if (auth()->check() && ! $backup->isForceDeleting() && empty($backup->deleted_by)) {
                $backup->deleted_by = auth()->id();
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function deletedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Determine if physical file exists on configured disk.
     */
    public function existsOnDisk(): bool
    {
        return Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Get the absolute filesystem path to the backup file.
     */
    public function absolutePath(): string
    {
        return Storage::disk($this->disk)->path($this->path);
    }

    /**
     * Get human-readable formatted file size (KB, MB, GB).
     */
    public function formattedSize(): string
    {
        $bytes = $this->size_bytes;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    /**
     * Get badge color representation for backup status.
     */
    public function statusBadgeColor(): string
    {
        return match ($this->status) {
            self::STATUS_COMPLETED => 'emerald',
            self::STATUS_FAILED => 'rose',
            self::STATUS_RUNNING => 'sky',
            self::STATUS_RESTORED => 'indigo',
            default => 'secondary',
        };
    }

    /**
     * Get readable label for backup type.
     */
    public function typeLabel(): string
    {
        return match ($this->type) {
            self::TYPE_FULL_WITH_MEDIA => __('Full (DB + Media Files)'),
            self::TYPE_DATABASE_ONLY => __('Database Schema & Data'),
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }

    /**
     * Get readable label for trigger type.
     */
    public function triggerLabel(): string
    {
        return match ($this->trigger_type) {
            self::TRIGGER_MANUAL => __('Manual Trigger'),
            self::TRIGGER_SCHEDULED => __('Scheduled Cron'),
            self::TRIGGER_PRE_RESTORE => __('Pre-Restore Snapshot'),
            self::TRIGGER_API => __('API Dispatched'),
            default => ucfirst($this->trigger_type),
        };
    }

    /**
     * Check if backup file is a ZIP archive.
     */
    public function isZip(): bool
    {
        return str_ends_with(strtolower($this->filename), '.zip');
    }
}
