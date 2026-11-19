<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class ApiKeyLog extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'api_key_id',
        'api_key_name',
        'ip_address',
        'user_agent',
        'method',
        'endpoint',
        'status_code',
        'duration_ms',
        'request_headers',
        'request_payload',
        'response_summary',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'request_headers' => 'array',
            'request_payload' => 'array',
            'duration_ms' => 'float',
            'status_code' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function apiKey(): BelongsTo
    {
        return $this->belongsTo(ApiKey::class, 'api_key_id')->withTrashed();
    }

    public function isSuccessful(): bool
    {
        return $this->status_code >= 200 && $this->status_code < 300;
    }

    public function isClientError(): bool
    {
        return $this->status_code >= 400 && $this->status_code < 500;
    }

    public function isServerError(): bool
    {
        return $this->status_code >= 500;
    }

    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->whereBetween('status_code', [200, 299]);
    }

    public function scopeFailed(Builder $query): Builder
    {
        return $query->where('status_code', '>=', 400);
    }

    public function scopeRecent(Builder $query, int $days = 7): Builder
    {
        return $query->where('created_at', '>=', Carbon::now()->subDays($days));
    }
}
