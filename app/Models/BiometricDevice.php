<?php

namespace App\Models;

use App\Concerns\Auditable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class BiometricDevice extends Model
{
    use Auditable, HasFactory, SoftDeletes;

    protected $fillable = [
        'device_name',
        'device_serial_number',
        'device_ip',
        'port',
        'location',
        'device_type',
        'api_token',
        'status',
        'last_heartbeat_at',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
            'last_heartbeat_at' => 'datetime',
        ];
    }

    public static function booted(): void
    {
        static::creating(function (BiometricDevice $device) {
            if (empty($device->api_token)) {
                $device->api_token = 'bio_'.Str::random(40);
            }
            if (empty($device->device_serial_number)) {
                $device->device_serial_number = 'DEV-'.strtoupper(Str::random(10));
            }
        });
    }

    public function logs(): HasMany
    {
        return $this->hasMany(BiometricLog::class);
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOnline(Builder $query): Builder
    {
        return $query->where('status', 'online');
    }

    public function statusBadgeClass(): string
    {
        return match ($this->status) {
            'online' => 'bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 border-emerald-500/20',
            'offline' => 'bg-rose-500/10 text-rose-600 dark:text-rose-400 border-rose-500/20',
            default => 'bg-amber-500/10 text-amber-600 dark:text-amber-400 border-amber-500/20',
        };
    }
}
