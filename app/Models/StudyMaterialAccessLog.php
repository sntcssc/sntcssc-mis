<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $study_material_id
 * @property int|null $study_material_file_id
 * @property int|null $user_id
 * @property int|null $subscriber_id
 * @property string $action
 * @property string|null $guest_name
 * @property string|null $guest_email
 * @property string|null $guest_phone
 * @property bool $is_verified
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StudyMaterial $studyMaterial
 * @property-read StudyMaterialFile|null $file
 * @property-read User|null $user
 * @property-read Subscriber|null $subscriber
 */
class StudyMaterialAccessLog extends Model
{
    use HasFactory;

    public const ACTION_VIEW = 'view';

    public const ACTION_DOWNLOAD = 'download';

    public const ACTION_EXTERNAL_CLICK = 'external_click';

    protected $fillable = [
        'study_material_id',
        'study_material_file_id',
        'user_id',
        'subscriber_id',
        'action',
        'guest_name',
        'guest_email',
        'guest_phone',
        'is_verified',
        'ip_address',
        'user_agent',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'is_verified' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function studyMaterial(): BelongsTo
    {
        return $this->belongsTo(StudyMaterial::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StudyMaterialFile::class, 'study_material_file_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }
}
