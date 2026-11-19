<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Services\StudentIdCardService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Student ID Card Settings')] class extends Component {
    public array $form = [
        'enable_student_download' => true,
        'card_title' => 'STUDENT IDENTITY CARD',
        'institution_name' => 'Satyendranath Tagore Civil Services Study Centre',
        'institution_sub' => 'Government of West Bengal',
        'validity_period' => 'Academic Session 2026-2027',
        'authorized_signatory_title' => 'Course Director / Authorized Signatory',
        'show_blood_group' => true,
        'show_emergency_contact' => true,
        'show_barcode' => true,
        'show_qr_code' => true,
        'show_optional_subject' => true,
        'terms_and_conditions' => '',
        'theme_color' => '#1e3a8a',
    ];

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'student_id_card')->get()->keyBy('key');

        $defaults = StudentIdCardService::getSettings();
        $this->form = array_merge($this->form, $defaults);

        foreach ($this->form as $key => $default) {
            $fullKey = "student_id_card.{$key}";
            if (isset($settings[$fullKey])) {
                if (is_bool($default)) {
                    $this->form[$key] = (bool) $settings[$fullKey]->typed();
                } else {
                    $this->form[$key] = (string) ($settings[$fullKey]->rawValue() ?? $default);
                }
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.enable_student_download' => ['boolean'],
            'form.card_title' => ['required', 'string', 'max:100'],
            'form.institution_name' => ['required', 'string', 'max:200'],
            'form.institution_sub' => ['nullable', 'string', 'max:200'],
            'form.validity_period' => ['required', 'string', 'max:100'],
            'form.authorized_signatory_title' => ['required', 'string', 'max:150'],
            'form.show_blood_group' => ['boolean'],
            'form.show_emergency_contact' => ['boolean'],
            'form.show_barcode' => ['boolean'],
            'form.show_qr_code' => ['boolean'],
            'form.show_optional_subject' => ['boolean'],
            'form.terms_and_conditions' => ['required', 'string', 'max:2500'],
            'form.theme_color' => ['nullable', 'string', 'max:30'],
        ]);

        try {
            DB::beginTransaction();

            foreach ($this->form as $key => $value) {
                Setting::set("student_id_card.{$key}", $value);
            }

            AuditLogService::log(
                event: 'student_id_card_settings_updated',
                description: 'Updated Student Identity Card configuration and student download availability rules.',
                newValues: $this->form
            );

            DB::commit();

            Toast::dispatch($this, 'success', __('Student ID Card settings saved successfully.'));
        } catch (\Throwable $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2.5">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400">
                    <x-icon name="id-card" class="w-6 h-6" />
                </span>
                {{ __('Student ID Card Configuration & Policy') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Control student portal ID card download availability, card layout elements, validity session, and terms.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a
                href="{{ route('admin.student-id-cards.index') }}"
                class="inline-flex items-center gap-1.5 px-3 py-2 text-xs font-semibold rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-700 dark:text-zinc-300 hover:bg-zinc-50 transition"
            >
                <x-icon name="users" class="w-4 h-4" />
                {{ __('Student Cards Registry') }}
            </a>
            <x-ui.button wire:click="save" variant="primary" class="gap-1.5 text-xs font-semibold">
                <x-icon name="check" class="w-4 h-4" />
                {{ __('Save Settings') }}
            </x-ui.button>
        </div>
    </div>

    <!-- Main Settings Form Grid -->
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
        <!-- Configuration Controls (Left 2 cols) -->
        <div class="lg:col-span-2 space-y-6">
            <!-- Student Portal Download Availability Card -->
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                            <x-icon name="arrow-down-tray" class="w-4 h-4 text-blue-600" />
                            {{ __('Student Portal Download Access') }}
                        </h3>
                        <p class="text-xs text-zinc-500 mt-0.5">
                            {{ __('Turn on or off the PDF ID card download button for logged-in students in their student dashboard and portal.') }}
                        </p>
                    </div>
                </div>

                <div class="flex items-center justify-between p-4 rounded-xl {{ $form['enable_student_download'] ? 'bg-emerald-50/60 dark:bg-emerald-950/20 border border-emerald-200 dark:border-emerald-800/50' : 'bg-rose-50/60 dark:bg-rose-950/20 border border-rose-200 dark:border-rose-800/50' }}">
                    <div class="space-y-0.5">
                        <span class="text-xs font-bold {{ $form['enable_student_download'] ? 'text-emerald-900 dark:text-emerald-200' : 'text-rose-900 dark:text-rose-200' }}">
                            {{ $form['enable_student_download'] ? __('Download Option Enabled for Students') : __('Download Option Disabled (Restricted)') }}
                        </span>
                        <p class="text-[11px] {{ $form['enable_student_download'] ? 'text-emerald-700 dark:text-emerald-400' : 'text-rose-700 dark:text-rose-400' }}">
                            {{ $form['enable_student_download'] ? __('Enrolled students can download their official printable PDF ID card anytime.') : __('Students will only see their digital view in portal; PDF download is locked.') }}
                        </p>
                    </div>

                    <label class="relative inline-flex items-center cursor-pointer">
                        <input
                            type="checkbox"
                            wire:model.live="form.enable_student_download"
                            class="sr-only peer"
                        />
                        <div class="w-11 h-6 bg-zinc-300 peer-focus:outline-hidden rounded-full peer dark:bg-zinc-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-zinc-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-blue-600"></div>
                    </label>
                </div>
            </div>

            <!-- Card Layout & Institutional Information -->
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-4">
                <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <x-icon name="building-library" class="w-4 h-4 text-indigo-600" />
                    {{ __('Header & Institutional Information') }}
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Institution Name') }}</label>
                        <x-ui.input wire:model="form.institution_name" placeholder="e.g. Satyendranath Tagore Civil Services Study Centre" />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Sub-Header / Department') }}</label>
                        <x-ui.input wire:model="form.institution_sub" placeholder="e.g. Government of West Bengal" />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Card Badge Title') }}</label>
                        <x-ui.input wire:model="form.card_title" placeholder="e.g. STUDENT IDENTITY CARD" />
                    </div>

                    <div class="space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Validity Academic Session') }}</label>
                        <x-ui.input wire:model="form.validity_period" placeholder="e.g. Academic Session 2026-2027" />
                    </div>

                    <div class="sm:col-span-2 space-y-1">
                        <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Authorized Signatory Title') }}</label>
                        <x-ui.input wire:model="form.authorized_signatory_title" placeholder="e.g. Course Director / Authorized Signatory" />
                    </div>
                </div>
            </div>

            <!-- Field Display Visibility Toggles -->
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-4">
                <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <x-icon name="adjustments-horizontal" class="w-4 h-4 text-purple-600" />
                    {{ __('Card Elements & Scannable Media') }}
                </h3>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                    <label class="flex items-center gap-2.5 p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40 cursor-pointer">
                        <input type="checkbox" wire:model.live="form.show_optional_subject" class="w-4 h-4 text-blue-600 rounded border-zinc-300 focus:ring-blue-500" />
                        <div>
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200 block">{{ __('Show Optional Subject') }}</span>
                            <span class="text-[11px] text-zinc-500">{{ __('Prints the student’s UPSC optional subject on the front card face.') }}</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-2.5 p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40 cursor-pointer">
                        <input type="checkbox" wire:model.live="form.show_blood_group" class="w-4 h-4 text-blue-600 rounded border-zinc-300 focus:ring-blue-500" />
                        <div>
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200 block">{{ __('Show Blood Group') }}</span>
                            <span class="text-[11px] text-zinc-500">{{ __('Includes student medical blood group.') }}</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-2.5 p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40 cursor-pointer">
                        <input type="checkbox" wire:model.live="form.show_barcode" class="w-4 h-4 text-blue-600 rounded border-zinc-300 focus:ring-blue-500" />
                        <div>
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200 block">{{ __('Generate Code 128 Barcode') }}</span>
                            <span class="text-[11px] text-zinc-500">{{ __('Renders scannable barcode for biometric and access desks.') }}</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-2.5 p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40 cursor-pointer">
                        <input type="checkbox" wire:model.live="form.show_qr_code" class="w-4 h-4 text-blue-600 rounded border-zinc-300 focus:ring-blue-500" />
                        <div>
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200 block">{{ __('Generate Verification QR Code') }}</span>
                            <span class="text-[11px] text-zinc-500">{{ __('Embeds digital verification payload on card back.') }}</span>
                        </div>
                    </label>

                    <label class="flex items-center gap-2.5 p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/40 cursor-pointer sm:col-span-2">
                        <input type="checkbox" wire:model.live="form.show_emergency_contact" class="w-4 h-4 text-blue-600 rounded border-zinc-300 focus:ring-blue-500" />
                        <div>
                            <span class="font-semibold text-zinc-800 dark:text-zinc-200 block">{{ __('Show Emergency Contacts') }}</span>
                            <span class="text-[11px] text-zinc-500">{{ __('Prints emergency telephone line and support email on card back.') }}</span>
                        </div>
                    </label>
                </div>
            </div>

            <!-- Terms and Conditions Textarea -->
            <div class="p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-4">
                <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2 pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <x-icon name="document-text" class="w-4 h-4 text-emerald-600" />
                    {{ __('Standing Card Instructions & Regulations (Reverse Side)') }}
                </h3>

                <div class="space-y-1">
                    <label class="text-xs font-semibold text-zinc-700 dark:text-zinc-300">{{ __('Terms & Regulations (One item per line)') }}</label>
                    <textarea
                        wire:model="form.terms_and_conditions"
                        rows="6"
                        class="w-full rounded-md border border-input bg-card p-3 text-xs shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 font-sans"
                        placeholder="Enter terms and conditions line by line..."
                    ></textarea>
                </div>
            </div>
        </div>

        <!-- Live Preview Pane (Right 1 col) -->
        <div class="space-y-6">
            <div class="sticky top-6 p-5 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-4">
                <div class="flex items-center justify-between pb-2 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="font-bold text-sm text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                        <x-icon name="eye" class="w-4 h-4 text-blue-600" />
                        {{ __('Live Card Template Preview') }}
                    </h3>
                    <span class="text-[10px] font-mono px-2 py-0.5 rounded bg-blue-50 dark:bg-blue-950/40 text-blue-600 dark:text-blue-400 font-bold">
                        A4 BADGE
                    </span>
                </div>

                <!-- Mini Front Card -->
                <div class="rounded-2xl bg-gradient-to-br from-blue-950 to-slate-900 p-4 text-white shadow-md space-y-3 text-[10px]">
                    <div class="border-b border-white/15 pb-2 text-center">
                        <div class="font-bold text-[11px] truncate">{{ $form['institution_name'] ?: 'Institution Name' }}</div>
                        <div class="text-[8px] text-blue-300 tracking-wider font-semibold uppercase">{{ $form['card_title'] ?: 'STUDENT IDENTITY CARD' }}</div>
                    </div>

                    <div class="flex gap-3 items-center">
                        <div class="w-12 h-16 rounded-lg bg-white/10 border border-white/20 flex items-center justify-center shrink-0">
                            <x-icon name="user" class="w-6 h-6 text-white/50" />
                        </div>
                        <div class="flex-1 space-y-0.5 min-w-0">
                            <div class="font-bold text-xs text-white truncate">Aditya Mukherjee</div>
                            <div class="text-[9px] font-mono text-blue-200">ID: STU-2026-0042</div>
                            <div class="text-[9px] text-zinc-300 truncate">Course: Civil Services 2026</div>
                            @if($form['show_optional_subject'])
                                <div class="text-[9px] text-amber-300 font-semibold truncate">Optional: Public Administration</div>
                            @endif
                        </div>
                    </div>

                    <div class="pt-2 border-t border-white/10 flex items-center justify-between text-[8px] text-blue-200">
                        @if($form['show_blood_group'])
                            <span>Blood: O+</span>
                        @endif
                        <span>Valid: {{ $form['validity_period'] }}</span>
                    </div>
                </div>

                <!-- Mini Back Card -->
                <div class="rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/60 p-3 text-[9px] space-y-1.5 text-zinc-600 dark:text-zinc-300">
                    <div class="font-bold uppercase text-[9px] text-zinc-900 dark:text-zinc-100 pb-1 border-b border-zinc-200 dark:border-zinc-700">
                        {{ __('Card Reverse Regulations') }}
                    </div>
                    <p class="text-[8px] line-clamp-3">
                        {{ $form['terms_and_conditions'] ?: 'Non-transferable official card.' }}
                    </p>
                    <div class="pt-1.5 border-t border-zinc-200 dark:border-zinc-700 flex items-center justify-between text-[8px]">
                        @if($form['show_qr_code'])
                            <span class="font-mono text-blue-600 font-semibold">[QR VERIFIED]</span>
                        @endif
                        <span class="font-semibold text-zinc-700 dark:text-zinc-200">{{ $form['authorized_signatory_title'] }}</span>
                    </div>
                </div>

                <div class="pt-2">
                    <x-ui.button wire:click="save" variant="primary" class="w-full justify-center gap-1.5 text-xs font-semibold">
                        <x-icon name="check" class="w-4 h-4" />
                        {{ __('Save Configuration') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>
</div>
