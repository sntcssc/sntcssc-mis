<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $study_material_id
 * @property string $channel
 * @property string $recipient_type
 * @property int|null $recipient_id
 * @property string|null $recipient_contact
 * @property string|null $recipient_name
 * @property string $status
 * @property string|null $error_message
 * @property int|null $shared_by
 * @property array|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read StudyMaterial $studyMaterial
 * @property-read User|null $sender
 */
class StudyMaterialShare extends Model
{
    use HasFactory;

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_EMAIL = 'email';

    public const CHANNEL_SMS = 'sms';

    public const CHANNEL_TELEGRAM = 'telegram';

    public const CHANNEL_IN_APP = 'in_app';

    public const CHANNEL_PUBLIC_LINK = 'public_link';

    protected $fillable = [
        'study_material_id',
        'channel',
        'recipient_type',
        'recipient_id',
        'recipient_contact',
        'recipient_name',
        'status',
        'error_message',
        'shared_by',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function studyMaterial(): BelongsTo
    {
        return $this->belongsTo(StudyMaterial::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shared_by');
    }
}
