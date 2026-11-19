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
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property string $document_number
 * @property string $title
 * @property string|null $purpose
 * @property int|null $document_category_id
 * @property string|null $department
 * @property array<string>|null $tags
 * @property string $access_level
 * @property string $status
 * @property string|null $description
 * @property string|null $notes
 * @property string|null $remarks
 * @property int|null $current_version_id
 * @property int $total_versions_count
 * @property int $total_downloads_count
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read DocumentCategory|null $category
 * @property-read DocumentVersion|null $currentVersion
 * @property-read Collection<int, DocumentVersion> $versions
 * @property-read User|null $creator
 * @property-read User|null $updater
 * @property-read User|null $deleter
 */
class Document extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'documents';

    protected $fillable = [
        'document_number',
        'title',
        'purpose',
        'document_category_id',
        'department',
        'tags',
        'access_level',
        'status',
        'description',
        'notes',
        'remarks',
        'current_version_id',
        'total_versions_count',
        'total_downloads_count',
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
            'tags' => 'array',
            'total_versions_count' => 'integer',
            'total_downloads_count' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Document $document) {
            if (empty($document->document_number)) {
                $document->document_number = static::generateDocumentNumber();
            }
        });
    }

    /**
     * Generate unique document number (e.g. DOC-2026-0001).
     */
    public static function generateDocumentNumber(): string
    {
        $year = date('Y');
        $prefix = "DOC-{$year}-";

        $lastDoc = static::withTrashed()
            ->where('document_number', 'like', "{$prefix}%")
            ->orderBy('id', 'desc')
            ->first();

        $nextSeq = 1;
        if ($lastDoc && preg_match('/DOC-\d{4}-(\d+)/', $lastDoc->document_number, $matches)) {
            $nextSeq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s%04d', $prefix, $nextSeq);
    }

    /**
     * @return BelongsTo<DocumentCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(DocumentCategory::class, 'document_category_id');
    }

    /**
     * @return BelongsTo<DocumentVersion, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }

    /**
     * All versions ordered by descending revision sequence.
     *
     * @return HasMany<DocumentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')
            ->orderBy('revision_sequence', 'desc');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    /**
     * Atomically switch current active version.
     */
    public function setCurrentVersion(DocumentVersion $version): void
    {
        DB::transaction(function () use ($version) {
            // Mark all versions as not current
            DocumentVersion::where('document_id', $this->id)->update(['is_current' => false]);

            // Set chosen version as current
            DocumentVersion::where('id', $version->id)->update(['is_current' => true]);
            $version->refresh();

            // Update parent document pointer
            $this->update([
                'current_version_id' => $version->id,
                'total_versions_count' => $this->versions()->count(),
            ]);
        });
    }

    /**
     * Format current version size.
     */
    public function getFormattedFileSizeAttribute(): string
    {
        if (! $this->currentVersion) {
            return '0 B';
        }

        return $this->currentVersion->formatted_file_size;
    }

    /**
     * Scope search query across fields.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = trim($term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            $q->where('title', 'like', "%{$term}%")
                ->orWhere('document_number', 'like', "%{$term}%")
                ->orWhere('purpose', 'like', "%{$term}%")
                ->orWhere('department', 'like', "%{$term}%")
                ->orWhere('description', 'like', "%{$term}%")
                ->orWhere('notes', 'like', "%{$term}%")
                ->orWhere('remarks', 'like', "%{$term}%")
                ->orWhereJsonContains('tags', $term)
                ->orWhereHas('versions', function (Builder $vq) use ($term) {
                    $vq->where('original_file_name', 'like', "%{$term}%")
                        ->orWhere('version_number', 'like', "%{$term}%")
                        ->orWhere('version_title', 'like', "%{$term}%")
                        ->orWhere('change_summary', 'like', "%{$term}%");
                });
        });
    }

    /**
     * Scope for category filter.
     *
     * @param  Builder<Document>  $query
     * @param  int|string|null  $categoryId
     * @return Builder<Document>
     */
    public function scopeCategory(Builder $query, mixed $categoryId): Builder
    {
        if (empty($categoryId)) {
            return $query;
        }

        return $query->where('document_category_id', $categoryId);
    }

    /**
     * Scope for department filter.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeDepartment(Builder $query, ?string $department): Builder
    {
        if (empty($department)) {
            return $query;
        }

        return $query->where('department', $department);
    }

    /**
     * Scope for file type filter (checked against current version).
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeFileType(Builder $query, ?string $fileType): Builder
    {
        if (empty($fileType)) {
            return $query;
        }

        return $query->whereHas('currentVersion', function (Builder $q) use ($fileType) {
            $q->where('file_type', strtolower($fileType));
        });
    }

    /**
     * Scope for access level filter.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeAccessLevel(Builder $query, ?string $level): Builder
    {
        if (empty($level)) {
            return $query;
        }

        return $query->where('access_level', $level);
    }

    /**
     * Scope for status filter.
     *
     * @param  Builder<Document>  $query
     * @return Builder<Document>
     */
    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        if (empty($status)) {
            return $query;
        }

        return $query->where('status', $status);
    }
}
