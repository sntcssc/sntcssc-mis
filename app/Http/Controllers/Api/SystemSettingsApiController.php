<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmailService;
use App\Services\FileUploadService;
use App\Services\SmsService;
use App\Services\StorageService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class SystemSettingsApiController extends Controller
{
    /**
     * Resolve the active authenticated user context (via Sanctum/token, session, or API key owner).
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
    }

    /**
     * Check if the request context has permission or scope to read secrets in plaintext.
     */
    protected function canRevealSecrets(Request $request): bool
    {
        $apiKey = $request->attributes->get('api_key');
        if ($apiKey) {
            return $apiKey->hasScope('manage:settings') || $apiKey->hasScope('*');
        }

        $user = $this->resolveUser($request);
        if ($user && ($user->hasRole('Super Administrator') || $user->can('settings.manage') || $user->can('settings.view'))) {
            return true;
        }

        return false;
    }

    /**
     * Standard JSON success response envelope.
     */
    protected function success(mixed $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Standard JSON error response envelope.
     */
    protected function error(string $code, string $message, int $status = 400, ?array $details = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    /**
     * Format a Setting model instance for API representation.
     */
    protected function formatSetting(Setting $setting, bool $revealSecret = false): array
    {
        $isSecret = $setting->type === Setting::TYPE_SECRET;
        $isAsset = in_array($setting->type, [Setting::TYPE_IMAGE, Setting::TYPE_FILE], true);

        $value = $setting->typed();
        $displayValue = $value;

        if ($isSecret) {
            $displayValue = $revealSecret ? $setting->rawValue() : '••••••••';
        }

        $url = null;
        if ($isAsset && ! empty($setting->rawValue())) {
            $url = FileUploadService::url((string) $setting->rawValue());
        }

        return [
            'id' => $setting->id,
            'key' => $setting->key,
            'group' => $setting->group,
            'type' => $setting->type,
            'label' => $setting->label,
            'value' => $displayValue,
            'is_secret' => $isSecret,
            'url' => $url,
            'options' => $setting->options,
            'status' => (bool) $setting->status,
            'created_by' => $setting->created_by,
            'updated_by' => $setting->updated_by,
            'deleted_by' => $setting->deleted_by,
            'created_at' => $setting->created_at?->toIso8601String(),
            'updated_at' => $setting->updated_at?->toIso8601String(),
            'deleted_at' => $setting->deleted_at?->toIso8601String(),
            'is_trashed' => $setting->trashed(),
        ];
    }

    /**
     * Public Bootstrap / App Configuration Endpoint.
     * Serves essential application identity, branding, theme, localization, and feature flags
     * for native mobile apps (Flutter, React Native), SPAs, desktop clients, and kiosks.
     */
    public function publicBootstrap(Request $request): JsonResponse
    {
        try {
            $appName = Setting::appName();
            $siteName = Setting::siteName();
            $logoUrl = Setting::logoUrl();
            $faviconUrl = Setting::faviconUrl();

            // Extract core branding and appearance
            $primaryColor = (string) Setting::get('appearance.primary_color', '#10b981');
            $darkMode = (string) Setting::get('appearance.dark_mode', 'system');
            $sidebarTheme = (string) Setting::get('appearance.sidebar_theme', 'default');
            $fontFamily = (string) Setting::get('appearance.font_family', 'Inter');

            // Extract localization
            $locale = (string) Setting::get('localization.language', config('app.locale', 'en'));
            $fallbackLocale = (string) Setting::get('localization.fallback_language', config('app.fallback_locale', 'en'));
            $timezone = (string) Setting::get('localization.timezone', config('app.timezone', 'Asia/Kolkata'));
            $dateFormat = (string) Setting::get('localization.date_format', 'd M Y');
            $timeFormat = (string) Setting::get('localization.time_format', 'h:i A');
            $currencySymbol = (string) Setting::get('localization.currency_symbol', '₹');
            $currencyCode = (string) Setting::get('localization.currency_code', 'INR');
            $numberFormat = (string) Setting::get('localization.number_format', 'indian');

            // Extract general contact & institution info
            $tagline = (string) Setting::get('general.site_tagline', 'Shaping future civil servants');
            $description = (string) Setting::get('general.site_description', '');
            $campus = (string) Setting::get('general.site_campus', 'Main Campus');
            $email = (string) Setting::get('general.site_email', 'info@sntcssc.in');
            $mobile = (string) Setting::get('general.site_mobile', '');
            $phone = (string) Setting::get('general.site_phone', '');
            $address = (string) Setting::get('general.site_address', '');
            $timing = (string) Setting::get('general.site_timing', '10:00 AM – 6:00 PM');
            $openDays = (string) Setting::get('general.site_open_days', 'Monday – Saturday');
            $copyright = Setting::copyrightText();

            // System operational flags
            $maintenanceMode = (bool) Setting::get('system.maintenance_mode', false);
            $appVersion = (string) Setting::get('system.app_version', '1.0.0');
            $maxUploadMb = (int) Setting::get('system.max_upload_size', 10);
            $sessionLifetime = (int) Setting::get('system.session_lifetime', 120);

            // Feature flags for cross-platform clients
            $features = [
                'live_chat' => (bool) Setting::get('chat.enabled', true),
                'voice_calls' => (bool) Setting::get('chat.voice_call_enabled', true),
                'video_calls' => (bool) Setting::get('chat.video_call_enabled', true),
                'online_meetings' => true,
                'study_materials' => true,
                'library' => true,
                'library_fines' => (bool) Setting::get('library.fines_enabled', true),
                'hostel' => true,
                'examinations' => true,
                'attendance_biometric' => true,
                'online_payments' => (bool) Setting::get('payment.enabled', false),
                'offline_payments' => (bool) Setting::get('payment.offline_enabled', true),
                'sms_service' => (bool) Setting::get('sms.enabled', false),
                'email_service' => (bool) Setting::get('email.is_enabled', true),
            ];

            $supportedLanguages = [
                ['code' => 'en', 'name' => 'English', 'native_name' => 'English', 'dir' => 'ltr'],
                ['code' => 'bn', 'name' => 'Bengali', 'native_name' => 'বাংলা', 'dir' => 'ltr'],
                ['code' => 'hi', 'name' => 'Hindi', 'native_name' => 'हिन्दी', 'dir' => 'ltr'],
            ];

            return $this->success([
                'app' => [
                    'name' => $appName,
                    'site_name' => $siteName,
                    'tagline' => $tagline,
                    'description' => $description,
                    'campus' => $campus,
                    'version' => $appVersion,
                    'copyright' => $copyright,
                    'institution' => 'Satyendranath Tagore Civil Services Study Centre',
                ],
                'branding' => [
                    'logo_url' => $logoUrl,
                    'favicon_url' => $faviconUrl,
                    'primary_color' => $primaryColor,
                    'theme_mode' => $darkMode,
                    'sidebar_theme' => $sidebarTheme,
                    'font_family' => $fontFamily,
                ],
                'localization' => [
                    'locale' => $locale,
                    'fallback_locale' => $fallbackLocale,
                    'timezone' => $timezone,
                    'date_format' => $dateFormat,
                    'time_format' => $timeFormat,
                    'currency_symbol' => $currencySymbol,
                    'currency_code' => $currencyCode,
                    'number_format' => $numberFormat,
                    'supported_locales' => $supportedLanguages,
                ],
                'contact' => [
                    'email' => $email,
                    'mobile' => $mobile,
                    'phone' => $phone,
                    'address' => $address,
                    'office_timing' => $timing,
                    'open_days' => $openDays,
                ],
                'operational' => [
                    'maintenance_mode' => $maintenanceMode,
                    'max_upload_size_mb' => $maxUploadMb,
                    'session_lifetime_minutes' => $sessionLifetime,
                    'default_storage_disk' => (string) Setting::get('storage.default_disk', 'public'),
                    'allowed_upload_extensions' => array_map('trim', explode(',', (string) Setting::get('storage.allowed_file_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,svg,zip,mp4'))),
                ],
                'features' => $features,
            ], [
                'server_time' => Carbon::now()->toIso8601String(),
                'api_version' => 'v1',
            ]);
        } catch (Throwable $e) {
            Log::error('Public bootstrap settings error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('BOOTSTRAP_FETCH_FAILED', 'Unable to retrieve application bootstrap settings: '.$e->getMessage(), 500);
        }
    }

    /**
     * Catalog of all distinct setting groups with active settings count.
     */
    public function groups(Request $request): JsonResponse
    {
        try {
            $groupCounts = Setting::query()
                ->select('group', DB::raw('count(*) as total_count'), DB::raw('sum(case when status = 1 then 1 else 0 end) as active_count'), DB::raw('max(updated_at) as last_updated_at'))
                ->groupBy('group')
                ->orderBy('group')
                ->get();

            $groupMetadata = [
                'general' => ['title' => 'General & Institution Information', 'description' => 'Institution name, campus, contact info, office timing and copyright.'],
                'appearance' => ['title' => 'Appearance & Theming', 'description' => 'Primary accent colors, dark mode defaults, logos, and sidebar styling.'],
                'localization' => ['title' => 'Localization & Regional Formats', 'description' => 'Default language, timezone, date/time formats, and currency settings.'],
                'system' => ['title' => 'System & Environment Diagnostics', 'description' => 'Maintenance mode, upload limits, session lifetimes, and cache drivers.'],
                'email' => ['title' => 'Email & SMTP Mailer', 'description' => 'SMTP server host, port, credentials, and notification sender profile.'],
                'sms' => ['title' => 'SMS Gateway (2factor / DLT)', 'description' => 'SMS API keys, sender IDs, DLT templates, and OTP policies.'],
                'payment' => ['title' => 'Payment Gateways & Offline Banking', 'description' => 'Razorpay, PhonePe, CCAvenue, UPI QR, and bank transfer accounts.'],
                'chat' => ['title' => 'Live Chat & WebRTC Calling', 'description' => 'WebSocket transport, STUN/TURN ICE configurations, and calling toggles.'],
                'library' => ['title' => 'Library Management Policies', 'description' => 'Loan durations, overdue fine rates, security deposits, and reminders.'],
                'hostel' => ['title' => 'Hostel & Residential Policies', 'description' => 'Room allocation rules, curfew hours, gate passes, and fee policies.'],
                'attendance' => ['title' => 'Attendance & Shift Rules', 'description' => 'Grace periods, half-day cutoffs, overtime limits, and biometric flags.'],
                'seo' => ['title' => 'Search Engine Optimization (SEO)', 'description' => 'Meta titles, keywords, OpenGraph images, and search engine directives.'],
            ];

            $results = $groupCounts->map(function ($item) use ($groupMetadata) {
                $group = (string) $item->group;
                $meta = $groupMetadata[$group] ?? [
                    'title' => Str::title(str_replace(['_', '-'], ' ', $group)),
                    'description' => 'Settings and parameters for the '.$group.' module.',
                ];

                return [
                    'group' => $group,
                    'title' => $meta['title'],
                    'description' => $meta['description'],
                    'total_count' => (int) $item->total_count,
                    'active_count' => (int) $item->active_count,
                    'last_updated_at' => $item->last_updated_at ? Carbon::parse($item->last_updated_at)->toIso8601String() : null,
                ];
            });

            return $this->success($results, [
                'total_groups' => $results->count(),
                'server_time' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('Settings groups fetch error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('GROUPS_FETCH_FAILED', 'Failed to retrieve settings groups: '.$e->getMessage(), 500);
        }
    }

    /**
     * Settings Telemetry and System Diagnostics KPI metrics.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $totalSettings = Setting::withTrashed()->count();
            $activeSettings = Setting::where('status', true)->count();
            $inactiveSettings = Setting::where('status', false)->count();
            $trashedSettings = Setting::onlyTrashed()->count();
            $secretSettings = Setting::where('type', Setting::TYPE_SECRET)->count();
            $groupsCount = Setting::distinct('group')->count('group');
            $lastUpdated = Setting::max('updated_at');

            // Cache status
            $isCached = Cache::has('snt.settings');

            return $this->success([
                'counts' => [
                    'total' => $totalSettings,
                    'active' => $activeSettings,
                    'inactive' => $inactiveSettings,
                    'soft_deleted' => $trashedSettings,
                    'secrets' => $secretSettings,
                    'groups' => $groupsCount,
                ],
                'cache' => [
                    'is_cached' => $isCached,
                    'cache_key' => 'snt.settings',
                    'cache_driver' => config('cache.default'),
                ],
                'environment' => [
                    'app_name' => Setting::appName(),
                    'php_version' => PHP_VERSION,
                    'laravel_version' => app()->version(),
                    'db_connection' => config('database.default'),
                    'server_timezone' => config('app.timezone'),
                    'server_time' => Carbon::now()->toIso8601String(),
                    'last_settings_update' => $lastUpdated ? Carbon::parse($lastUpdated)->toIso8601String() : null,
                ],
            ]);
        } catch (Throwable $e) {
            Log::error('Settings statistics error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('STATISTICS_FETCH_FAILED', 'Failed to calculate settings telemetry: '.$e->getMessage(), 500);
        }
    }

    /**
     * Query and paginate settings with group, type, status, and search filters.
     */
    public function index(Request $request): JsonResponse
    {
        try {
            $query = Setting::query();

            // Handle trashed settings filter
            if ($request->boolean('only_trashed')) {
                $query->onlyTrashed();
            } elseif ($request->boolean('with_trashed')) {
                $query->withTrashed();
            }

            // Filter by group
            if ($group = $request->input('group')) {
                $query->where('group', $group);
            }

            // Filter by type
            if ($type = $request->input('type')) {
                $query->where('type', $type);
            }

            // Filter by status
            if ($request->has('status')) {
                $query->where('status', $request->boolean('status'));
            }

            // Search query across key, label, group
            if ($search = $request->input('search')) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('key', 'like', "%{$search}%")
                        ->orWhere('label', 'like', "%{$search}%")
                        ->orWhere('group', 'like', "%{$search}%");
                });
            }

            // Order by group, key
            $query->orderBy('group')->orderBy('key');

            $perPage = min(max((int) $request->input('per_page', 50), 1), 250);
            $settings = $query->paginate($perPage);

            $revealSecrets = $request->boolean('reveal_secrets') && $this->canRevealSecrets($request);

            $formatted = collect($settings->items())->map(function (Setting $setting) use ($revealSecrets) {
                return $this->formatSetting($setting, $revealSecrets);
            });

            return $this->success($formatted, [
                'current_page' => $settings->currentPage(),
                'per_page' => $settings->perPage(),
                'total' => $settings->total(),
                'last_page' => $settings->lastPage(),
                'reveal_secrets' => $revealSecrets,
            ]);
        } catch (Throwable $e) {
            Log::error('Settings index fetch error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SETTINGS_FETCH_FAILED', 'Failed to retrieve settings list: '.$e->getMessage(), 500);
        }
    }

    /**
     * Get all settings in a specific group as a dictionary key-value map and item list.
     */
    public function byGroup(string $group, Request $request): JsonResponse
    {
        try {
            $settings = Setting::where('group', $group)
                ->orderBy('key')
                ->get();

            if ($settings->isEmpty()) {
                // Check if group exists or return empty
                $exists = Setting::withTrashed()->where('group', $group)->exists();
                if (! $exists) {
                    return $this->error('GROUP_NOT_FOUND', "Settings group '{$group}' does not exist.", 404);
                }
            }

            $revealSecrets = $request->boolean('reveal_secrets') && $this->canRevealSecrets($request);

            $dictionary = [];
            $items = [];

            foreach ($settings as $setting) {
                $formatted = $this->formatSetting($setting, $revealSecrets);
                $dictionary[$setting->key] = $formatted['value'];
                $items[] = $formatted;
            }

            return $this->success([
                'group' => $group,
                'dictionary' => $dictionary,
                'items' => $items,
            ], [
                'total_items' => count($items),
                'group' => $group,
            ]);
        } catch (Throwable $e) {
            Log::error("Settings byGroup '{$group}' fetch error: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('GROUP_FETCH_FAILED', 'Failed to retrieve group settings: '.$e->getMessage(), 500);
        }
    }

    /**
     * Retrieve single setting dossier by its unique key.
     */
    public function show(string $key, Request $request): JsonResponse
    {
        try {
            $setting = Setting::withTrashed()->firstWhere('key', $key);

            if (! $setting) {
                return $this->error('SETTING_NOT_FOUND', "Setting with key '{$key}' was not found.", 404);
            }

            $revealSecret = $request->boolean('reveal_secret') && $this->canRevealSecrets($request);

            return $this->success($this->formatSetting($setting, $revealSecret));
        } catch (Throwable $e) {
            Log::error("Setting show '{$key}' error: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SETTING_FETCH_FAILED', 'Failed to retrieve setting: '.$e->getMessage(), 500);
        }
    }

    /**
     * Create / Register a new dynamic setting.
     */
    public function store(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'key' => ['required', 'string', 'max:190', 'unique:settings,key', 'regex:/^[a-zA-Z0-9_\-\.]+$/'],
            'group' => ['required', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:string,text,boolean,number,select,image,file,secret,json'],
            'label' => ['required', 'string', 'max:255'],
            'value' => ['nullable'],
            'options' => ['nullable', 'array'],
            'status' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The settings payload failed validation rules.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $user = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $setting = new Setting;
            $setting->key = $validated['key'];
            $setting->group = $validated['group'];
            $setting->type = $validated['type'];
            $setting->label = $validated['label'];
            $setting->options = $validated['options'] ?? null;
            $setting->status = $validated['status'] ?? true;
            $setting->created_by = $user?->id;
            $setting->updated_by = $user?->id;

            // Handle type-specific value assignment
            $value = $validated['value'] ?? null;
            if (is_array($value)) {
                $setting->value = json_encode($value);
            } elseif (is_bool($value)) {
                $setting->value = $value ? '1' : '0';
            } else {
                $setting->value = $value !== null ? (string) $value : null;
            }

            $setting->save();

            AuditLogService::log(
                event: 'settings.created',
                description: "Created setting '{$setting->key}' in group '{$setting->group}'",
                auditable: $setting,
                oldValues: null,
                newValues: [
                    'key' => $setting->key,
                    'group' => $setting->group,
                    'type' => $setting->type,
                    'label' => $setting->label,
                    'status' => $setting->status,
                ],
                userId: $user?->id
            );

            Setting::flushCache();

            DB::commit();

            return $this->success(
                $this->formatSetting($setting->refresh()),
                ['message' => "Setting '{$setting->key}' created successfully."],
                201
            );
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Setting creation error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SETTING_CREATE_FAILED', 'Failed to create setting: '.$e->getMessage(), 500);
        }
    }

    /**
     * Update an existing setting by key.
     */
    public function update(string $key, Request $request): JsonResponse
    {
        $setting = Setting::withTrashed()->firstWhere('key', $key);

        if (! $setting) {
            return $this->error('SETTING_NOT_FOUND', "Setting with key '{$key}' was not found.", 404);
        }

        $validator = Validator::make($request->all(), [
            'value' => ['nullable'],
            'label' => ['sometimes', 'string', 'max:255'],
            'group' => ['sometimes', 'string', 'max:100'],
            'type' => ['sometimes', 'string', 'in:string,text,boolean,number,select,image,file,secret,json'],
            'options' => ['nullable', 'array'],
            'status' => ['sometimes', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'The settings payload failed validation rules.', 422, $validator->errors()->toArray());
        }

        $validated = $validator->validated();
        $user = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $oldValues = [
                'value' => $setting->type === Setting::TYPE_SECRET ? '••••••••' : $setting->rawValue(),
                'label' => $setting->label,
                'status' => $setting->status,
                'options' => $setting->options,
            ];

            if (array_key_exists('label', $validated)) {
                $setting->label = $validated['label'];
            }
            if (array_key_exists('group', $validated)) {
                $setting->group = $validated['group'];
            }
            if (array_key_exists('type', $validated)) {
                $setting->type = $validated['type'];
            }
            if (array_key_exists('options', $validated)) {
                $setting->options = $validated['options'];
            }
            if (array_key_exists('status', $validated)) {
                $setting->status = (bool) $validated['status'];
            }

            if (array_key_exists('value', $validated)) {
                $val = $validated['value'];
                if (is_array($val)) {
                    $setting->value = json_encode($val);
                } elseif (is_bool($val)) {
                    $setting->value = $val ? '1' : '0';
                } else {
                    $setting->value = $val !== null ? (string) $val : null;
                }
            }

            $setting->updated_by = $user?->id;
            $setting->save();

            $newValues = [
                'value' => $setting->type === Setting::TYPE_SECRET ? '••••••••' : $setting->rawValue(),
                'label' => $setting->label,
                'status' => $setting->status,
                'options' => $setting->options,
            ];

            AuditLogService::log(
                event: 'settings.updated',
                description: "Updated setting '{$setting->key}'",
                auditable: $setting,
                oldValues: $oldValues,
                newValues: $newValues,
                userId: $user?->id
            );

            Setting::flushCache();

            DB::commit();

            return $this->success(
                $this->formatSetting($setting->refresh()),
                ['message' => "Setting '{$setting->key}' updated successfully."]
            );
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Setting update '{$key}' error: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SETTING_UPDATE_FAILED', 'Failed to update setting: '.$e->getMessage(), 500);
        }
    }

    /**
     * Batch update multiple settings atomically in a single transaction.
     * Accepts key-value map e.g. {"general.site_name": "New Name", "appearance.primary_color": "#2563eb"}
     * or array of items [{"key": "...", "value": "..."}].
     */
    public function batchUpdate(Request $request): JsonResponse
    {
        $settingsData = $request->input('settings');

        if (! is_array($settingsData) || empty($settingsData)) {
            return $this->error('INVALID_PAYLOAD', "The 'settings' payload must be a non-empty array or dictionary.", 422);
        }

        $user = $this->resolveUser($request);
        $updatedKeys = [];
        $errors = [];

        DB::beginTransaction();
        try {
            // Normalize into key => value pairs
            $pairs = [];
            if (array_is_list($settingsData)) {
                foreach ($settingsData as $item) {
                    if (is_array($item) && isset($item['key'])) {
                        $pairs[$item['key']] = $item['value'] ?? null;
                    }
                }
            } else {
                $pairs = $settingsData;
            }

            foreach ($pairs as $key => $val) {
                $setting = Setting::withTrashed()->firstWhere('key', $key);

                if (! $setting) {
                    // Create if key doesn't exist
                    $group = str_contains($key, '.') ? explode('.', $key)[0] : 'general';
                    $type = is_bool($val) ? Setting::TYPE_BOOLEAN : (is_array($val) ? Setting::TYPE_JSON : (is_numeric($val) ? Setting::TYPE_NUMBER : Setting::TYPE_STRING));

                    $setting = new Setting([
                        'key' => $key,
                        'group' => $group,
                        'type' => $type,
                        'label' => Str::headline(str_replace('.', ' ', $key)),
                        'status' => true,
                        'created_by' => $user?->id,
                    ]);
                }

                if ($setting->trashed()) {
                    $setting->restore();
                }

                $oldVal = $setting->rawValue();

                if (is_array($val)) {
                    $setting->value = json_encode($val);
                } elseif (is_bool($val)) {
                    $setting->value = $val ? '1' : '0';
                } else {
                    $setting->value = $val !== null ? (string) $val : null;
                }

                $setting->updated_by = $user?->id;
                $setting->save();

                $updatedKeys[] = $key;

                AuditLogService::log(
                    event: 'settings.batch_updated',
                    description: "Batch updated setting '{$key}'",
                    auditable: $setting,
                    oldValues: ['value' => $setting->type === Setting::TYPE_SECRET ? '••••••••' : $oldVal],
                    newValues: ['value' => $setting->type === Setting::TYPE_SECRET ? '••••••••' : $setting->rawValue()],
                    userId: $user?->id
                );
            }

            Setting::flushCache();

            DB::commit();

            return $this->success([
                'updated_count' => count($updatedKeys),
                'updated_keys' => $updatedKeys,
            ], [
                'message' => 'Successfully batch updated '.count($updatedKeys).' settings.',
                'server_time' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Settings batch update error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('BATCH_UPDATE_FAILED', 'Failed to batch update settings: '.$e->getMessage(), 500);
        }
    }

    /**
     * Soft delete a setting by its unique key.
     */
    public function destroy(string $key, Request $request): JsonResponse
    {
        $setting = Setting::firstWhere('key', $key);

        if (! $setting) {
            return $this->error('SETTING_NOT_FOUND', "Active setting with key '{$key}' was not found.", 404);
        }

        $user = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $setting->deleted_by = $user?->id;
            $setting->save();
            $setting->delete();

            AuditLogService::log(
                event: 'settings.deleted',
                description: "Soft deleted setting '{$key}'",
                auditable: $setting,
                userId: $user?->id
            );

            Setting::flushCache();

            DB::commit();

            return $this->success(null, [
                'message' => "Setting '{$key}' was soft deleted successfully.",
                'key' => $key,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Setting delete '{$key}' error: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SETTING_DELETE_FAILED', 'Failed to delete setting: '.$e->getMessage(), 500);
        }
    }

    /**
     * Restore a soft-deleted setting.
     */
    public function restore(string $key, Request $request): JsonResponse
    {
        $setting = Setting::onlyTrashed()->firstWhere('key', $key);

        if (! $setting) {
            return $this->error('SETTING_NOT_TRASHED', "No trashed setting found with key '{$key}'.", 404);
        }

        $user = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $setting->restore();
            $setting->deleted_by = null;
            $setting->updated_by = $user?->id;
            $setting->save();

            AuditLogService::log(
                event: 'settings.restored',
                description: "Restored soft deleted setting '{$key}'",
                auditable: $setting,
                userId: $user?->id
            );

            Setting::flushCache();

            DB::commit();

            return $this->success(
                $this->formatSetting($setting->refresh()),
                ['message' => "Setting '{$key}' was restored successfully."]
            );
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Setting restore '{$key}' error: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('SETTING_RESTORE_FAILED', 'Failed to restore setting: '.$e->getMessage(), 500);
        }
    }

    /**
     * Force delete a setting permanently.
     */
    public function forceDelete(string $key, Request $request): JsonResponse
    {
        $setting = Setting::withTrashed()->firstWhere('key', $key);

        if (! $setting) {
            return $this->error('SETTING_NOT_FOUND', "Setting with key '{$key}' was not found.", 404);
        }

        $user = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            AuditLogService::log(
                event: 'settings.force_deleted',
                description: "Permanently purged setting '{$key}' from database",
                auditable: $setting,
                userId: $user?->id
            );

            $setting->forceDelete();

            Setting::flushCache();

            DB::commit();

            return $this->success(null, [
                'message' => "Setting '{$key}' was permanently deleted.",
                'key' => $key,
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error("Setting force delete '{$key}' error: ".$e->getMessage(), ['exception' => $e]);

            return $this->error('FORCE_DELETE_FAILED', 'Failed to permanently delete setting: '.$e->getMessage(), 500);
        }
    }

    /**
     * Flush and invalidate the application settings cache.
     */
    public function flushCache(Request $request): JsonResponse
    {
        try {
            $user = $this->resolveUser($request);

            Setting::flushCache();

            AuditLogService::log(
                event: 'settings.cache_flushed',
                description: 'Forcibly flushed application settings cache',
                userId: $user?->id
            );

            return $this->success([
                'flushed' => true,
                'cache_key' => 'snt.settings',
                'flushed_at' => Carbon::now()->toIso8601String(),
            ], [
                'message' => 'Settings cache flushed successfully. System will reload fresh values on next request.',
            ]);
        } catch (Throwable $e) {
            Log::error('Settings cache flush error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('CACHE_FLUSH_FAILED', 'Failed to flush settings cache: '.$e->getMessage(), 500);
        }
    }

    /**
     * Reset a setting, group, or all settings to factory defaults.
     */
    public function reset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'scope' => ['required', 'string', 'in:key,group,all'],
            'target' => ['required_if:scope,key,group', 'nullable', 'string'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid reset request parameters.', 422, $validator->errors()->toArray());
        }

        $scope = $request->input('scope');
        $target = $request->input('target');
        $user = $this->resolveUser($request);

        // Fetch seeder defaults
        $seeder = new SettingsSeeder;
        $reflection = new \ReflectionClass($seeder);
        $method = $reflection->getMethod('settings');
        $method->setAccessible(true);
        /** @var array<int, array> $defaults */
        $defaults = $method->invoke($seeder);

        DB::beginTransaction();
        try {
            $resetCount = 0;

            if ($scope === 'key') {
                $defaultDef = collect($defaults)->firstWhere('key', $target);
                if (! $defaultDef) {
                    return $this->error('DEFAULT_NOT_FOUND', "No factory default defined for key '{$target}'.", 404);
                }

                $setting = Setting::withTrashed()->firstWhere('key', $target) ?? new Setting(['key' => $target]);
                if ($setting->trashed()) {
                    $setting->restore();
                }

                $setting->group = $defaultDef['group'];
                $setting->type = $defaultDef['type'];
                $setting->label = $defaultDef['label'];
                $setting->options = $defaultDef['options'] ?? null;
                $setting->value = is_array($defaultDef['value']) ? json_encode($defaultDef['value']) : (is_bool($defaultDef['value']) ? ($defaultDef['value'] ? '1' : '0') : (string) $defaultDef['value']);
                $setting->status = true;
                $setting->updated_by = $user?->id;
                $setting->save();
                $resetCount++;
            } elseif ($scope === 'group') {
                $groupDefaults = collect($defaults)->where('group', $target);
                if ($groupDefaults->isEmpty()) {
                    return $this->error('GROUP_DEFAULTS_NOT_FOUND', "No factory defaults defined for group '{$target}'.", 404);
                }

                foreach ($groupDefaults as $def) {
                    $setting = Setting::withTrashed()->firstWhere('key', $def['key']) ?? new Setting(['key' => $def['key']]);
                    if ($setting->trashed()) {
                        $setting->restore();
                    }
                    $setting->group = $def['group'];
                    $setting->type = $def['type'];
                    $setting->label = $def['label'];
                    $setting->options = $def['options'] ?? null;
                    $setting->value = is_array($def['value']) ? json_encode($def['value']) : (is_bool($def['value']) ? ($def['value'] ? '1' : '0') : (string) $def['value']);
                    $setting->status = true;
                    $setting->updated_by = $user?->id;
                    $setting->save();
                    $resetCount++;
                }
            } else { // 'all'
                foreach ($defaults as $def) {
                    $setting = Setting::withTrashed()->firstWhere('key', $def['key']) ?? new Setting(['key' => $def['key']]);
                    if ($setting->trashed()) {
                        $setting->restore();
                    }
                    $setting->group = $def['group'];
                    $setting->type = $def['type'];
                    $setting->label = $def['label'];
                    $setting->options = $def['options'] ?? null;
                    $setting->value = is_array($def['value']) ? json_encode($def['value']) : (is_bool($def['value']) ? ($def['value'] ? '1' : '0') : (string) $def['value']);
                    $setting->status = true;
                    $setting->updated_by = $user?->id;
                    $setting->save();
                    $resetCount++;
                }
            }

            Setting::flushCache();

            AuditLogService::log(
                event: 'settings.reset',
                description: "Reset settings to factory defaults for scope '{$scope}'".($target ? " target '{$target}'" : ''),
                userId: $user?->id
            );

            DB::commit();

            return $this->success([
                'scope' => $scope,
                'target' => $target,
                'reset_count' => $resetCount,
            ], [
                'message' => "Successfully reset {$resetCount} settings to factory defaults.",
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Settings reset error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('RESET_FAILED', 'Failed to reset settings: '.$e->getMessage(), 500);
        }
    }

    /**
     * Export all active settings as a JSON package (redacting secrets for security).
     */
    public function export(Request $request): JsonResponse
    {
        try {
            $group = $request->input('group');
            $query = Setting::query()->where('status', true);

            if ($group) {
                $query->where('group', $group);
            }

            $settings = $query->orderBy('group')->orderBy('key')->get();

            $exported = $settings->map(function (Setting $s) {
                return [
                    'key' => $s->key,
                    'group' => $s->group,
                    'type' => $s->type,
                    'label' => $s->label,
                    'value' => $s->type === Setting::TYPE_SECRET ? null : $s->typed(),
                    'options' => $s->options,
                    'status' => (bool) $s->status,
                ];
            });

            return $this->success([
                'exported_at' => Carbon::now()->toIso8601String(),
                'app_name' => Setting::appName(),
                'count' => $exported->count(),
                'settings' => $exported,
            ], [
                'note' => 'Sensitive secret values have been redacted from this export bundle.',
            ]);
        } catch (Throwable $e) {
            Log::error('Settings export error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('EXPORT_FAILED', 'Failed to export settings: '.$e->getMessage(), 500);
        }
    }

    /**
     * Import and upsert settings from JSON array.
     */
    public function import(Request $request): JsonResponse
    {
        $settingsData = $request->input('settings');

        if (! is_array($settingsData) || empty($settingsData)) {
            return $this->error('INVALID_PAYLOAD', "The 'settings' payload must be a non-empty array of setting objects.", 422);
        }

        $user = $this->resolveUser($request);
        $importedCount = 0;

        DB::beginTransaction();
        try {
            foreach ($settingsData as $item) {
                if (! is_array($item) || empty($item['key'])) {
                    continue;
                }

                $key = $item['key'];
                $setting = Setting::withTrashed()->firstWhere('key', $key) ?? new Setting(['key' => $key]);

                if ($setting->trashed()) {
                    $setting->restore();
                }

                $setting->group = $item['group'] ?? ($setting->group ?: 'general');
                $setting->type = $item['type'] ?? ($setting->type ?: Setting::TYPE_STRING);
                $setting->label = $item['label'] ?? ($setting->label ?: Str::headline(str_replace('.', ' ', $key)));
                if (isset($item['options'])) {
                    $setting->options = $item['options'];
                }
                if (isset($item['status'])) {
                    $setting->status = (bool) $item['status'];
                }

                // If value is provided (and not null for secrets), set it
                if (array_key_exists('value', $item) && $item['value'] !== null) {
                    $val = $item['value'];
                    if (is_array($val)) {
                        $setting->value = json_encode($val);
                    } elseif (is_bool($val)) {
                        $setting->value = $val ? '1' : '0';
                    } else {
                        $setting->value = (string) $val;
                    }
                }

                $setting->updated_by = $user?->id;
                $setting->save();
                $importedCount++;
            }

            Setting::flushCache();

            AuditLogService::log(
                event: 'settings.imported',
                description: "Imported {$importedCount} settings from JSON payload",
                userId: $user?->id
            );

            DB::commit();

            return $this->success([
                'imported_count' => $importedCount,
            ], [
                'message' => "Successfully imported and synchronized {$importedCount} settings.",
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Settings import error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('IMPORT_FAILED', 'Failed to import settings: '.$e->getMessage(), 500);
        }
    }

    /**
     * Upload branding logo, favicon, or asset file.
     */
    public function uploadAsset(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'file' => ['required', 'file', 'max:10240', 'mimes:png,jpg,jpeg,webp,svg,ico,pdf'],
            'key' => ['nullable', 'string', 'max:190'],
            'folder' => ['nullable', 'string', 'max:50'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'File upload validation failed.', 422, $validator->errors()->toArray());
        }

        $file = $request->file('file');
        $key = $request->input('key');
        $folder = $request->input('folder', 'settings/branding');
        $user = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            $prefix = $key ? Str::slug(str_replace('.', '_', $key), '_') : 'asset';
            $oldPath = null;

            if ($key) {
                $existing = Setting::firstWhere('key', $key);
                if ($existing && $existing->rawValue()) {
                    $oldPath = $existing->rawValue();
                }
            }

            $storagePath = FileUploadService::store(
                file: $file,
                folder: $folder,
                prefix: $prefix,
                oldPath: $oldPath
            );

            $publicUrl = FileUploadService::url($storagePath);

            // Automatically associate with setting if key provided
            if ($key) {
                $setting = Setting::withTrashed()->firstWhere('key', $key);
                if (! $setting) {
                    $group = str_contains($key, '.') ? explode('.', $key)[0] : 'general';
                    $setting = new Setting([
                        'key' => $key,
                        'group' => $group,
                        'type' => Setting::TYPE_IMAGE,
                        'label' => Str::headline(str_replace('.', ' ', $key)),
                        'status' => true,
                        'created_by' => $user?->id,
                    ]);
                }

                if ($setting->trashed()) {
                    $setting->restore();
                }

                $setting->value = $storagePath;
                $setting->updated_by = $user?->id;
                $setting->save();

                Setting::flushCache();
            }

            AuditLogService::log(
                event: 'settings.asset_uploaded',
                description: "Uploaded asset for setting key '".($key ?? 'unassigned')."' to {$storagePath}",
                userId: $user?->id
            );

            DB::commit();

            return $this->success([
                'key' => $key,
                'path' => $storagePath,
                'url' => $publicUrl,
                'original_name' => $file->getClientOriginalName(),
                'size_bytes' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ], [
                'message' => 'Asset uploaded successfully.',
            ], 201);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Settings asset upload error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('UPLOAD_FAILED', 'Failed to upload setting asset: '.$e->getMessage(), 500);
        }
    }

    /**
     * Send a diagnostic test email using current SMTP configuration.
     */
    public function testEmail(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipient_email' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Recipient email is invalid.', 422, $validator->errors()->toArray());
        }

        $recipient = $request->input('recipient_email');
        $user = $this->resolveUser($request);

        try {
            $emailService = app(EmailService::class);
            $subject = 'SNTCSSC MIS — Diagnostic SMTP Test Email';
            $body = '<h3>SNTCSSC MIS System Test</h3><p>This is a verified test email dispatched from the System Settings API. SMTP parameters are functioning correctly.</p><p>Timestamp: '.Carbon::now()->toIso8601String().'</p>';

            $result = $emailService->sendDirect($recipient, $subject, $body);

            AuditLogService::log(
                event: 'settings.test_email_sent',
                description: "Dispatched diagnostic test email to {$recipient}",
                userId: $user?->id
            );

            if (! empty($result['success'])) {
                return $this->success([
                    'recipient' => $recipient,
                    'sent' => true,
                    'timestamp' => Carbon::now()->toIso8601String(),
                ], ['message' => "Test email dispatched successfully to {$recipient}."]);
            }

            return $this->error('EMAIL_DISPATCH_FAILED', $result['error'] ?? 'Mail dispatch failed.', 500);
        } catch (Throwable $e) {
            Log::error('Test email dispatch error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('EMAIL_EXCEPTION', 'Failed to send test email: '.$e->getMessage(), 500);
        }
    }

    /**
     * Send a diagnostic test SMS using current SMS gateway configuration.
     */
    public function testSms(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipient_phone' => ['required', 'string', 'min:10', 'max:15'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Recipient phone number is invalid.', 422, $validator->errors()->toArray());
        }

        $phone = $request->input('recipient_phone');
        $user = $this->resolveUser($request);

        try {
            $smsService = app(SmsService::class);
            $message = 'SNTCSSC MIS: Diagnostic test SMS dispatched from System Settings API at '.Carbon::now()->format('h:i A');

            $result = $smsService->sendDirect($phone, $message);

            AuditLogService::log(
                event: 'settings.test_sms_sent',
                description: "Dispatched diagnostic test SMS to {$phone}",
                userId: $user?->id
            );

            if (! empty($result['success'])) {
                return $this->success([
                    'recipient' => $phone,
                    'sent' => true,
                    'driver' => Setting::get('sms.driver', 'log'),
                    'timestamp' => Carbon::now()->toIso8601String(),
                ], ['message' => "Test SMS dispatched successfully to {$phone}."]);
            }

            return $this->error('SMS_DISPATCH_FAILED', $result['error'] ?? 'SMS dispatch failed.', 500);
        } catch (Throwable $e) {
            Log::error('Test SMS dispatch error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('SMS_EXCEPTION', 'Failed to send test SMS: '.$e->getMessage(), 500);
        }
    }

    /**
     * Toggle Maintenance Mode status and configure message and allowed IPs.
     */
    /**
     * Retrieve real-time storage statistics, provider status, and disk telemetry.
     */
    public function storageOverview(Request $request): JsonResponse
    {
        try {
            $stats = StorageService::getStorageStatistics();

            return $this->success($stats, [
                'timestamp' => Carbon::now()->toIso8601String(),
                'providers' => StorageService::getProviderPresets(),
            ]);
        } catch (Throwable $e) {
            Log::error('Storage overview API error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('STORAGE_STATS_FAILED', 'Failed to retrieve storage statistics: '.$e->getMessage(), 500);
        }
    }

    /**
     * Test Amazon S3 or S3-compatible cloud storage connection with live probe diagnostics.
     */
    public function testStorage(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            's3_key' => ['nullable', 'string', 'max:255'],
            's3_secret' => ['nullable', 'string', 'max:255'],
            's3_region' => ['nullable', 'string', 'max:100'],
            's3_bucket' => ['nullable', 'string', 'max:255'],
            's3_endpoint' => ['nullable', 'string', 'max:500'],
            's3_url' => ['nullable', 'string', 'max:500'],
            's3_use_path_style_endpoint' => ['nullable', 'boolean'],
            'provider' => ['nullable', 'string', 'in:aws,r2,minio,wasabi,do_spaces,backblaze,custom'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid storage test parameters.', 422, $validator->errors()->toArray());
        }

        try {
            $overrides = array_filter($request->only([
                's3_key', 's3_secret', 's3_region', 's3_bucket', 's3_endpoint', 's3_url', 's3_use_path_style_endpoint', 'provider',
            ]), fn ($val) => $val !== null && $val !== '');

            $result = StorageService::testConnection($overrides);

            $user = $this->resolveUser($request);
            AuditLogService::log(
                event: 'settings.storage_tested',
                description: 'Tested S3 storage connection: '.($result['success'] ? 'SUCCESS' : 'FAILED'),
                newValues: [
                    'success' => $result['success'],
                    'latency_ms' => $result['latency_ms'],
                    'message' => $result['message'],
                ],
                userId: $user?->id
            );

            if (! $result['success']) {
                return $this->error('STORAGE_TEST_FAILED', $result['message'], 400, $result);
            }

            return $this->success($result, [
                'message' => $result['message'],
                'timestamp' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('Storage test API error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('STORAGE_TEST_ERROR', 'Storage test encountered an unexpected error: '.$e->getMessage(), 500);
        }
    }

    /**
     * List files from specified disk (e.g. public or s3).
     */
    public function storageFiles(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'disk' => ['nullable', 'string', 'in:public,s3,local'],
            'directory' => ['nullable', 'string', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid file query parameters.', 422, $validator->errors()->toArray());
        }

        try {
            $disk = (string) $request->input('disk', Setting::get('storage.default_disk', 'public'));
            $directory = (string) $request->input('directory', '');
            $limit = (int) $request->input('limit', 40);

            $files = StorageService::listFiles($disk, $directory, $limit);

            return $this->success($files, [
                'disk' => $disk,
                'count' => count($files),
                'limit' => $limit,
            ]);
        } catch (Throwable $e) {
            Log::error('Storage files API error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('STORAGE_FILES_FAILED', 'Failed to retrieve storage files: '.$e->getMessage(), 500);
        }
    }

    /**
     * Delete a file from storage.
     */
    public function deleteStorageFile(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'path' => ['required', 'string', 'max:500'],
            'disk' => ['nullable', 'string', 'in:public,s3,local'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid file deletion request.', 422, $validator->errors()->toArray());
        }

        try {
            $path = (string) $request->input('path');
            $disk = (string) $request->input('disk', Setting::get('storage.default_disk', 'public'));
            $user = $this->resolveUser($request);

            $deleted = StorageService::deleteFile($path, $disk, $user);

            if (! $deleted) {
                return $this->error('FILE_NOT_FOUND', 'File not found on specified storage disk.', 404);
            }

            return $this->success([
                'deleted' => true,
                'path' => $path,
                'disk' => $disk,
            ], [
                'message' => 'File successfully deleted from storage.',
            ]);
        } catch (Throwable $e) {
            Log::error('Storage file deletion error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('STORAGE_DELETE_FAILED', 'Failed to delete file from storage: '.$e->getMessage(), 500);
        }
    }

    /**
     * Toggle Maintenance Mode status and configure message and allowed IPs.
     */
    public function toggleMaintenance(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'enabled' => ['required', 'boolean'],
            'message' => ['nullable', 'string', 'max:500'],
            'allowed_ips' => ['nullable', 'array'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid maintenance mode configuration parameters.', 422, $validator->errors()->toArray());
        }

        $enabled = (bool) $request->input('enabled');
        $message = $request->input('message');
        $allowedIps = $request->input('allowed_ips');
        $user = $this->resolveUser($request);

        DB::beginTransaction();
        try {
            Setting::set('system.maintenance_mode', $enabled, $user?->id);

            if ($message !== null) {
                Setting::set('system.maintenance_message', $message, $user?->id);
            }

            if ($allowedIps !== null) {
                Setting::set('system.maintenance_allowed_ips', $allowedIps, $user?->id);
            }

            Setting::flushCache();

            AuditLogService::log(
                event: 'settings.maintenance_toggled',
                description: 'Maintenance mode '.($enabled ? 'ENABLED' : 'DISABLED'),
                newValues: [
                    'enabled' => $enabled,
                    'message' => $message,
                    'allowed_ips' => $allowedIps,
                ],
                userId: $user?->id
            );

            DB::commit();

            return $this->success([
                'maintenance_mode' => $enabled,
                'message' => $message ?? Setting::get('system.maintenance_message', 'System is undergoing scheduled maintenance.'),
                'allowed_ips' => $allowedIps ?? Setting::get('system.maintenance_allowed_ips', []),
                'updated_at' => Carbon::now()->toIso8601String(),
            ], [
                'message' => 'Maintenance mode '.($enabled ? 'enabled' : 'disabled').' successfully.',
            ]);
        } catch (Throwable $e) {
            DB::rollBack();
            Log::error('Maintenance mode toggle error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('MAINTENANCE_TOGGLE_FAILED', 'Failed to toggle maintenance mode: '.$e->getMessage(), 500);
        }
    }

    /**
     * Tailored Platform Bootstrap for native Mobile apps, SPAs, Desktop clients, and Kiosks.
     */
    public function platformBootstrap(Request $request, string $platform): JsonResponse
    {
        $platform = strtolower(trim($platform));
        $validPlatforms = ['mobile', 'android', 'ios', 'web', 'desktop', 'windows', 'macos', 'linux', 'kiosk'];

        if (! in_array($platform, $validPlatforms, true)) {
            return $this->error('INVALID_PLATFORM', "Unsupported client platform '{$platform}'. Supported platforms: ".implode(', ', $validPlatforms).'.', 422);
        }

        try {
            $baseBootstrap = $this->publicBootstrap($request)->getData(true);
            $baseData = $baseBootstrap['data'] ?? [];

            $platformGroup = match ($platform) {
                'android', 'ios' => 'mobile',
                'windows', 'macos', 'linux' => 'desktop',
                default => $platform,
            };

            $platformConfig = match ($platformGroup) {
                'mobile' => [
                    'platform' => $platform,
                    'min_supported_version' => (string) Setting::get('system.min_mobile_version', '1.0.0'),
                    'latest_version' => (string) Setting::get('system.latest_mobile_version', '1.2.0'),
                    'force_update' => (bool) Setting::get('system.force_mobile_update', false),
                    'play_store_url' => (string) Setting::get('system.play_store_url', 'https://play.google.com/store/apps/details?id=in.sntcssc.mis'),
                    'app_store_url' => (string) Setting::get('system.app_store_url', 'https://apps.apple.com/app/sntcssc-mis/id123456789'),
                    'push_notifications' => [
                        'enabled' => (bool) Setting::get('system.push_notifications_enabled', true),
                        'fcm_sender_id' => (string) Setting::get('system.fcm_sender_id', ''),
                    ],
                    'biometrics' => [
                        'fingerprint_allowed' => true,
                        'face_id_allowed' => true,
                    ],
                    'offline_sync' => [
                        'cache_ttl_seconds' => (int) Setting::get('system.mobile_cache_ttl', 3600),
                        'max_offline_records' => 500,
                    ],
                    'deep_link_scheme' => 'sntcssc://',
                ],
                'web' => [
                    'platform' => 'web',
                    'meta_title' => (string) Setting::get('seo.meta_title', Setting::siteName()),
                    'meta_description' => (string) Setting::get('seo.meta_description', Setting::get('general.site_tagline', '')),
                    'canonical_url' => Setting::get('seo.canonical_url'),
                    'og_image_url' => Setting::ogImageUrl(),
                    'theme' => [
                        'primary_color' => (string) Setting::get('appearance.primary_color', '#10b981'),
                        'font_family' => (string) Setting::get('appearance.font_family', 'Inter'),
                        'dark_mode' => (string) Setting::get('appearance.dark_mode', 'system'),
                        'sidebar_theme' => (string) Setting::get('appearance.sidebar_theme', 'default'),
                    ],
                    'pwa' => [
                        'name' => Setting::siteName(),
                        'short_name' => Setting::appName(),
                        'start_url' => '/',
                        'display' => 'standalone',
                        'theme_color' => (string) Setting::get('appearance.primary_color', '#10b981'),
                        'background_color' => '#ffffff',
                    ],
                    'cdn_url' => config('app.asset_url') ?: url('/'),
                ],
                'desktop' => [
                    'platform' => $platform,
                    'min_supported_version' => (string) Setting::get('system.min_desktop_version', '1.0.0'),
                    'latest_version' => (string) Setting::get('system.latest_desktop_version', '1.1.0'),
                    'update_channel' => (string) Setting::get('system.desktop_update_channel', 'stable'),
                    'download_urls' => [
                        'windows' => (string) Setting::get('system.desktop_windows_url', 'https://mis.sntcssc.in/downloads/desktop/windows/latest.exe'),
                        'mac' => (string) Setting::get('system.desktop_mac_url', 'https://mis.sntcssc.in/downloads/desktop/mac/latest.dmg'),
                        'linux' => (string) Setting::get('system.desktop_linux_url', 'https://mis.sntcssc.in/downloads/desktop/linux/latest.AppImage'),
                    ],
                    'system_tray_enabled' => true,
                    'hardware_acceleration' => true,
                    'close_to_tray' => true,
                ],
                'kiosk' => [
                    'platform' => 'kiosk',
                    'refresh_interval_seconds' => (int) Setting::get('system.kiosk_refresh_interval', 300),
                    'idle_timeout_seconds' => (int) Setting::get('system.kiosk_idle_timeout', 60),
                    'screen_lock_enabled' => true,
                    'heartbeat_interval_seconds' => 30,
                    'kiosk_mode' => true,
                ],
                default => [],
            };

            $baseData['platform_config'] = $platformConfig;

            return $this->success($baseData, [
                'platform' => $platform,
                'category' => $platformGroup,
                'server_time' => Carbon::now()->toIso8601String(),
                'api_version' => 'v1',
            ]);
        } catch (Throwable $e) {
            Log::error('Platform bootstrap settings error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PLATFORM_BOOTSTRAP_FAILED', 'Unable to retrieve platform bootstrap: '.$e->getMessage(), 500);
        }
    }

    /**
     * Cross-platform version checking and force-update validation endpoint.
     */
    public function versionCheck(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'platform' => ['required', 'string', 'in:android,ios,mobile,web,windows,macos,linux,desktop'],
            'current_version' => ['required', 'string', 'max:50'],
            'build_number' => ['nullable', 'integer'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid version check parameters.', 422, $validator->errors()->toArray());
        }

        try {
            $platform = strtolower((string) $request->input('platform'));
            $currentVersion = trim((string) $request->input('current_version'));
            $buildNumber = $request->input('build_number');

            $isMobile = in_array($platform, ['android', 'ios', 'mobile'], true);
            $isDesktop = in_array($platform, ['windows', 'macos', 'linux', 'desktop'], true);

            if ($isMobile) {
                $minVersion = (string) Setting::get('system.min_mobile_version', '1.0.0');
                $latestVersion = (string) Setting::get('system.latest_mobile_version', '1.2.0');
                $forceUpdateFlag = (bool) Setting::get('system.force_mobile_update', false);
                $downloadUrl = $platform === 'ios'
                    ? (string) Setting::get('system.app_store_url', 'https://apps.apple.com/app/sntcssc-mis/id123456789')
                    : (string) Setting::get('system.play_store_url', 'https://play.google.com/store/apps/details?id=in.sntcssc.mis');
            } elseif ($isDesktop) {
                $minVersion = (string) Setting::get('system.min_desktop_version', '1.0.0');
                $latestVersion = (string) Setting::get('system.latest_desktop_version', '1.1.0');
                $forceUpdateFlag = (bool) Setting::get('system.force_desktop_update', false);
                $downloadUrl = match ($platform) {
                    'mac', 'macos' => (string) Setting::get('system.desktop_mac_url', 'https://mis.sntcssc.in/downloads/desktop/mac/latest.dmg'),
                    'linux' => (string) Setting::get('system.desktop_linux_url', 'https://mis.sntcssc.in/downloads/desktop/linux/latest.AppImage'),
                    default => (string) Setting::get('system.desktop_windows_url', 'https://mis.sntcssc.in/downloads/desktop/windows/latest.exe'),
                };
            } else {
                // Web
                $minVersion = '1.0.0';
                $latestVersion = (string) Setting::get('system.app_version', '1.0.0');
                $forceUpdateFlag = false;
                $downloadUrl = url('/');
            }

            $updateAvailable = version_compare($currentVersion, $latestVersion, '<');
            $belowMinimum = version_compare($currentVersion, $minVersion, '<');
            $updateRequired = $belowMinimum || ($updateAvailable && $forceUpdateFlag);

            return $this->success([
                'platform' => $platform,
                'current_version' => $currentVersion,
                'build_number' => $buildNumber,
                'min_supported_version' => $minVersion,
                'latest_version' => $latestVersion,
                'update_available' => $updateAvailable,
                'update_required' => $updateRequired,
                'force_update' => $forceUpdateFlag,
                'download_url' => $downloadUrl,
                'release_notes' => 'Stability enhancements, improved cloud storage support, and multi-lingual synchronization.',
                'checked_at' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('Version check error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('VERSION_CHECK_FAILED', 'Unable to complete version verification: '.$e->getMessage(), 500);
        }
    }

    /**
     * Categorized client feature flags catalog.
     */
    public function features(Request $request): JsonResponse
    {
        try {
            $categories = [
                'communication' => [
                    'live_chat' => (bool) Setting::get('chat.enabled', true),
                    'voice_calls' => (bool) Setting::get('chat.voice_call_enabled', true),
                    'video_calls' => (bool) Setting::get('chat.video_call_enabled', true),
                    'online_meetings' => true,
                    'sms_service' => (bool) Setting::get('sms.enabled', false),
                    'email_service' => (bool) Setting::get('email.is_enabled', true),
                ],
                'academics' => [
                    'courses' => true,
                    'batches' => true,
                    'study_materials' => true,
                    'examinations' => true,
                    'student_tests' => true,
                    'mock_interviews' => true,
                ],
                'facilities' => [
                    'library' => true,
                    'library_fines' => (bool) Setting::get('library.fines_enabled', true),
                    'hostel' => true,
                    'biometrics' => true,
                ],
                'payments' => [
                    'online_payments' => (bool) Setting::get('payment.enabled', false),
                    'offline_payments' => (bool) Setting::get('payment.offline_enabled', true),
                ],
                'cloud_storage' => [
                    'default_disk' => (string) Setting::get('storage.default_disk', 'public'),
                    'provider' => (string) Setting::get('storage.provider', 'aws'),
                    'direct_s3_upload' => (string) Setting::get('storage.default_disk', 'public') === 's3',
                ],
                'system' => [
                    'maintenance_mode' => (bool) Setting::get('system.maintenance_mode', false),
                    'debug_mode' => (bool) Setting::get('system.debug_mode', false),
                ],
            ];

            return $this->success($categories, [
                'server_time' => Carbon::now()->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            Log::error('Feature flags fetch error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('FEATURES_FETCH_FAILED', 'Unable to retrieve feature flags: '.$e->getMessage(), 500);
        }
    }

    /**
     * Pre-flight direct cloud S3 upload authorization or backend proxy endpoint resolution.
     */
    public function signedUploadUrl(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'filename' => ['required', 'string', 'max:255'],
            'content_type' => ['required', 'string', 'max:100'],
            'size_bytes' => ['required', 'integer', 'min:1'],
            'directory' => ['nullable', 'string', 'max:100', 'regex:/^[a-zA-Z0-9_\-\/]+$/'],
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Invalid upload pre-flight parameters.', 422, $validator->errors()->toArray());
        }

        try {
            $filename = (string) $request->input('filename');
            $contentType = (string) $request->input('content_type');
            $sizeBytes = (int) $request->input('size_bytes');
            $directory = trim((string) $request->input('directory', 'uploads'), '/');

            // Enforce max upload size limit
            $maxMb = (int) Setting::get('storage.max_upload_size_mb', (int) Setting::get('system.max_upload_size', 25));
            $maxBytes = $maxMb * 1024 * 1024;

            if ($sizeBytes > $maxBytes) {
                return $this->error('FILE_TOO_LARGE', "File size exceeds the allowed system threshold of {$maxMb} MB.", 422);
            }

            // Enforce allowed file extensions
            $allowedExts = array_map('trim', explode(',', (string) Setting::get('storage.allowed_file_types', 'pdf,doc,docx,xls,xlsx,ppt,pptx,jpg,jpeg,png,webp,svg,zip,mp4')));
            $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

            if (! empty($allowedExts) && ! in_array($ext, $allowedExts, true)) {
                return $this->error('FILE_TYPE_NOT_ALLOWED', "File type '.{$ext}' is not permitted by system policy.", 422);
            }

            $datePath = date('Y/m');
            $uniqueSlug = Str::uuid()->toString().'.'.$ext;
            $targetPath = "{$directory}/{$datePath}/{$uniqueSlug}";

            $defaultDisk = (string) Setting::get('storage.default_disk', 'public');
            $user = $this->resolveUser($request);

            if ($defaultDisk === 's3') {
                $presigned = StorageService::createPresignedUploadUrl($targetPath, $contentType, 15);
                if ($presigned !== null) {
                    AuditLogService::log(
                        event: 'storage_presigned_url_generated',
                        description: "Generated direct S3 pre-signed upload URL for '{$filename}'.",
                        newValues: [
                            'path' => $targetPath,
                            'filename' => $filename,
                            'content_type' => $contentType,
                            'size_bytes' => $sizeBytes,
                        ],
                        userId: $user?->id
                    );

                    return $this->success([
                        'upload_mode' => 'direct_s3_presigned',
                        'upload_url' => $presigned['upload_url'],
                        'method' => $presigned['method'],
                        'headers' => $presigned['headers'],
                        'target_path' => $targetPath,
                        'public_url' => FileUploadService::url($targetPath, 's3'),
                        'expires_at' => $presigned['expires_at'],
                    ]);
                }
            }

            // Fallback for public local disk or when S3 presigned is not configured
            return $this->success([
                'upload_mode' => 'multipart_api',
                'upload_url' => url('/api/v1/settings/assets'),
                'method' => 'POST',
                'field_name' => 'file',
                'extra_fields' => [
                    'folder' => "{$directory}/{$datePath}",
                ],
                'target_path' => $targetPath,
                'public_url' => FileUploadService::url($targetPath, 'public'),
                'max_bytes' => $maxBytes,
            ]);
        } catch (Throwable $e) {
            Log::error('Signed upload URL preflight error: '.$e->getMessage(), ['exception' => $e]);

            return $this->error('PREFLIGHT_FAILED', 'Unable to generate upload authorization: '.$e->getMessage(), 500);
        }
    }
}
