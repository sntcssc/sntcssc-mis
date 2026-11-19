<?php

namespace App\Services\ApiKey;

use App\Models\ApiKey;
use App\Models\ApiKeyLog;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class ApiKeyService
{
    /**
     * Get structured catalog of available API Scopes.
     *
     * @return array<string, array<string, string>>
     */
    public static function getAvailableScopes(): array
    {
        return [
            'System & Diagnostic' => [
                'ping' => 'Basic API connectivity test & handshake',
                'read:system' => 'Read system health & application metadata',
            ],
            'Academics' => [
                'read:academics' => 'View courses, batches, sections, class routines, faculties, classrooms, syllabi & academic calendars',
                'write:academics' => 'Create and modify courses, batches, routines, faculties, classrooms, plans & academic calendars',
            ],
            'Documents & Archives' => [
                'read:documents' => 'View document directory, metadata, categories, and telemetry',
                'write:documents' => 'Upload documents, revisions, and update metadata',
                'download:documents' => 'Download document version files',
                'admin:documents' => 'Administrative operations: soft-delete, restore, rollback versions, and bulk import',
            ],
            'Students & Admissions' => [
                'read:students' => 'View student profiles, directory, and dossiers',
                'write:students' => 'Create or update student registration records',
            ],
            'Study Materials' => [
                'read:materials' => 'Access lecture notes, e-books, recordings, handouts',
                'write:materials' => 'Upload and publish new study materials',
                'download:materials' => 'Download study material file attachments',
            ],
            'Alumni Directory' => [
                'read:alumni' => 'Search and view alumni profiles and achievements',
                'write:alumni' => 'Submit and manage alumni profile dossiers',
            ],
            'Attendance & Biometrics' => [
                'read:attendance' => 'View attendance punches, shift calendars, holidays, and metrics',
                'write:attendance' => 'Mark, modify, and import attendance records and manage calendars',
                'write:biometric' => 'Push raw biometric punches and gate turnstile scans from hardware devices',
                'download:attendance' => 'Download and stream official printable attendance registers in CSV, Excel, or PDF',
            ],
            'Leave Management' => [
                'read:leaves' => 'View leave applications, categories, and balance allocations',
                'write:leaves' => 'Submit, approve, reject, or cancel leave applications and manage quotas',
            ],
            'Fees & Payments' => [
                'read:payments' => 'View fee structures, invoices, and transaction history',
                'write:payments' => 'Record fee collections and offline receipts',
                'download:receipts' => 'Generate and stream official fee receipt and tax invoice PDFs',
            ],
            'Library Management' => [
                'read:library' => 'Search books catalog, view circulation status, members & reports',
                'write:library' => 'Manage books, physical copies, checkouts, returns, renewals & fines',
                'download:library_cards' => 'Download and stream official printable student & staff library card PDFs',
            ],
            'Hostel Management' => [
                'read:hostel' => 'View hostel buildings, rooms, bed occupancy, allocations, and reports',
                'write:hostel' => 'Manage buildings, rooms, allocations, and process extension requests',
                'download:hostel_forms' => 'Download official personalized hostel admission forms and exports',
            ],
            'Examination Management' => [
                'read:examinations' => 'View exam schedules, centres, room plans, candidates, and marks',
                'write:examinations' => 'Create and modify exams, venues, seat allocations, attendance, and results',
                'download:admit_cards' => 'Generate and stream official student admit cards and hall tickets',
            ],
            'Student Tests & Mock Examinations' => [
                'read:tests' => 'View tests, merit rankings, leaderboards, question papers, and performance analytics',
                'write:tests' => 'Create and modify tests, record candidate scores, and bulk import results',
                'download:marksheets' => 'Generate and stream official student marksheet PDFs and performance dossiers',
            ],
            'Optional Subjects Management' => [
                'read:optional_subjects' => 'View optional subjects catalog, student allocations, and reports',
                'write:optional_subjects' => 'Update student optional subjects, approve/reject requests, and manage catalog',
            ],
            'Student Identity Cards' => [
                'read:id_cards' => 'View student ID card configurations, digital card payloads, and verification data',
                'download:id_cards' => 'Download official student printable identity card PDFs',
            ],
            'Users & Access Management' => [
                'read:users' => 'Search and view users, profiles, security telemetry, roles, and permissions',
                'write:users' => 'Create, update, lock/unlock, soft-delete, restore, or export/import users',
                'manage:roles' => 'Create, modify, clone, delete roles and synchronize assigned permissions',
                'manage:permissions' => 'Inspect and manage system security permissions and audit trails',
            ],
            'Live Chat & Realtime Calls' => [
                'read:chat' => 'View conversations, direct messages, channels, chat history, and active participants',
                'write:chat' => 'Send messages, upload attachments, react, create channels or groups, and manage participants',
                'call:chat' => 'Initiate, join, accept, reject, signal, and manage WebRTC audio and video calls',
                'meeting:chat' => 'Schedule, start, manage waiting rooms, admit participants, and host online meetings',
            ],
            'Application System Settings' => [
                'read:settings' => 'Read application, branding, localization, and system configurations',
                'write:settings' => 'Update application system settings, modules, branding, and parameters',
                'manage:settings' => 'Manage system maintenance, cache flushing, backups, and security parameters',
            ],
            'Support Tickets & Helpdesk' => [
                'read:tickets' => 'View support tickets, threads, categories, canned responses, statistics, and SLA telemetry',
                'write:tickets' => 'Submit tickets, post replies, upload attachments, and rate support satisfaction',
                'manage:tickets' => 'Assign tickets, update status and priority, manage categories, canned responses, bulk triage, and SLA enforcement',
            ],
            'Communications & Marketing' => [
                'read:communications' => 'View communication logs, campaigns, marketing subscribers, SMS and Email templates, and dispatch statistics',
                'write:communications' => 'Compose single/bulk communications, manage newsletter/WhatsApp subscribers, create and update SMS/Email templates',
                'manage:communications' => 'Execute bulk campaigns, cancel queued communications, retry failed dispatches, manage subscribers export/import, delete templates, and purge communication logs',
            ],
            'Global Search & Navigation' => [
                'read:search' => 'Execute global unified search across permissible modules, navigation items, actions, and system entities',
                'manage:search' => 'Configure and query navigation taxonomy and user interface viewport preferences',
            ],
            'UPSC Mock Interview Management' => [
                'read:mock_interviews' => 'View mock interview sessions, 5-member board panelists, candidate slots, appearance order, and evaluation marks',
                'write:mock_interviews' => 'Create and modify interview sessions, assign board members, schedule candidate slots, and upload recordings',
                'manage:mock_interviews' => 'Auto-generate sequential timetable slots, dispatch personalized invitations and recording links, and delete sessions',
                'download:mock_interviews' => 'Export comprehensive session timetables and multi-dimensional reports in PDF, Excel, and CSV formats',
            ],
            'Notifications' => [
                'send:notifications' => 'Dispatch multi-channel push and broadcast alerts',
            ],
            'Bot Defense & CAPTCHA' => [
                'read:captcha' => 'Read CAPTCHA specifications, configuration, verification logs, and telemetry metrics',
                'verify:captcha' => 'Submit and verify CAPTCHA challenges via headless API or external clients',
                'manage:captcha' => 'Administrative management: update CAPTCHA driver settings, prune logs, and run diagnostics',
            ],
            'Administrative Full Access' => [
                '*' => 'Full unrestricted access across all existing & future API endpoints',
            ],
        ];
    }

    /**
     * Generate and store a new API Key.
     *
     * @param  array<string, mixed>  $data
     * @return array{apiKey: ApiKey, plainTextKey: string}
     */
    public function generateKey(array $data, ?User $user = null): array
    {
        $user = $user ?? Auth::user();
        $userId = $user?->id;

        $environment = $data['environment'] ?? ApiKey::ENV_PRODUCTION;
        $secret = ApiKey::generateSecret($environment);

        // Normalize IP allowlist
        $ipAllowlist = null;
        if (! empty($data['ip_allowlist'])) {
            if (is_string($data['ip_allowlist'])) {
                $ipAllowlist = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $data['ip_allowlist']))));
            } elseif (is_array($data['ip_allowlist'])) {
                $ipAllowlist = array_values(array_filter(array_map('trim', $data['ip_allowlist'])));
            }
        }

        // Normalize Allowed Domains
        $allowedDomains = null;
        if (! empty($data['allowed_domains'])) {
            if (is_string($data['allowed_domains'])) {
                $allowedDomains = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $data['allowed_domains']))));
            } elseif (is_array($data['allowed_domains'])) {
                $allowedDomains = array_values(array_filter(array_map('trim', $data['allowed_domains'])));
            }
        }

        // Normalize Scopes
        $scopes = $data['scopes'] ?? ['ping'];
        if (is_string($scopes)) {
            $scopes = array_values(array_filter(array_map('trim', explode(',', $scopes))));
        }

        return DB::transaction(function () use ($data, $userId, $environment, $secret, $ipAllowlist, $allowedDomains, $scopes) {
            $apiKey = ApiKey::create([
                'user_id' => $data['user_id'] ?? $userId,
                'name' => trim((string) ($data['name'] ?? 'API Client')),
                'key_prefix' => $secret['key_prefix'],
                'key_hash' => $secret['key_hash'],
                'type' => $data['type'] ?? ApiKey::TYPE_EXTERNAL_APP,
                'environment' => $environment,
                'status' => ApiKey::STATUS_ACTIVE,
                'scopes' => $scopes,
                'ip_allowlist' => $ipAllowlist,
                'allowed_domains' => $allowedDomains,
                'rate_limit_per_minute' => max(1, min(10000, (int) ($data['rate_limit_per_minute'] ?? 60))),
                'expires_at' => ! empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null,
                'description' => ! empty($data['description']) ? trim((string) $data['description']) : null,
                'metadata' => $data['metadata'] ?? null,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'api_key.created',
                description: "Created new API Key '{$apiKey->name}' [{$apiKey->key_prefix}] ({$apiKey->type}/{$apiKey->environment})",
                auditable: $apiKey,
                oldValues: null,
                newValues: [
                    'id' => $apiKey->id,
                    'name' => $apiKey->name,
                    'prefix' => $apiKey->key_prefix,
                    'type' => $apiKey->type,
                    'environment' => $apiKey->environment,
                    'rate_limit' => $apiKey->rate_limit_per_minute,
                    'scopes' => $apiKey->scopes,
                    'expires_at' => $apiKey->expires_at?->toIso8601String(),
                ],
                userId: $userId
            );

            return [
                'apiKey' => $apiKey,
                'plainTextKey' => $secret['plain_key'],
            ];
        });
    }

    /**
     * Update an existing API Key's metadata, scopes, and policies.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateKey(ApiKey $apiKey, array $data, ?User $user = null): ApiKey
    {
        $user = $user ?? Auth::user();
        $userId = $user?->id;

        $oldValues = $apiKey->only([
            'name', 'type', 'environment', 'status', 'scopes', 'ip_allowlist',
            'allowed_domains', 'rate_limit_per_minute', 'expires_at', 'description',
        ]);

        // Normalize IP allowlist
        $ipAllowlist = null;
        if (isset($data['ip_allowlist'])) {
            if (is_string($data['ip_allowlist'])) {
                $ipAllowlist = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $data['ip_allowlist']))));
            } elseif (is_array($data['ip_allowlist'])) {
                $ipAllowlist = array_values(array_filter(array_map('trim', $data['ip_allowlist'])));
            }
        } else {
            $ipAllowlist = $apiKey->ip_allowlist;
        }

        // Normalize Allowed Domains
        $allowedDomains = null;
        if (isset($data['allowed_domains'])) {
            if (is_string($data['allowed_domains'])) {
                $allowedDomains = array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $data['allowed_domains']))));
            } elseif (is_array($data['allowed_domains'])) {
                $allowedDomains = array_values(array_filter(array_map('trim', $data['allowed_domains'])));
            }
        } else {
            $allowedDomains = $apiKey->allowed_domains;
        }

        // Normalize Scopes
        $scopes = $apiKey->scopes;
        if (isset($data['scopes'])) {
            $scopes = is_string($data['scopes'])
                ? array_values(array_filter(array_map('trim', explode(',', $data['scopes']))))
                : array_values($data['scopes']);
        }

        return DB::transaction(function () use ($apiKey, $data, $oldValues, $userId, $ipAllowlist, $allowedDomains, $scopes) {
            $apiKey->update([
                'name' => isset($data['name']) ? trim((string) $data['name']) : $apiKey->name,
                'type' => $data['type'] ?? $apiKey->type,
                'environment' => $data['environment'] ?? $apiKey->environment,
                'scopes' => $scopes,
                'ip_allowlist' => $ipAllowlist,
                'allowed_domains' => $allowedDomains,
                'rate_limit_per_minute' => isset($data['rate_limit_per_minute']) ? max(1, min(10000, (int) $data['rate_limit_per_minute'])) : $apiKey->rate_limit_per_minute,
                'expires_at' => array_key_exists('expires_at', $data)
                    ? (! empty($data['expires_at']) ? Carbon::parse($data['expires_at']) : null)
                    : $apiKey->expires_at,
                'description' => array_key_exists('description', $data) ? (! empty($data['description']) ? trim((string) $data['description']) : null) : $apiKey->description,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'api_key.updated',
                description: "Updated API Key '{$apiKey->name}' [{$apiKey->key_prefix}]",
                auditable: $apiKey,
                oldValues: $oldValues,
                newValues: $apiKey->only([
                    'name', 'type', 'environment', 'status', 'scopes', 'ip_allowlist',
                    'allowed_domains', 'rate_limit_per_minute', 'expires_at', 'description',
                ]),
                userId: $userId
            );

            return $apiKey;
        });
    }

    /**
     * Rotate secret for an existing API Key.
     *
     * @return array{apiKey: ApiKey, plainTextKey: string}
     */
    public function rotateKey(ApiKey $apiKey, ?User $user = null): array
    {
        $user = $user ?? Auth::user();
        $userId = $user?->id;

        $secret = ApiKey::generateSecret($apiKey->environment);

        return DB::transaction(function () use ($apiKey, $userId, $secret) {
            $oldPrefix = $apiKey->key_prefix;

            $apiKey->update([
                'key_prefix' => $secret['key_prefix'],
                'key_hash' => $secret['key_hash'],
                'status' => ApiKey::STATUS_ACTIVE,
                'revoked_at' => null,
                'revoked_by' => null,
                'revocation_reason' => null,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'api_key.rotated',
                description: "Rotated secret for API Key '{$apiKey->name}'. Old prefix: {$oldPrefix}, New prefix: {$apiKey->key_prefix}",
                auditable: $apiKey,
                oldValues: ['prefix' => $oldPrefix],
                newValues: ['prefix' => $apiKey->key_prefix],
                userId: $userId
            );

            return [
                'apiKey' => $apiKey,
                'plainTextKey' => $secret['plain_key'],
            ];
        });
    }

    /**
     * Revoke an active API Key immediately.
     */
    public function revokeKey(ApiKey $apiKey, ?string $reason = null, ?User $user = null): ApiKey
    {
        $user = $user ?? Auth::user();
        $userId = $user?->id;

        return DB::transaction(function () use ($apiKey, $userId, $reason) {
            $apiKey->revoke($userId, $reason);

            AuditLogService::log(
                event: 'api_key.revoked',
                description: "Revoked API Key '{$apiKey->name}' [{$apiKey->key_prefix}]. Reason: ".($reason ?: 'Administrative revocation'),
                auditable: $apiKey,
                oldValues: ['status' => ApiKey::STATUS_ACTIVE],
                newValues: [
                    'status' => ApiKey::STATUS_REVOKED,
                    'revocation_reason' => $reason,
                    'revoked_at' => $apiKey->revoked_at?->toIso8601String(),
                ],
                userId: $userId
            );

            return $apiKey;
        });
    }

    /**
     * Toggle status between active and suspended.
     */
    public function toggleStatus(ApiKey $apiKey, ?User $user = null): ApiKey
    {
        $user = $user ?? Auth::user();
        $userId = $user?->id;

        return DB::transaction(function () use ($apiKey, $userId) {
            $newStatus = ($apiKey->status === ApiKey::STATUS_ACTIVE) ? ApiKey::STATUS_SUSPENDED : ApiKey::STATUS_ACTIVE;
            $oldStatus = $apiKey->status;

            $apiKey->update([
                'status' => $newStatus,
                'updated_by' => $userId,
            ]);

            AuditLogService::log(
                event: 'api_key.status_changed',
                description: "Changed API Key '{$apiKey->name}' status from {$oldStatus} to {$newStatus}",
                auditable: $apiKey,
                oldValues: ['status' => $oldStatus],
                newValues: ['status' => $newStatus],
                userId: $userId
            );

            return $apiKey;
        });
    }

    /**
     * Soft delete an API Key.
     */
    public function deleteKey(ApiKey $apiKey, ?User $user = null): bool
    {
        $user = $user ?? Auth::user();
        $userId = $user?->id;

        return DB::transaction(function () use ($apiKey, $userId) {
            $apiKey->update(['deleted_by' => $userId]);
            $deleted = $apiKey->delete();

            AuditLogService::log(
                event: 'api_key.deleted',
                description: "Soft deleted API Key '{$apiKey->name}' [{$apiKey->key_prefix}]",
                auditable: $apiKey,
                oldValues: null,
                newValues: ['deleted_at' => Carbon::now()->toIso8601String()],
                userId: $userId
            );

            return $deleted;
        });
    }

    /**
     * Restore a soft-deleted API Key.
     */
    public function restoreKey(int|string $id, ?User $user = null): ApiKey
    {
        $user = $user ?? Auth::user();
        $userId = $user?->id;

        return DB::transaction(function () use ($id, $userId) {
            $apiKey = ApiKey::withTrashed()->findOrFail($id);
            $apiKey->restore();
            $apiKey->update(['deleted_by' => null, 'updated_by' => $userId]);

            AuditLogService::log(
                event: 'api_key.restored',
                description: "Restored soft deleted API Key '{$apiKey->name}' [{$apiKey->key_prefix}]",
                auditable: $apiKey,
                oldValues: ['deleted_at' => $apiKey->deleted_at],
                newValues: ['deleted_at' => null],
                userId: $userId
            );

            return $apiKey;
        });
    }

    /**
     * Force delete an API Key and associated telemetry.
     */
    public function forceDeleteKey(ApiKey $apiKey, ?User $user = null): bool
    {
        $user = $user ?? Auth::user();
        $userId = $user?->id;

        return DB::transaction(function () use ($apiKey, $userId) {
            $keyName = $apiKey->name;
            $keyPrefix = $apiKey->key_prefix;

            // Delete logs or keep null
            $apiKey->logs()->delete();
            $deleted = $apiKey->forceDelete();

            AuditLogService::log(
                event: 'api_key.purged',
                description: "Permanently purged API Key '{$keyName}' [{$keyPrefix}] and associated records",
                auditable: null,
                oldValues: ['name' => $keyName, 'prefix' => $keyPrefix],
                newValues: null,
                userId: $userId
            );

            return $deleted;
        });
    }

    /**
     * Get real-time dashboard analytics and statistics.
     *
     * @return array<string, mixed>
     */
    public function getDashboardStatistics(): array
    {
        $totalKeys = ApiKey::count();
        $activeKeys = ApiKey::where('status', ApiKey::STATUS_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', Carbon::now());
            })->count();

        $suspendedKeys = ApiKey::where('status', ApiKey::STATUS_SUSPENDED)->count();
        $revokedKeys = ApiKey::where('status', ApiKey::STATUS_REVOKED)->count();
        $expiredKeys = ApiKey::whereNotNull('expires_at')
            ->where('expires_at', '<=', Carbon::now())
            ->count();
        $trashedKeys = ApiKey::onlyTrashed()->count();

        // Telemetry stats
        $last24Hours = Carbon::now()->subHours(24);
        $requests24h = ApiKeyLog::where('created_at', '>=', $last24Hours)->count();
        $totalCalls = ApiKey::sum('total_requests');

        $successfulCalls24h = ApiKeyLog::where('created_at', '>=', $last24Hours)
            ->whereBetween('status_code', [200, 299])
            ->count();

        $successRate = $requests24h > 0
            ? round(($successfulCalls24h / $requests24h) * 100, 1)
            : 100.0;

        $avgLatency24h = (float) ApiKeyLog::where('created_at', '>=', $last24Hours)
            ->whereNotNull('duration_ms')
            ->avg('duration_ms');

        $topKeys = ApiKey::orderByDesc('total_requests')
            ->limit(5)
            ->get(['id', 'name', 'key_prefix', 'type', 'environment', 'status', 'total_requests', 'last_used_at']);

        $recentErrors = ApiKeyLog::where('status_code', '>=', 400)
            ->orderByDesc('created_at')
            ->limit(5)
            ->get(['id', 'api_key_name', 'endpoint', 'method', 'status_code', 'ip_address', 'response_summary', 'created_at']);

        return [
            'total_keys' => $totalKeys,
            'active_keys' => $activeKeys,
            'suspended_keys' => $suspendedKeys,
            'revoked_keys' => $revokedKeys,
            'expired_keys' => $expiredKeys,
            'trashed_keys' => $trashedKeys,
            'requests_24h' => $requests24h,
            'total_calls' => $totalCalls,
            'success_rate' => $successRate,
            'avg_latency_ms' => round($avgLatency24h, 1),
            'top_keys' => $topKeys,
            'recent_errors' => $recentErrors,
        ];
    }

    /**
     * Record an incoming API request telemetry log.
     */
    public function logRequest(
        Request $request,
        ?ApiKey $apiKey,
        int $statusCode,
        float $durationMs,
        ?string $errorSummary = null
    ): ?ApiKeyLog {
        try {
            $headers = $request->headers->all();
            // Sanitize sensitive headers
            unset($headers['authorization'], $headers['x-api-key'], $headers['cookie'], $headers['php-auth-pw']);

            $payload = $request->except(['password', 'password_confirmation', 'token', 'secret', 'key']);

            return ApiKeyLog::create([
                'api_key_id' => $apiKey?->id,
                'api_key_name' => $apiKey?->name ?? ($request->header('X-API-Key') ? 'Unrecognized Key' : 'Anonymous Request'),
                'ip_address' => $request->ip() ?: '127.0.0.1',
                'user_agent' => mb_substr((string) $request->header('User-Agent'), 0, 500),
                'method' => strtoupper($request->method()),
                'endpoint' => mb_substr($request->path(), 0, 500),
                'status_code' => $statusCode,
                'duration_ms' => round($durationMs, 2),
                'request_headers' => $headers,
                'request_payload' => ! empty($payload) ? $payload : null,
                'response_summary' => $errorSummary ? mb_substr($errorSummary, 0, 500) : null,
                'created_at' => Carbon::now(),
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to write ApiKeyLog: '.$e->getMessage());

            return null;
        }
    }
}
