<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

class AuditLogService
{
    /**
     * Log a general system or user action.
     */
    public static function log(
        string $event,
        ?string $description = null,
        ?Model $auditable = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?int $userId = null,
        ?int $teamId = null
    ): ?AuditLog {
        try {
            $user = Auth::user();
            $userId = $userId ?? Auth::id();
            $teamId = $teamId ?? (property_exists($user ?? (object) [], 'current_team_id') ? $user?->current_team_id : null);

            $ip = Request::ip();
            $userAgent = Request::header('User-Agent');
            $url = Request::fullUrl();
            $method = Request::method();

            return AuditLog::create([
                'user_id' => $userId,
                'team_id' => $teamId,
                'event' => $event,
                'auditable_type' => $auditable ? get_class($auditable) : null,
                'auditable_id' => $auditable?->getKey(),
                'ip_address' => $ip,
                'user_agent' => $userAgent ? mb_substr($userAgent, 0, 500) : null,
                'url' => $url ? mb_substr($url, 0, 500) : null,
                'method' => $method,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'description' => $description,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to record audit log: '.$e->getMessage(), ['exception' => $e]);

            return null;
        }
    }

    /**
     * Log an Eloquent model lifecycle event.
     */
    public static function logModelEvent(
        string $event,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null
    ): ?AuditLog {
        $className = class_basename($model);
        $key = $model->getKey();

        $description = match ($event) {
            'created' => "Created {$className} #{$key}",
            'updated' => "Updated {$className} #{$key}",
            'deleted' => "Deleted {$className} #{$key}",
            'restored' => "Restored {$className} #{$key}",
            default => "{$event} {$className} #{$key}",
        };

        return static::log(
            event: $event,
            description: $description,
            auditable: $model,
            oldValues: $oldValues,
            newValues: $newValues
        );
    }

    /**
     * Prune old audit logs.
     */
    public static function prune(int $daysOld = 90): int
    {
        return AuditLog::query()
            ->where('created_at', '<', now()->subDays($daysOld))
            ->delete();
    }
}
