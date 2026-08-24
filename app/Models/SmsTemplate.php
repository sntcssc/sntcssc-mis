<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $category
 * @property string|null $sender_id
 * @property string|null $dlt_template_id
 * @property string $body
 * @property array<string>|null $variables
 * @property bool $status
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property int|null $deleted_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property-read User|null $creator
 * @property-read User|null $editor
 * @property-read User|null $deleter
 */
class SmsTemplate extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    public const CATEGORY_OTP = 'otp';

    public const CATEGORY_NOTIFICATION = 'notification';

    public const CATEGORY_NOTICE = 'notice';

    public const CATEGORY_COMMUNICATION = 'communication';

    public const CATEGORY_PROMOTIONAL = 'promotional';

    public const CATEGORIES = [
        self::CATEGORY_OTP => 'OTP & Security',
        self::CATEGORY_NOTIFICATION => 'Notifications',
        self::CATEGORY_NOTICE => 'Notice Board',
        self::CATEGORY_COMMUNICATION => 'Communications',
        self::CATEGORY_PROMOTIONAL => 'Promotional',
    ];

    protected $fillable = [
        'code',
        'name',
        'category',
        'sender_id',
        'dlt_template_id',
        'body',
        'variables',
        'status',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'status' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function deleter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', true);
    }

    public function scopeCategory(Builder $query, string $category): Builder
    {
        return $query->where('category', $category);
    }

    /**
     * Render the template body with provided variable values.
     *
     * @param  array<string, mixed>  $variables
     */
    public function render(array $variables = []): string
    {
        $rendered = $this->body;

        foreach ($variables as $key => $value) {
            $valueStr = (string) $value;
            $rendered = str_replace([
                '{'.$key.'}',
                '{{'.$key.'}}',
                '{#'.$key.'#}',
            ], $valueStr, $rendered);
        }

        return $rendered;
    }
}
