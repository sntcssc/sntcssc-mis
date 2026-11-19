<?php

use App\Models\Course;
use App\Models\Section;
use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\StudentIdGeneratorService;
use App\Support\Toast;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('System & Maintenance Settings')] class extends Component {
    public array $form = [
        'maintenance_mode' => false,
        'maintenance_secret' => '',
        'maintenance_token' => '',
        'maintenance_message' => 'The application is currently undergoing scheduled maintenance. Please check back shortly.',
        'debug_mode' => false,
        'allow_user_impersonation' => true,
        'allow_incomplete_student_dashboard' => true,
        'allow_account_deletion' => false,
        'app_name' => 'SNT CSSC MIS',
        'app_version' => '1.0.0',
        'developed_by' => 'SNT CSSC IT Team',
        'developer_contact' => 'dev@sntcssc.in',
        'developer_github' => 'https://github.com/sntcssc',
        'developer_website' => 'https://sntcssc.in',
        'max_upload_size' => 10,
        'session_lifetime' => 120,
        'cache_driver' => 'file',
        'test_leaderboard_limit' => 10,
    ];

    // Student ID Configuration State (student_id_config)
    public array $studentIdConfig = [
        'sequence_length' => 4,
        'default_fallback_identifier' => '0',
        'course_identifiers' => [],
        'section_identifiers' => [],
    ];

    public string $newCourseCode = '';
    public string $newCourseIdentifier = '';
    public string $newSectionName = '';
    public string $newSectionIdentifier = '';

    // Live Simulator Controls
    public string $previewYear = '2026';
    public string $previewSelectedCourse = 'CC';
    public string $previewSelectedSection = '';

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'system')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "system.{$key}";
            if (isset($settings[$fullKey])) {
                $setting = $settings[$fullKey];
                if ($setting->type === Setting::TYPE_BOOLEAN) {
                    $this->form[$key] = (bool) $setting->typed();
                } elseif ($setting->type === Setting::TYPE_NUMBER) {
                    $this->form[$key] = (int) $setting->typed();
                } else {
                    $this->form[$key] = (string) ($setting->rawValue() ?? $default);
                }
            }
        }

        if (empty($this->form['maintenance_secret']) && ! empty($this->form['maintenance_token'])) {
            $this->form['maintenance_secret'] = $this->form['maintenance_token'];
        }
        if (empty($this->form['maintenance_secret'])) {
            $this->form['maintenance_secret'] = Str::random(16);
        }
        $this->form['maintenance_token'] = $this->form['maintenance_secret'];

        if ($leadLimit = Setting::get('test_leaderboard_limit')) {
            $this->form['test_leaderboard_limit'] = (int) $leadLimit;
        }

        // Load Student ID Config
        $rawConfig = Setting::get('student_id_config');
        if (is_string($rawConfig)) {
            $rawConfig = json_decode($rawConfig, true);
        }
        if (! is_array($rawConfig) || empty($rawConfig)) {
            $rawConfig = [
                'sequence_length' => 4,
                'default_fallback_identifier' => '0',
                'course_identifiers' => [
                    'CC' => '1',
                    'PCC' => '3',
                    'MGP' => '4',
                    'ITP' => '5',
                    'WTS' => '6',
                ],
                'section_identifiers' => [
                    'Section A' => '1',
                    'Section B' => '2',
                    'Section C' => '3',
                ],
            ];
        }

        $this->studentIdConfig = [
            'sequence_length' => max(3, min(5, (int) ($rawConfig['sequence_length'] ?? 4))),
            'default_fallback_identifier' => (string) ($rawConfig['default_fallback_identifier'] ?? '0'),
            'course_identifiers' => is_array($rawConfig['course_identifiers'] ?? null) ? $rawConfig['course_identifiers'] : [],
            'section_identifiers' => is_array($rawConfig['section_identifiers'] ?? null) ? $rawConfig['section_identifiers'] : [],
        ];

        $this->previewYear = (string) date('Y');
        $firstCourse = array_key_first($this->studentIdConfig['course_identifiers']) ?? 'CC';
        $this->previewSelectedCourse = (string) $firstCourse;
    }

    public function generateNewSecret(): void
    {
        $this->form['maintenance_secret'] = Str::random(24);
        $this->form['maintenance_token'] = $this->form['maintenance_secret'];
        Toast::dispatch($this, 'info', __('New secret bypass token generated. Remember to save changes.'));
    }

    public function regenerateToken(): void
    {
        $this->generateNewSecret();
    }

    public function addCourseIdentifier(): void
    {
        $code = strtoupper(trim($this->newCourseCode));
        $identifier = trim($this->newCourseIdentifier);

        if ($code === '' || $identifier === '') {
            Toast::dispatch($this, 'error', __('Please enter both Course Code and Identifier digit.'));

            return;
        }

        if (! is_numeric($identifier) || strlen($identifier) > 2) {
            Toast::dispatch($this, 'error', __('Identifier must be a 1 or 2 digit number.'));

            return;
        }

        if (isset($this->studentIdConfig['course_identifiers'][$code])) {
            Toast::dispatch($this, 'error', __('Course identifier already configured for this course code.'));

            return;
        }

        $this->studentIdConfig['course_identifiers'][$code] = $identifier;
        $this->newCourseCode = '';
        $this->newCourseIdentifier = '';
        Toast::dispatch($this, 'success', __('Added course identifier: :code => :id', ['code' => $code, 'id' => $identifier]));
    }

    public function removeCourseIdentifier(string $code): void
    {
        unset($this->studentIdConfig['course_identifiers'][$code]);
        if ($this->previewSelectedCourse === $code) {
            $this->previewSelectedCourse = (string) (array_key_first($this->studentIdConfig['course_identifiers']) ?? '');
        }
        Toast::dispatch($this, 'info', __('Removed course identifier mapping for :code', ['code' => $code]));
    }

    public function addSectionIdentifier(): void
    {
        $name = trim($this->newSectionName);
        $identifier = trim($this->newSectionIdentifier);

        if ($name === '' || $identifier === '') {
            Toast::dispatch($this, 'error', __('Please enter both Section Name/Code and Identifier digit.'));

            return;
        }

        if (! is_numeric($identifier) || strlen($identifier) > 2) {
            Toast::dispatch($this, 'error', __('Identifier must be a 1 or 2 digit number.'));

            return;
        }

        if (isset($this->studentIdConfig['section_identifiers'][$name])) {
            Toast::dispatch($this, 'error', __('Section identifier already configured for this section name.'));

            return;
        }

        $this->studentIdConfig['section_identifiers'][$name] = $identifier;
        $this->newSectionName = '';
        $this->newSectionIdentifier = '';
        Toast::dispatch($this, 'success', __('Added section identifier: :name => :id', ['name' => $name, 'id' => $identifier]));
    }

    public function removeSectionIdentifier(string $name): void
    {
        unset($this->studentIdConfig['section_identifiers'][$name]);
        if ($this->previewSelectedSection === $name) {
            $this->previewSelectedSection = '';
        }
        Toast::dispatch($this, 'info', __('Removed section identifier mapping for :name', ['name' => $name]));
    }

    public function syncCoursesFromDb(): void
    {
        $courses = Course::orderBy('code')->get();
        $added = 0;
        foreach ($courses as $c) {
            $code = strtoupper(trim($c->code));
            if (! isset($this->studentIdConfig['course_identifiers'][$code])) {
                $idCode = $c->identifier_code ?: '1';
                $this->studentIdConfig['course_identifiers'][$code] = (string) $idCode;
                $added++;
            }
        }
        Toast::dispatch($this, 'success', __('Synced :count active courses from database into identifier mappings.', ['count' => $added]));
    }

    public function syncSectionsFromDb(): void
    {
        $sections = Section::orderBy('name')->get();
        $added = 0;
        foreach ($sections as $s) {
            $name = trim($s->name);
            if (! isset($this->studentIdConfig['section_identifiers'][$name])) {
                $this->studentIdConfig['section_identifiers'][$name] = (string) ($s->code ?: '1');
                $added++;
            }
        }
        Toast::dispatch($this, 'success', __('Synced :count sections from database into identifier mappings.', ['count' => $added]));
    }

    public function resetStudentIdConfigToDefaults(): void
    {
        $this->studentIdConfig = [
            'sequence_length' => 4,
            'default_fallback_identifier' => '0',
            'course_identifiers' => [
                'CC' => '1',
                'PCC' => '3',
                'MGP' => '4',
                'ITP' => '5',
                'WTS' => '6',
            ],
            'section_identifiers' => [
                'Section A' => '1',
                'Section B' => '2',
                'Section C' => '3',
            ],
        ];
        $this->previewSelectedCourse = 'CC';
        $this->previewSelectedSection = '';
        Toast::dispatch($this, 'info', __('Student ID configuration reset to default schema. Remember to save changes.'));
    }

    public function saveStudentIdConfig(): void
    {
        $this->validate([
            'studentIdConfig.sequence_length' => ['required', 'integer', 'min:3', 'max:5'],
            'studentIdConfig.default_fallback_identifier' => ['required', 'string', 'max:2'],
            'studentIdConfig.course_identifiers' => ['array'],
            'studentIdConfig.section_identifiers' => ['array'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();
                $oldConfig = Setting::get('student_id_config', []);

                Setting::set('student_id_config', $this->studentIdConfig, $userId);
                Setting::flushCache();

                AuditLogService::log(
                    event: 'student_id_config_updated',
                    description: 'Updated Student ID Generation Rules and Identifier Mappings.',
                    oldValues: is_array($oldConfig) ? $oldConfig : [],
                    newValues: $this->studentIdConfig,
                    userId: $userId
                );
            });

            Toast::dispatch($this, 'success', __('Student ID generation configuration saved successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save student ID configuration: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save student ID configuration.'));
        }
    }

    #[Computed]
    public function previewGeneratedId(): array
    {
        $year = ! empty($this->previewYear) ? $this->previewYear : date('Y');
        if (preg_match('/^(\d{4})/', $year, $m)) {
            $year = $m[1];
        } else {
            $year = date('Y');
        }

        $identifier = $this->studentIdConfig['default_fallback_identifier'] ?? '0';

        // Check if a section is chosen
        if (! empty($this->previewSelectedSection)) {
            if (isset($this->studentIdConfig['section_identifiers'][$this->previewSelectedSection])) {
                $identifier = (string) $this->studentIdConfig['section_identifiers'][$this->previewSelectedSection];
            } else {
                $identifier = StudentIdGeneratorService::sanitizeIdentifier($this->previewSelectedSection);
            }
        } elseif (! empty($this->previewSelectedCourse)) {
            if (isset($this->studentIdConfig['course_identifiers'][$this->previewSelectedCourse])) {
                $identifier = (string) $this->studentIdConfig['course_identifiers'][$this->previewSelectedCourse];
            }
        }

        $seqLength = max(3, min(5, (int) ($this->studentIdConfig['sequence_length'] ?? 4)));
        $sampleSequence1 = str_pad('1', $seqLength, '0', STR_PAD_LEFT);
        $sampleSequence2 = str_pad('42', $seqLength, '0', STR_PAD_LEFT);

        $sampleId1 = $year.$identifier.$sampleSequence1;
        $sampleId2 = $year.$identifier.$sampleSequence2;

        return [
            'year' => $year,
            'identifier' => $identifier,
            'seq_length' => $seqLength,
            'sample_id' => $sampleId1,
            'sample_id_secondary' => $sampleId2,
            'length' => strlen($sampleId1),
            'max_capacity' => number_format((int) str_repeat('9', $seqLength)),
            'is_valid' => strlen($sampleId1) <= 10,
        ];
    }

    #[Computed]
    public function availableCourses()
    {
        return Course::orderBy('code')->get();
    }

    #[Computed]
    public function availableSections()
    {
        return Section::orderBy('name')->get();
    }

    public function save(): void
    {
        if ($this->form['maintenance_token'] !== '' && $this->form['maintenance_token'] !== $this->form['maintenance_secret']) {
            $this->form['maintenance_secret'] = $this->form['maintenance_token'];
        } else {
            $this->form['maintenance_token'] = $this->form['maintenance_secret'];
        }

        $this->validate([
            'form.maintenance_mode' => ['boolean'],
            'form.maintenance_secret' => ['nullable', 'string', 'max:100'],
            'form.maintenance_token' => ['nullable', 'string', 'max:100'],
            'form.maintenance_message' => ['nullable', 'string', 'max:500'],
            'form.debug_mode' => ['boolean'],
            'form.allow_user_impersonation' => ['boolean'],
            'form.allow_incomplete_student_dashboard' => ['boolean'],
            'form.allow_account_deletion' => ['boolean'],
            'form.app_name' => ['required', 'string', 'max:255'],
            'form.app_version' => ['required', 'string', 'max:50'],
            'form.developed_by' => ['nullable', 'string', 'max:255'],
            'form.developer_contact' => ['nullable', 'string', 'max:255'],
            'form.developer_github' => ['nullable', 'url', 'max:255'],
            'form.developer_website' => ['nullable', 'url', 'max:255'],
            'form.max_upload_size' => ['required', 'numeric', 'min:1', 'max:100'],
            'form.session_lifetime' => ['required', 'numeric', 'min:1', 'max:1440'],
            'form.cache_driver' => ['required', 'string', 'in:file,redis,database,array'],

            'studentIdConfig.sequence_length' => ['required', 'integer', 'min:3', 'max:5'],
            'studentIdConfig.default_fallback_identifier' => ['required', 'string', 'max:2'],
            'studentIdConfig.course_identifiers' => ['array'],
            'studentIdConfig.section_identifiers' => ['array'],
        ]);

        try {
            DB::transaction(function () {
                $userId = auth()->id();

                foreach ($this->form as $key => $value) {
                    $fullKey = "system.{$key}";

                    $type = match ($key) {
                        'maintenance_mode', 'debug_mode', 'allow_user_impersonation', 'allow_incomplete_student_dashboard', 'allow_account_deletion' => Setting::TYPE_BOOLEAN,
                        'max_upload_size', 'session_lifetime', 'test_leaderboard_limit' => Setting::TYPE_NUMBER,
                        'cache_driver' => Setting::TYPE_SELECT,
                        'maintenance_message' => Setting::TYPE_TEXT,
                        default => Setting::TYPE_STRING,
                    };

                    $setting = Setting::withTrashed()->firstWhere('key', $fullKey) ?? new Setting(['key' => $fullKey]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->group = 'system';
                    $setting->type = $type;
                    $setting->label = ucwords(str_replace('_', ' ', $key));
                    $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    $setting->status = true;
                    $setting->updated_by = $userId;
                    $setting->created_by ??= $userId;
                    $setting->save();
                }

                // Save student_id_config
                $oldStudentIdConfig = Setting::get('student_id_config', []);
                Setting::set('student_id_config', $this->studentIdConfig, $userId);
                Setting::set('test_leaderboard_limit', (int) ($this->form['test_leaderboard_limit'] ?? 10), $userId);

                Setting::flushCache();

                AuditLogService::log(
                    event: 'maintenance_mode_toggled',
                    description: $this->form['maintenance_mode'] ? 'Application Maintenance Mode ENABLED with Secret Token.' : 'Application Maintenance Mode DISABLED.',
                    newValues: [
                        'maintenance_mode' => $this->form['maintenance_mode'],
                        'debug_mode' => $this->form['debug_mode'],
                        'app_name' => $this->form['app_name'],
                    ],
                    userId: $userId
                );

                if ($oldStudentIdConfig !== $this->studentIdConfig) {
                    AuditLogService::log(
                        event: 'student_id_config_updated',
                        description: 'Updated Student ID Generation Rules and Identifier Mappings via System Settings.',
                        oldValues: is_array($oldStudentIdConfig) ? $oldStudentIdConfig : [],
                        newValues: $this->studentIdConfig,
                        userId: $userId
                    );
                }
            });

            Toast::dispatch($this, 'success', __('System settings and student ID configuration saved.'));
        } catch (\Throwable $e) {
            Log::error('Failed to save system settings: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save system settings.'));
        }
    }

    public function clearCache(): void
    {
        try {
            Artisan::call('cache:clear');
            Setting::flushCache();
            Toast::dispatch($this, 'success', __('Application cache cleared successfully.'));
        } catch (\Throwable $e) {
            Log::error('Failed to clear cache: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Cache clear failed.'));
        }
    }

    public function clearViews(): void
    {
        try {
            Artisan::call('view:clear');
            Toast::dispatch($this, 'success', __('Compiled Blade views cleared.'));
        } catch (\Throwable $e) {
            Log::error('Failed to clear views: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('View cache clear failed.'));
        }
    }

    public function linkStorage(): void
    {
        try {
            Artisan::call('storage:link');
            Toast::dispatch($this, 'success', __('Public storage symlink verified.'));
        } catch (\Throwable $e) {
            Log::error('Storage link failed: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Storage link failed.'));
        }
    }
}; ?>

