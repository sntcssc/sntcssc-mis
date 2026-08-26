<?php

namespace App\Models;

use App\Services\FileUploadService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class ChatMessageAttachment extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'uuid',
        'message_id',
        'file_name',
        'file_path',
        'file_type',
        'file_size',
        'file_extension',
        'metadata',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $attachment) {
            if (empty($attachment->uuid)) {
                $attachment->uuid = (string) Str::uuid();
            }
        });
    }

    protected function casts(): array
    {
        return [
            'file_size' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'message_id');
    }

    public function url(): string
    {
        return FileUploadService::url($this->file_path);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->file_type, 'image/');
    }

    public function isAudio(): bool
    {
        return str_starts_with($this->file_type, 'audio/');
    }

    public function isVideo(): bool
    {
        return str_starts_with($this->file_type, 'video/');
    }

    public function isPdf(): bool
    {
        return $this->file_type === 'application/pdf' || strtolower($this->file_extension ?? '') === 'pdf';
    }

    public function isDocument(): bool
    {
        return ! $this->isImage() && ! $this->isAudio() && ! $this->isVideo();
    }

    public function formattedSize(): string
    {
        $bytes = $this->file_size;
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
