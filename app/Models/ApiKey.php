<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class ApiKey extends Model
{
    use HasFactory, SoftDeletes;

    public const TYPE_MOBILE_APP = 'mobile_app';

    public const TYPE_DESKTOP_APP = 'desktop_app';

    public const TYPE_EXTERNAL_APP = 'external_app';

    public const TYPE_SERVICE = 'service';

    public const TYPE_TESTING = 'testing';

    public const ENV_PRODUCTION = 'production';

    public const ENV_SANDBOX = 'sandbox';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_REVOKED = 'revoked';

    protected $fillable = [
        'uuid',
        'user_id',
        'name',
        'key_prefix',
        'key_hash',
        'type',
        'environment',
        'status',
        'scopes',
        'ip_allowlist',
        'allowed_domains',
        'rate_limit_per_minute',
        'expires_at',
        'last_used_at',
        'last_used_ip',
        'last_used_user_agent',
        'total_requests',
        'description',
        'metadata',
        'revoked_at',
        'revoked_by',
        'revocation_reason',
        'created_by',
        'updated_by',
        'deleted_by',
    ];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'ip_allowlist' => 'array',
            'allowed_domains' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at' => 'datetime',
            'total_requests' => 'integer',
            'rate_limit_per_minute' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ApiKey $model) {
            if (empty($model->uuid)) {
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Generate a new cryptographically secure API Key pair.
     *
     * @return array{plain_key: string, key_prefix: string, key_hash: string}
     */
    public static function generateSecret(string $environment = self::ENV_PRODUCTION): array
    {
        $prefixTag = ($environment === self::ENV_SANDBOX) ? 'sntc_test_' : 'sntc_live_';
        $randomSegment = Str::random(36);
        $plainKey = $prefixTag.$randomSegment;
        $keyPrefix = substr($plainKey, 0, 16).'...';
        $keyHash = hash('sha256', $plainKey);

        return [
            'plain_key' => $plainKey,
            'key_prefix' => $keyPrefix,
            'key_hash' => $keyHash,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function revoker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(ApiKeyLog::class, 'api_key_id');
    }

    public function isValid(): bool
    {
        return $this->status === self::STATUS_ACTIVE && ! $this->isExpired() && ! $this->isRevoked();
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isRevoked(): bool
    {
        return $this->status === self::STATUS_REVOKED || $this->revoked_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }

    /**
     * Check if this API Key has permission for a specific scope.
     */
    public function hasScope(string $requiredScope): bool
    {
        $scopes = $this->scopes ?? [];

        if (empty($scopes) || in_array('*', $scopes, true)) {
            return true;
        }

        if (in_array($requiredScope, $scopes, true)) {
            return true;
        }

        // Check namespace wildcards like "read:*" or "write:*"
        if (str_contains($requiredScope, ':')) {
            $parts = explode(':', $requiredScope, 2);
            $wildcard = $parts[0].':*';
            if (in_array($wildcard, $scopes, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if a client IP address is allowed under the IP allowlist.
     */
    public function allowsIp(?string $clientIp): bool
    {
        if (empty($this->ip_allowlist)) {
            return true;
        }

        if (! $clientIp) {
            return false;
        }

        foreach ($this->ip_allowlist as $allowed) {
            $allowed = trim((string) $allowed);
            if ($allowed === '') {
                continue;
            }

            if ($allowed === $clientIp || $allowed === '*') {
                return true;
            }

            // Check CIDR block (e.g. 192.168.1.0/24)
            if (str_contains($allowed, '/')) {
                [$subnet, $bits] = explode('/', $allowed, 2);
                if (filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($clientIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $subnetLong = ip2long($subnet);
                    $ipLong = ip2long($clientIp);
                    $mask = -1 << (32 - (int) $bits);
                    $subnetMasked = $subnetLong & $mask;
                    if (($ipLong & $mask) === $subnetMasked) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Record request usage telemetry.
     */
    public function recordUsage(string $ip, ?string $userAgent): void
    {
        $this->forceFill([
            'last_used_at' => Carbon::now(),
            'last_used_ip' => $ip,
            'last_used_user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
            'total_requests' => $this->total_requests + 1,
        ])->saveQuietly();
    }

    /**
     * Revoke this API key immediately.
     */
    public function revoke(?int $userId = null, ?string $reason = null): void
    {
        $this->update([
            'status' => self::STATUS_REVOKED,
            'revoked_at' => Carbon::now(),
            'revoked_by' => $userId,
            'revocation_reason' => $reason,
        ]);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE)
            ->where(function (Builder $q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            });
    }

    public function scopeSearch(Builder $query, ?string $search): Builder
    {
        if (! $search) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
                ->orWhere('key_prefix', 'like', "%{$search}%")
                ->orWhere('description', 'like', "%{$search}%");
        });
    }
}