<div class="space-y-6 max-w-5xl">
    <x-settings-nav active="system"/>

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight">{{ __('System & Maintenance Settings') }}</h1>
            <p class="text-xs text-muted-foreground mt-1">{{ __('Centralized application environment, maintenance mode with secret token bypass, cache drivers and quotas.') }}</p>
        </div>

        <x-ui.button wire:click="save" wire:loading.attr="disabled">
            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
            {{ __('Save Changes') }}
        </x-ui.button>
    </div>

    <form wire:submit="save" class="space-y-6">
        {{-- Section 1: Maintenance Mode with Secret Token Bypass --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-amber-500/10 text-amber-600 dark:text-amber-400">
                    <x-icon name="alert-triangle" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Application Status & Maintenance Mode') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Lock the public interface while allowing authorized stakeholders to bypass using a secret URL token.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2">
                    <x-ui.switch
                        wire:model="form.maintenance_mode"
                        :label="__('Enable Application Maintenance Mode')"
                        :description="__('Renders a 503 Maintenance page to students/public. Admin panel stays accessible.')"
                        :checked="(bool) $form['maintenance_mode']"
                    />
                </div>

                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2">
                    <x-ui.switch
                        wire:model="form.debug_mode"
                        :label="__('Enable Diagnostic Mode (Debug)')"
                        :description="__('Detailed stack traces in dev/staging environments.')"
                        :checked="(bool) $form['debug_mode']"
                    />
                </div>

                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2 sm:col-span-2">
                    <x-ui.switch
                        wire:model="form.allow_user_impersonation"
                        :label="__('Allow Admin Impersonation (Anonymous Login as User)')"
                        :description="__('Enables administrators to log directly into any user dashboard anonymously for diagnostics, student assistance, and verification.')"
                        :checked="(bool) $form['allow_user_impersonation']"
                    />
                </div>

                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2 sm:col-span-2">
                    <x-ui.switch
                        wire:model="form.allow_incomplete_student_dashboard"
                        :label="__('Allow Student Dashboard & Portal Access When Profile Incomplete')"
                        :description="__('When enabled (Yes), students can freely access all portal routes even if profile details are incomplete. When disabled (No), students with missing information across any of the 7 sections must complete their profile first. Administrators are never restricted when viewing student dashboards.')"
                        :checked="(bool) $form['allow_incomplete_student_dashboard']"
                    />
                </div>

                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2 sm:col-span-2">
                    <x-ui.switch
                        wire:model="form.allow_account_deletion"
                        :label="__('Allow User Account Self-Deletion')"
                        :description="__('When disabled (default), users and students cannot delete their accounts or associated resources from profile settings. When enabled, users are permitted to permanently delete their own account and all associated data.')"
                        :checked="(bool) $form['allow_account_deletion']"
                    />
                </div>
            </div>

            {{-- Secret Token Configuration --}}
            <div class="p-4 rounded-xl border border-border bg-secondary/20 space-y-4">
                <div class="flex items-center justify-between">
                    <div>
                        <h3 class="text-xs font-semibold text-foreground">{{ __('Secret Token Bypass Key') }}</h3>
                        <p class="text-[11px] text-muted-foreground">{{ __('Anyone opening the app with this token (?secret=TOKEN) will receive a bypass cookie and can browse normally.') }}</p>
                    </div>

                    <button
                        type="button"
                        wire:click="generateNewSecret"
                        class="text-xs text-primary hover:underline font-medium cursor-pointer flex items-center gap-1"
                    >
                        <x-icon name="refresh-cw" class="h-3 w-3"/>
                        {{ __('Generate New Token') }}
                    </button>
                </div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <x-ui.input
                        wire:model="form.maintenance_secret"
                        :label="__('Bypass Secret Token')"
                        placeholder="e.g. snt_bypass_2026"
                        class="font-mono text-xs"
                    />

                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Bypass URL Link') }}</label>
                        <div class="flex items-center gap-2 mt-1">
                            <input
                                type="text"
                                readonly
                                value="{{ url('/') }}?secret={{ $form['maintenance_secret'] }}"
                                class="h-9 flex-1 rounded-md border border-input bg-background/50 px-3 font-mono text-xs text-muted-foreground select-all"
                            />
                        </div>
                    </div>
                </div>

                <x-ui.textarea
                    wire:model="form.maintenance_message"
                    :label="__('Public Maintenance Notice Message')"
                    rows="2"
                    placeholder="We are currently upgrading server systems. Please check back shortly."
                />
            </div>
        </div>

        {{-- Section 2: Student ID Generation Configuration --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-6 shadow-xs">
            {{-- Header --}}
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 pb-3 border-b border-border">
                <div class="flex items-center gap-2.5">
                    <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <x-icon name="hash" class="h-4 w-4"/>
                    </div>
                    <div>
                        <div class="flex items-center gap-2">
                            <h2 class="text-sm font-semibold">{{ __('Student ID Generation Configuration') }}</h2>
                            <x-ui.badge color="primary" class="font-mono text-[10px]">student_id_config</x-ui.badge>
                        </div>
                        <p class="text-[11px] text-muted-foreground">{{ __('Configure lifetime unique Student ID rules: [Year(4)][Identifier(1-2)][Sequence(3-5)], max 10 digits.') }}</p>
                    </div>
                </div>

                <div class="flex items-center gap-2">
                    <x-ui.button
                        type="button"
                        variant="outline"
                        size="sm"
                        wire:click="resetStudentIdConfigToDefaults"
                        wire:confirm="{{ __('Reset student ID configuration to default settings?') }}"
                        title="{{ __('Reset to default rules') }}"
                    >
                        <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1 text-muted-foreground"/>
                        {{ __('Reset Defaults') }}
                    </x-ui.button>

                    <x-ui.button
                        type="button"
                        variant="secondary"
                        size="sm"
                        wire:click="saveStudentIdConfig"
                        wire:loading.attr="disabled"
                        title="{{ __('Save student ID settings immediately') }}"
                    >
                        <x-icon name="check" class="h-3.5 w-3.5 mr-1 text-emerald-500"/>
                        <span wire:loading.remove wire:target="saveStudentIdConfig">{{ __('Save ID Rules') }}</span>
                        <span wire:loading wire:target="saveStudentIdConfig">{{ __('Saving...') }}</span>
                    </x-ui.button>
                </div>
            </div>

            {{-- Live Formula & Interactive Simulator Panel --}}
            @php $preview = $this->previewGeneratedId; @endphp
            <div class="rounded-xl border border-primary/20 bg-primary/5 p-4 sm:p-5 space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                    <div class="flex items-center gap-2">
                        <span class="flex h-2 w-2 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span class="text-xs font-semibold text-foreground uppercase tracking-wider">{{ __('Live Format Simulator & Formula') }}</span>
                    </div>

                    <div class="flex items-center gap-2">
                        @if ($preview['is_valid'])
                            <x-ui.badge color="success" class="font-mono text-[11px]">
                                <x-icon name="check" class="h-3 w-3 mr-0.5"/>
                                {{ $preview['length'] }} {{ __('digits (Valid ≤ 10)') }}
                            </x-ui.badge>
                        @else
                            <x-ui.badge color="danger" class="font-mono text-[11px]">
                                <x-icon name="alert-triangle" class="h-3 w-3 mr-0.5"/>
                                {{ $preview['length'] }} {{ __('digits (Exceeds max 10!)') }}
                            </x-ui.badge>
                        @endif
                        <span class="text-[11px] text-muted-foreground hidden sm:inline">| {{ __('Max capacity/year:') }} <strong class="text-foreground">{{ $preview['max_capacity'] }}</strong></span>
                    </div>
                </div>

                {{-- Visual Formula Breakdown --}}
                <div class="flex flex-wrap items-center gap-2 p-3 rounded-lg bg-background/80 border border-border">
                    <div class="flex flex-col items-center px-3 py-1.5 rounded bg-secondary/50 border border-border">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Year (4 Digits)') }}</span>
                        <span class="text-sm font-mono font-bold text-foreground">{{ $preview['year'] }}</span>
                    </div>
                    <span class="text-muted-foreground font-bold">+</span>
                    <div class="flex flex-col items-center px-3 py-1.5 rounded bg-secondary/50 border border-border">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Identifier (1-2 Digits)') }}</span>
                        <span class="text-sm font-mono font-bold text-primary">{{ $preview['identifier'] }}</span>
                    </div>
                    <span class="text-muted-foreground font-bold">+</span>
                    <div class="flex flex-col items-center px-3 py-1.5 rounded bg-secondary/50 border border-border">
                        <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Sequence (:len Digits)', ['len' => $preview['seq_length']]) }}</span>
                        <span class="text-sm font-mono font-bold text-foreground">{{ str_pad('1', $preview['seq_length'], '0', STR_PAD_LEFT) }}</span>
                    </div>
                    <span class="text-muted-foreground font-bold">=</span>
                    <div class="flex flex-col px-3 py-1.5 rounded bg-primary/10 border border-primary/30">
                        <span class="text-[10px] uppercase font-semibold text-primary">{{ __('Generated Student ID') }}</span>
                        <span class="text-base font-mono font-extrabold text-primary tracking-wider">{{ $preview['sample_id'] }}</span>
                    </div>
                    <div class="ml-auto text-right text-[11px] text-muted-foreground hidden md:block">
                        <div>{{ __('Next Student:') }} <span class="font-mono text-foreground font-semibold">{{ $preview['sample_id_secondary'] }}</span></div>
                    </div>
                </div>

                {{-- Interactive Test Controls --}}
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 pt-1">
                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Test Year') }}</label>
                        <input
                            type="text"
                            wire:model.live.debounce.250ms="previewYear"
                            maxlength="4"
                            class="mt-1 h-8 w-full rounded-md border border-input bg-background px-3 font-mono text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                            placeholder="{{ date('Y') }}"
                        />
                    </div>

                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Test Course') }}</label>
                        <select
                            wire:model.live="previewSelectedCourse"
                            class="mt-1 h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            <option value="">-- {{ __('No Course (Fallback)') }} --</option>
                            @foreach ($studentIdConfig['course_identifiers'] as $courseCode => $cid)
                                <option value="{{ $courseCode }}">{{ $courseCode }} (ID: {{ $cid }})</option>
                            @endforeach
                            @foreach ($this->availableCourses as $course)
                                @if (!isset($studentIdConfig['course_identifiers'][$course->code]))
                                    <option value="{{ $course->code }}">{{ $course->code }} - {{ $course->name }} (DB unmapped)</option>
                                @endif
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Test Section (Overrides Course)') }}</label>
                        <select
                            wire:model.live="previewSelectedSection"
                            class="mt-1 h-8 w-full rounded-md border border-input bg-background px-2.5 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                        >
                            <option value="">-- {{ __('No Section (Use Course)') }} --</option>
                            @foreach ($studentIdConfig['section_identifiers'] as $sectionName => $sid)
                                <option value="{{ $sectionName }}">{{ $sectionName }} (ID: {{ $sid }})</option>
                            @endforeach
                            @foreach ($this->availableSections as $sec)
                                @if (!isset($studentIdConfig['section_identifiers'][$sec->name]))
                                    <option value="{{ $sec->name }}">{{ $sec->name }} (DB unmapped)</option>
                                @endif
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            {{-- Core Rules: Sequence Length & Default Fallback Identifier --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Sequence Padding Length') }} <span class="text-destructive">*</span>
                    </label>
                    <select
                        wire:model.live="studentIdConfig.sequence_length"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                    >
                        <option value="3">{{ __('3 Digits (001 - 999 max per course/year)') }}</option>
                        <option value="4">{{ __('4 Digits (0001 - 9,999 max per course/year) — Recommended') }}</option>
                        <option value="5">{{ __('5 Digits (00001 - 99,999 max per course/year)') }}</option>
                    </select>
                    <p class="text-[11px] text-muted-foreground">
                        {{ __('Defines how many digits are padded with leading zeros for student sequences within a year and identifier.') }}
                    </p>
                </div>

                <div class="p-4 rounded-lg border border-border bg-secondary/10 space-y-2">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Default Fallback Identifier') }} <span class="text-destructive">*</span>
                    </label>
                    <input
                        type="text"
                        wire:model.live="studentIdConfig.default_fallback_identifier"
                        maxlength="2"
                        placeholder="0"
                        class="h-9 w-full rounded-md border border-input bg-background px-3 font-mono text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                    />
                    <p class="text-[11px] text-muted-foreground">
                        {{ __('Fallback digit used when no course or section matching rule applies (e.g. 0). Up to 2 alphanumeric characters.') }}
                    </p>
                </div>
            </div>

            {{-- 2-Column Mappings: Course Identifiers & Section Identifiers --}}
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-5 pt-1">
                {{-- Course Identifiers Table --}}
                <div class="rounded-xl border border-border bg-card p-4 space-y-3">
                    <div class="flex items-center justify-between pb-2 border-b border-border">
                        <div class="flex items-center gap-2">
                            <h3 class="text-xs font-semibold text-foreground uppercase tracking-wider">{{ __('Course Identifiers') }}</h3>
                            <x-ui.badge color="secondary" class="font-mono text-[10px]">{{ count($studentIdConfig['course_identifiers'] ?? []) }}</x-ui.badge>
                        </div>
                        <x-ui.button
                            type="button"
                            variant="ghost"
                            size="xs"
                            wire:click="syncCoursesFromDb"
                            title="{{ __('Populate with unmapped courses from database') }}"
                        >
                            <x-icon name="refresh-cw" class="h-3 w-3 mr-1 text-primary"/>
                            {{ __('Sync from DB') }}
                        </x-ui.button>
                    </div>

                    <p class="text-[11px] text-muted-foreground">
                        {{ __('Maps course codes to an identifier digit (e.g., CC → 1, PCC → 3, WBCS → 2).') }}
                    </p>

                    <div class="max-h-60 overflow-y-auto space-y-1.5 pr-1 divide-y divide-border/40">
                        @forelse ($studentIdConfig['course_identifiers'] ?? [] as $code => $idVal)
                            <div class="flex items-center justify-between gap-2 pt-1.5 first:pt-0">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="font-mono font-semibold text-xs text-foreground px-2 py-0.5 rounded bg-secondary/60 shrink-0">
                                        {{ $code }}
                                    </span>
                                    <x-icon name="arrow-right" class="h-3 w-3 text-muted-foreground shrink-0"/>
                                </div>

                                <div class="flex items-center gap-1.5">
                                    <input
                                        type="text"
                                        wire:model.live="studentIdConfig.course_identifiers.{{ $code }}"
                                        maxlength="2"
                                        class="h-7 w-14 rounded border border-input bg-background px-2 text-center font-mono text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                                    />
                                    <button
                                        type="button"
                                        wire:click="removeCourseIdentifier('{{ $code }}')"
                                        class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors"
                                        title="{{ __('Remove mapping') }}"
                                    >
                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                    </button>
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-muted-foreground py-3 text-center italic">{{ __('No course identifiers configured.') }}</p>
                        @endforelse
                    </div>

                    {{-- Add New Course Mapping --}}
                    <div class="pt-2 border-t border-border flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="newCourseCode"
                            placeholder="{{ __('Code (e.g. UPSC)') }}"
                            class="h-8 flex-1 rounded-md border border-input bg-background px-2.5 font-mono text-xs uppercase shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                        />
                        <input
                            type="text"
                            wire:model="newCourseIdentifier"
                            maxlength="2"
                            placeholder="{{ __('ID (e.g. 5)') }}"
                            class="h-8 w-20 rounded-md border border-input bg-background px-2 text-center font-mono text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                        />
                        <x-ui.button
                            type="button"
                            variant="secondary"
                            size="sm"
                            wire:click="addCourseIdentifier"
                        >
                            <x-icon name="plus" class="h-3.5 w-3.5 mr-1"/>
                            {{ __('Add') }}
                        </x-ui.button>
                    </div>
                </div>

                {{-- Section Identifiers Table --}}
                <div class="rounded-xl border border-border bg-card p-4 space-y-3">
                    <div class="flex items-center justify-between pb-2 border-b border-border">
                        <div class="flex items-center gap-2">
                            <h3 class="text-xs font-semibold text-foreground uppercase tracking-wider">{{ __('Section Identifiers') }}</h3>
                            <x-ui.badge color="secondary" class="font-mono text-[10px]">{{ count($studentIdConfig['section_identifiers'] ?? []) }}</x-ui.badge>
                        </div>
                        <x-ui.button
                            type="button"
                            variant="ghost"
                            size="xs"
                            wire:click="syncSectionsFromDb"
                            title="{{ __('Populate with unmapped sections from database') }}"
                        >
                            <x-icon name="refresh-cw" class="h-3 w-3 mr-1 text-primary"/>
                            {{ __('Sync from DB') }}
                        </x-ui.button>
                    </div>

                    <p class="text-[11px] text-muted-foreground">
                        {{ __('Maps section names/codes to an identifier digit (e.g., Section A → 1, Batch B → 2).') }}
                    </p>

                    <div class="max-h-60 overflow-y-auto space-y-1.5 pr-1 divide-y divide-border/40">
                        @forelse ($studentIdConfig['section_identifiers'] ?? [] as $sName => $sVal)
                            <div class="flex items-center justify-between gap-2 pt-1.5 first:pt-0">
                                <div class="flex items-center gap-2 min-w-0">
                                    <span class="font-medium text-xs text-foreground px-2 py-0.5 rounded bg-secondary/60 truncate" title="{{ $sName }}">
                                        {{ $sName }}
                                    </span>
                                    <x-icon name="arrow-right" class="h-3 w-3 text-muted-foreground shrink-0"/>
                                </div>

                                <div class="flex items-center gap-1.5">
                                    <input
                                        type="text"
                                        wire:model.live="studentIdConfig.section_identifiers.{{ $sName }}"
                                        maxlength="2"
                                        class="h-7 w-14 rounded border border-input bg-background px-2 text-center font-mono text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                                    />
                                    <button
                                        type="button"
                                        wire:click="removeSectionIdentifier('{{ $sName }}')"
                                        class="h-7 w-7 rounded flex items-center justify-center text-muted-foreground hover:text-destructive hover:bg-destructive/10 transition-colors"
                                        title="{{ __('Remove mapping') }}"
                                    >
                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                    </button>
                                </div>
                            </div>
                        @empty
                            <p class="text-xs text-muted-foreground py-3 text-center italic">{{ __('No section identifiers configured.') }}</p>
                        @endforelse
                    </div>

                    {{-- Add New Section Mapping --}}
                    <div class="pt-2 border-t border-border flex items-center gap-2">
                        <input
                            type="text"
                            wire:model="newSectionName"
                            placeholder="{{ __('Section (e.g. Section C)') }}"
                            class="h-8 flex-1 rounded-md border border-input bg-background px-2.5 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                        />
                        <input
                            type="text"
                            wire:model="newSectionIdentifier"
                            maxlength="2"
                            placeholder="{{ __('ID (e.g. 3)') }}"
                            class="h-8 w-20 rounded-md border border-input bg-background px-2 text-center font-mono text-xs shadow-xs focus-visible:border-ring focus-visible:ring-1 focus-visible:ring-ring"
                        />
                        <x-ui.button
                            type="button"
                            variant="secondary"
                            size="sm"
                            wire:click="addSectionIdentifier"
                        >
                            <x-icon name="plus" class="h-3.5 w-3.5 mr-1"/>
                            {{ __('Add') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Section 3: Application & Developer Information --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="cpu" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Build & Developer Credits') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Internal application release metadata and contact references.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <x-ui.input
                    wire:model="form.app_name"
                    :label="__('Application Name') . ' *'"
                    placeholder="SNT CSSC MIS"
                    required
                />

                <x-ui.input
                    wire:model="form.app_version"
                    :label="__('Release Version') . ' *'"
                    placeholder="1.0.0"
                    required
                />

                <x-ui.input
                    wire:model="form.developed_by"
                    :label="__('Developed By')"
                    placeholder="SNT CSSC IT Team"
                />

                <x-ui.input
                    wire:model="form.developer_contact"
                    :label="__('Support / Developer Email')"
                    placeholder="dev@sntcssc.in"
                    icon="mail"
                />

                <x-ui.input
                    wire:model="form.developer_github"
                    :label="__('GitHub Repository')"
                    placeholder="https://github.com/sntcssc"
                    icon="code"
                />

                <x-ui.input
                    wire:model="form.developer_website"
                    :label="__('Documentation / Website')"
                    placeholder="https://sntcssc.in"
                    icon="globe"
                />
            </div>
        </div>

        {{-- Section 4: Quotas & Storage Limits --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="hard-drive" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Storage Limits & Sessions') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Upload size limits and session timeouts.') }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4">
                <x-ui.input
                    wire:model="form.max_upload_size"
                    :label="__('Max Upload Limit (MB)') . ' *'"
                    type="number"
                    placeholder="10"
                    hint="{{ __('Max size per document / receipt.') }}"
                />

                <x-ui.input
                    wire:model="form.session_lifetime"
                    :label="__('Session Inactivity Timeout (Min)') . ' *'"
                    type="number"
                    placeholder="120"
                />

                <x-ui.select
                    wire:model="form.cache_driver"
                    :label="__('Primary Cache Driver') . ' *'"
                    :options="[
                        'file' => 'File Cache (Local Disk)',
                        'redis' => 'Redis (In-Memory)',
                        'database' => 'Database Table',
                        'array' => 'Array (Testing)',
                    ]"
                />

                <x-ui.select
                    wire:model="form.test_leaderboard_limit"
                    :label="__('Leaderboard Top Rankers Limit') . ' *'"
                    hint="{{ __('Default number of rankers displayed in Leaderboard.') }}"
                    :options="[
                        '3' => 'Top 3 (Podium)',
                        '5' => 'Top 5',
                        '10' => 'Top 10',
                        '15' => 'Top 15',
                        '20' => 'Top 20',
                        '25' => 'Top 25',
                        '50' => 'Top 50',
                        '100' => 'Top 100',
                    ]"
                />
            </div>
        </div>

        {{-- Section 5: System Utilities & Maintenance Tools --}}
        <div class="rounded-xl border border-border bg-card p-5 sm:p-6 space-y-4 shadow-xs">
            <div class="flex items-center gap-2.5 pb-3 border-b border-border">
                <div class="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                    <x-icon name="rotate-ccw" class="h-4 w-4"/>
                </div>
                <div>
                    <h2 class="text-sm font-semibold">{{ __('Maintenance Utilities') }}</h2>
                    <p class="text-[11px] text-muted-foreground">{{ __('Clear application cache and refresh compiled assets.') }}</p>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <x-ui.button
                    type="button"
                    variant="outline"
                    wire:click="clearCache"
                    wire:loading.attr="disabled"
                    wire:target="clearCache"
                    class="h-9 gap-1.5"
                >
                    <x-icon name="refresh-cw" class="h-4 w-4" wire:loading.remove wire:target="clearCache"/>
                    <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" wire:loading wire:target="clearCache"/>
                    {{ __('Clear App Cache') }}
                </x-ui.button>

                <x-ui.button
                    type="button"
                    variant="outline"
                    wire:click="clearViews"
                    wire:loading.attr="disabled"
                    wire:target="clearViews"
                    class="h-9 gap-1.5"
                >
                    <x-icon name="layers" class="h-4 w-4" wire:loading.remove wire:target="clearViews"/>
                    <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" wire:loading wire:target="clearViews"/>
                    {{ __('Clear Compiled Views') }}
                </x-ui.button>

                <x-ui.button
                    type="button"
                    variant="outline"
                    wire:click="linkStorage"
                    wire:loading.attr="disabled"
                    wire:target="linkStorage"
                    class="h-9 gap-1.5"
                >
                    <x-icon name="hard-drive" class="h-4 w-4" wire:loading.remove wire:target="linkStorage"/>
                    <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" wire:loading wire:target="linkStorage"/>
                    {{ __('Verify Storage Symlink') }}
                </x-ui.button>
            </div>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2">
            <x-ui.button type="submit" wire:loading.attr="disabled">
                <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                {{ __('Save Changes') }}
            </x-ui.button>
        </div>
    </form>
</div>

