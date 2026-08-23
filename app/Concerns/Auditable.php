<?php

namespace App\Concerns;

use App\Models\AuditLog;
use App\Services\AuditLogService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            if (static::shouldAudit('created')) {
                AuditLogService::logModelEvent('created', $model, null, $model->auditableAttributes());
            }
        });

        static::updated(function (Model $model) {
            if (static::shouldAudit('updated')) {
                $changes = $model->getChanges();
                $original = $model->getOriginal();

                // Exclude timestamps from triggering audit alone
                $dirtyKeys = array_keys(array_diff_key($changes, array_flip(['updated_at', 'created_at'])));

                if (! empty($dirtyKeys)) {
                    $oldValues = collect($original)->only($dirtyKeys)->all();
                    $newValues = collect($changes)->only($dirtyKeys)->all();

                    // Mask sensitive fields
                    $oldValues = static::maskSensitiveFields($oldValues);
                    $newValues = static::maskSensitiveFields($newValues);

                    AuditLogService::logModelEvent('updated', $model, $oldValues, $newValues);
                }
            }
        });

        static::deleted(function (Model $model) {
            if (static::shouldAudit('deleted')) {
                AuditLogService::logModelEvent('deleted', $model, static::maskSensitiveFields($model->auditableAttributes()), null);
            }
        });

        if (method_exists(static::class, 'restored')) {
            static::restored(function (Model $model) {
                if (static::shouldAudit('restored')) {
                    AuditLogService::logModelEvent('restored', $model, null, static::maskSensitiveFields($model->auditableAttributes()));
                }
            });
        }
    }

    public function auditLogs(): MorphMany
    {
        return $this->morphMany(AuditLog::class, 'auditable')->latest();
    }

    public function auditableAttributes(): array
    {
        $attributes = $this->attributesToArray();

        return static::maskSensitiveFields($attributes);
    }

    protected static function maskSensitiveFields(array $attributes): array
    {
        $sensitive = [
            'password',
            'remember_token',
            'two_factor_secret',
            'two_factor_recovery_codes',
            'smtp_password',
            'razorpay_key_secret',
            'razorpay_webhook_secret',
            'phonepe_salt_key',
            'two_factor_api_key',
        ];

        foreach ($sensitive as $field) {
            if (array_key_exists($field, $attributes) && ! empty($attributes[$field])) {
                $attributes[$field] = '••••••••';
            }
        }

        return $attributes;
    }

    protected static function shouldAudit(string $event): bool
    {
        if (app()->runningInConsole() && ! in_array($_SERVER['argv'][1] ?? null, ['test', 'pest', 'serve', 'octane:start'], true)) {
            // Skip massive seeders during migrations unless explicitly in testing/web
            return false;
        }

        return true;
    }
}
