<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BiometricLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'biometric_device_id',
        'biometric_pin',
        'card_number',
        'user_id',
        'punch_time',
        'punch_type',
        'raw_payload',
        'is_processed',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'punch_time' => 'datetime',
            'raw_payload' => 'array',
            'is_processed' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeUnprocessed(Builder $query): Builder
    {
        return $query->where('is_processed', false);
    }
}
