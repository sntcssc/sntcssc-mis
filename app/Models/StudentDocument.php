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
 * @property int $user_id
 * @property string $type
 * @property string $file_path
 * @property string|null $file_name
 * @property string|null $file_hash
 * @property bool $is_active
 * @property bool $verified
 * @property int|null $verified_by
 * @property Carbon|null $verified_at
 * @property Carbon|null $uploaded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User $user
 * @property-read User|null $verifier
 */
class StudentDocument extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $table = 'student_documents';

    protected $fillable = [
        'user_id',
        'type',
        'document_type',
        'file_path',
        'file_url',
        'file_name',
        'file_hash',
        'is_active',
        'verified',
        'verified_by',
        'verified_at',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'verified' => 'boolean',
            'verified_at' => 'datetime',
            'uploaded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    public function getDocumentTypeAttribute(): ?string
    {
        return $this->type;
    }

    public function setDocumentTypeAttribute(?string $value): void
    {
        $this->attributes['type'] = $value;
    }

    public function getVerificationStatusAttribute(): string
    {
        return $this->verified ? 'verified' : 'pending';
    }
}
