<?php

use App\Models\Setting;
use App\Services\FileUploadService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Hostel Configuration & Policy Settings')] class extends Component {
    use WithFileUploads;

    public array $form = [
        'allow_resident_extension_requests' => true,
        'soft_copy_required' => false,
        'default_duration_months' => 6,
        'expiry_notice_days' => 15,
        'form_download_mode' => 'generated', // 'generated' or 'uploaded'
        'form_custom_pdf_path' => '',
        'rules_notice' => '',
    ];

    public $customFormFile = null;

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'hostel')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "hostel.{$key}";
            if (isset($settings[$fullKey])) {
                if (is_bool($default)) {
                    $this->form[$key] = (bool) $settings[$fullKey]->typed();
                } elseif (is_int($default)) {
                    $this->form[$key] = (int) $settings[$fullKey]->typed();
                } else {
                    $this->form[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
                }
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.allow_resident_extension_requests' => ['boolean'],
            'form.soft_copy_required' => ['boolean'],
            'form.default_duration_months' => ['required', 'integer', 'min:1', 'max:60'],
            'form.expiry_notice_days' => ['required', 'integer', 'min:1', 'max:90'],
            'form.form_download_mode' => ['required', 'in:generated,uploaded'],
            'form.rules_notice' => ['nullable', 'string', 'max:2000'],
            'customFormFile' => ['nullable', 'file', 'mimes:pdf', 'max:10240'],
        ]);

        try {
            DB::transaction(function () {
                if ($this->customFormFile) {
                    $stored = FileUploadService::store(
                        $this->customFormFile,
                        'hostel/forms',
                        'hostel_custom_template',
                        'public'
                    );
                    $this->form['form_custom_pdf_path'] = $stored;
                }

                $definitions = [
                    'allow_resident_extension_requests' => [
                        'type' => Setting::TYPE_BOOLEAN,
                        'label' => 'Allow Residents to Submit Accommodation Extension Requests',
                    ],
                    'soft_copy_required' => [
                        'type' => Setting::TYPE_BOOLEAN,
                        'label' => 'Require Soft-Copy Hostel Admission Form Upload (Mandatory)',
                    ],
                    'default_duration_months' => [
                        'type' => Setting::TYPE_NUMBER,
                        'label' => 'Default Room Accommodation Timeline Duration (Months)',
                    ],
                    'expiry_notice_days' => [
                        'type' => Setting::TYPE_NUMBER,
                        'label' => 'Hostel Accommodation Expiration Alert Window (Days)',
                    ],
                    'form_download_mode' => [
                        'type' => Setting::TYPE_STRING,
                        'label' => 'Hostel Admission Form Download Mode (Personalized Generated vs Admin Uploaded)',
                    ],
                    'form_custom_pdf_path' => [
                        'type' => Setting::TYPE_STRING,
                        'label' => 'Admin Uploaded Hostel Admission Form PDF Path',
                    ],
                    'rules_notice' => [
                        'type' => Setting::TYPE_TEXT,
                        'label' => 'Hostel Resident Guidelines and Terms Notice',
                    ],
                ];

                foreach ($this->form as $key => $value) {
                    $fullKey = "hostel.{$key}";
                    $meta = $definitions[$key] ?? ['type' => Setting::TYPE_STRING, 'label' => $key];

                    $setting = Setting::firstOrNew(['key' => $fullKey]);
                    $setting->group = 'hostel';
                    $setting->type = $meta['type'];
                    $setting->label = $meta['label'];
                    $setting->status = true;
                    $setting->value = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
                    $setting->updated_by = auth()->id();
                    $setting->save();
                }
            });

            $this->customFormFile = null;
            Setting::flushCache();
            Toast::dispatch($this, 'success', __('Hostel settings, form policies, and operational controls saved successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }
};
?>

<div class="space-y-6">
    <x-settings-nav active="hostel" />

    <!-- Header Section -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-border">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground">
                {{ __('Hostel Policy & Operational Settings') }}
            </h1>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ __('Configure hostel admission form downloads (personalized auto-generated vs custom admin PDF), resident extension privileges, timelines, and guidelines.') }}
            </p>
        </div>

        <div>
            <x-ui.button
                type="button"
                variant="default"
                size="sm"
                wire:click="save"
                class="bg-primary text-primary-foreground cursor-pointer"
            >
                <x-icon name="check" class="w-4 h-4 mr-1.5" />
                {{ __('Save Settings') }}
            </x-ui.button>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <!-- 1. Hostel Admission Form Download Configuration -->
        <div class="p-6 rounded-2xl border border-primary/30 bg-card shadow-xs space-y-5">
            <div class="flex items-center gap-2 pb-3 border-b border-border">
                <x-icon name="file-down" class="w-5 h-5 text-primary" />
                <div>
                    <h3 class="font-bold text-base text-foreground">{{ __('Hostel Admission Form Download Configuration') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Choose whether residents download a dynamically generated personalized form prefilled with their data, or an official custom PDF uploaded by administration.') }}
                    </p>
                </div>
            </div>

            <div class="space-y-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <!-- Option A: Generated PDF -->
                    <label class="relative flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors {{ $form['form_download_mode'] === 'generated' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-background hover:bg-muted/30' }}">
                        <input
                            type="radio"
                            wire:model.live="form.form_download_mode"
                            value="generated"
                            class="mt-1 text-primary focus:ring-primary"
                        />
                        <div>
                            <span class="block text-sm font-bold text-foreground">
                                {{ __('Auto-Generated Personalized PDF Form (Recommended)') }}
                            </span>
                            <span class="block text-xs text-muted-foreground mt-1">
                                {{ __('System automatically generates the official institutional PDF admission form dynamically prefilled with the student\'s photograph, Student ID, Enrollment ID, Course, Batch, Section, Room & Bed allotment, warden and manager seals, and undertakings.') }}
                            </span>
                        </div>
                    </label>

                    <!-- Option B: Custom Uploaded PDF -->
                    <label class="relative flex items-start gap-3 p-4 rounded-xl border cursor-pointer transition-colors {{ $form['form_download_mode'] === 'uploaded' ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-background hover:bg-muted/30' }}">
                        <input
                            type="radio"
                            wire:model.live="form.form_download_mode"
                            value="uploaded"
                            class="mt-1 text-primary focus:ring-primary"
                        />
                        <div>
                            <span class="block text-sm font-bold text-foreground">
                                {{ __('Admin-Uploaded Custom Institutional Blank Form (PDF)') }}
                            </span>
                            <span class="block text-xs text-muted-foreground mt-1">
                                {{ __('Students and residents will download a custom static blank PDF document uploaded by the administration for printing and manual ink signing.') }}
                            </span>
                        </div>
                    </label>
                </div>

                <!-- Custom PDF Upload Field -->
                @if ($form['form_download_mode'] === 'uploaded')
                    <div class="p-4 rounded-xl border border-amber-500/20 bg-amber-500/5 space-y-3">
                        <span class="text-xs font-bold text-foreground uppercase tracking-wider block">
                            {{ __('Upload Official Blank Hostel Admission Form (PDF)') }}
                        </span>

                        <div class="flex flex-col sm:flex-row sm:items-center gap-3">
                            <input
                                type="file"
                                wire:model="customFormFile"
                                accept=".pdf"
                                class="text-xs text-muted-foreground file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                            />

                            @if (! empty($form['form_custom_pdf_path']) && Storage::disk('public')->exists($form['form_custom_pdf_path']))
                                <a
                                    href="{{ Storage::disk('public')->url($form['form_custom_pdf_path']) }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-semibold bg-background border border-border text-foreground hover:bg-secondary transition-colors"
                                >
                                    <x-icon name="eye" class="w-3.5 h-3.5 text-primary" />
                                    {{ __('Preview Current Uploaded Template') }}
                                </a>
                            @endif
                        </div>
                        @error('customFormFile') <span class="text-xs text-rose-500 block">{{ $message }}</span> @enderror
                    </div>
                @endif

                <!-- Test Form Download Button for Admin -->
                <div class="flex items-center justify-between p-3 rounded-xl bg-secondary/30 border border-border text-xs">
                    <span class="text-muted-foreground">
                        {{ __('Active Mode:') }} <strong class="text-foreground uppercase">{{ $form['form_download_mode'] }}</strong>
                    </span>
                    <a
                        href="{{ route('hostel.form.download') }}"
                        target="_blank"
                        class="inline-flex items-center gap-1.5 px-3 py-1 text-xs font-semibold text-primary hover:underline"
                    >
                        <x-icon name="download" class="w-3.5 h-3.5" />
                        {{ __('Test Download Form as Administrator') }}
                    </a>
                </div>
            </div>
        </div>

        <!-- 2. Resident Portal Privileges & Extension Controls -->
        <div class="p-6 rounded-2xl border border-border bg-card shadow-xs space-y-5">
            <div class="flex items-center gap-2 pb-3 border-b border-border">
                <x-icon name="calendar-plus" class="w-5 h-5 text-primary" />
                <h3 class="font-bold text-base text-foreground">{{ __('Resident Extension Requests Feature Toggle') }}</h3>
            </div>

            <div class="space-y-4">
                <div class="flex items-start justify-between gap-4 p-4 rounded-xl border border-border bg-background">
                    <div class="space-y-1">
                        <label for="allow_extensions" class="text-sm font-bold text-foreground cursor-pointer">
                            {{ __('Enable "Request Extension" Button in Student & Staff Resident Dashboard') }}
                        </label>
                        <p class="text-xs text-muted-foreground">
                            {{ __('When enabled, accommodated residents can request stay timeline extensions with a reason. If disabled by admin, the button is hidden and extension requests are closed.') }}
                        </p>
                    </div>

                    <div class="pt-0.5">
                        <input
                            type="checkbox"
                            id="allow_extensions"
                            wire:model="form.allow_resident_extension_requests"
                            class="w-5 h-5 rounded border-border text-primary focus:ring-primary cursor-pointer"
                        />
                    </div>
                </div>

                <div class="flex items-start justify-between gap-4 p-4 rounded-xl border border-border bg-background">
                    <div class="space-y-1">
                        <label for="soft_copy_req" class="text-sm font-bold text-foreground cursor-pointer">
                            {{ __('Enforce Mandatory Soft-Copy Hostel Form Upload') }}
                        </label>
                        <p class="text-xs text-muted-foreground">
                            {{ __('When enabled, residents must upload a signed scanned copy of their physical hostel admission form. If disabled, upload remains optional.') }}
                        </p>
                    </div>

                    <div class="pt-0.5">
                        <input
                            type="checkbox"
                            id="soft_copy_req"
                            wire:model="form.soft_copy_required"
                            class="w-5 h-5 rounded border-border text-primary focus:ring-primary cursor-pointer"
                        />
                    </div>
                </div>
            </div>
        </div>

        <!-- 3. Timelines & Alert Thresholds -->
        <div class="p-6 rounded-2xl border border-border bg-card shadow-xs space-y-5">
            <div class="flex items-center gap-2 pb-3 border-b border-border">
                <x-icon name="clock" class="w-5 h-5 text-primary" />
                <h3 class="font-bold text-base text-foreground">{{ __('Accommodation Timelines & Expiration Alert Thresholds') }}</h3>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">
                        {{ __('Default Accommodation Period (Months) *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.default_duration_months"
                        min="1"
                        max="60"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    />
                    <p class="mt-1 text-[11px] text-muted-foreground">
                        {{ __('Pre-filled duration for new student & staff allotments (e.g. 6 months or 12 months).') }}
                    </p>
                    @error('form.default_duration_months') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground uppercase mb-1">
                        {{ __('Stay Expiration Alert Notice Window (Days) *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.expiry_notice_days"
                        min="1"
                        max="90"
                        class="w-full h-10 px-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                    />
                    <p class="mt-1 text-[11px] text-muted-foreground">
                        {{ __('Accommodations concluding within this number of days will be flagged in the Expiring Soon table (default: 15 days).') }}
                    </p>
                    @error('form.expiry_notice_days') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>
        </div>

        <!-- 4. Hostel Guidelines & Resident Terms -->
        <div class="p-6 rounded-2xl border border-border bg-card shadow-xs space-y-5">
            <div class="flex items-center gap-2 pb-3 border-b border-border">
                <x-icon name="file-text" class="w-5 h-5 text-primary" />
                <h3 class="font-bold text-base text-foreground">{{ __('Hostel Resident Guidelines and Terms Notice') }}</h3>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground uppercase mb-1">
                    {{ __('Resident Code of Conduct & Guidelines') }}
                </label>
                <textarea
                    wire:model="form.rules_notice"
                    rows="4"
                    placeholder="{{ __('Enter institution hostel rules, curfew timings, visitor policies, and contact information for the warden and caretakers...') }}"
                    class="w-full p-3 text-xs rounded-lg border border-border bg-background focus:ring-2 focus:ring-primary focus:outline-none"
                ></textarea>
                <p class="mt-1 text-[11px] text-muted-foreground">
                    {{ __('This notice will be displayed to students and staff inside their logged-in hostel dashboard.') }}
                </p>
                @error('form.rules_notice') <span class="text-xs text-rose-500 mt-1 block">{{ $message }}</span> @enderror
            </div>
        </div>

        <!-- Save Button Footer -->
        <div class="flex justify-end pt-4 border-t border-border">
            <x-ui.button
                type="submit"
                variant="default"
                size="sm"
                class="bg-primary text-primary-foreground cursor-pointer px-6"
            >
                <x-icon name="check" class="w-4 h-4 mr-1.5" />
                {{ __('Save All Policy Changes') }}
            </x-ui.button>
        </div>
    </form>
</div>
