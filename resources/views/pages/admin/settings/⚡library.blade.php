<?php

use App\Models\Setting;
use App\Services\AuditLogService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Library Policy & System Settings')] class extends Component {
    public array $form = [
        'default_loan_days' => 15,
        'max_books_allowed' => 3,
        'max_renewals' => 2,
        'renewal_duration_days' => 15,
        'require_renewal_approval' => true,
        'fines_enabled' => true,
        'fine_per_day' => 5.00,
        'require_security_deposit' => true,
        'allow_deposit_bypass' => true,
        'reservation_expiry_days' => 3,
        'reminder_days_before' => 2,
        'reminder_channels' => ['database', 'email', 'sms'],
        'inhouse_reading_enabled' => true,
        'inhouse_reading_start_time' => '11:00',
        'inhouse_reading_end_time' => '17:00',
        'inhouse_reading_max_items' => 5,
    ];

    public function mount(): void
    {
        $settings = Setting::query()->where('group', 'library')->get()->keyBy('key');

        foreach ($this->form as $key => $default) {
            $fullKey = "library.{$key}";
            if (isset($settings[$fullKey])) {
                if (is_bool($default)) {
                    $this->form[$key] = (bool) $settings[$fullKey]->typed();
                } elseif (is_array($default)) {
                    $val = $settings[$fullKey]->typed();
                    $this->form[$key] = is_array($val) ? $val : (json_decode((string) $val, true) ?: $default);
                } elseif (is_float($default) || is_numeric($default)) {
                    $this->form[$key] = $settings[$fullKey]->typed();
                } else {
                    $this->form[$key] = $settings[$fullKey]->rawValue() ?? $default;
                }
            }
        }
    }

    public function save(): void
    {
        $this->validate([
            'form.default_loan_days' => ['required', 'integer', 'min:1', 'max:90'],
            'form.max_books_allowed' => ['required', 'integer', 'min:1', 'max:20'],
            'form.max_renewals' => ['required', 'integer', 'min:0', 'max:10'],
            'form.renewal_duration_days' => ['required', 'integer', 'min:1', 'max:90'],
            'form.require_renewal_approval' => ['boolean'],
            'form.fines_enabled' => ['boolean'],
            'form.fine_per_day' => ['required', 'numeric', 'min:0'],
            'form.require_security_deposit' => ['boolean'],
            'form.allow_deposit_bypass' => ['boolean'],
            'form.reservation_expiry_days' => ['required', 'integer', 'min:1', 'max:30'],
            'form.reminder_days_before' => ['required', 'integer', 'min:0', 'max:14'],
            'form.reminder_channels' => ['array'],
            'form.inhouse_reading_enabled' => ['boolean'],
            'form.inhouse_reading_start_time' => ['required', 'string'],
            'form.inhouse_reading_end_time' => ['required', 'string'],
            'form.inhouse_reading_max_items' => ['required', 'integer', 'min:1', 'max:20'],
        ]);

        try {
            DB::beginTransaction();

            foreach ($this->form as $key => $value) {
                $type = is_bool($value) ? Setting::TYPE_BOOLEAN : (is_array($value) ? Setting::TYPE_ARRAY : (is_numeric($value) ? (is_int($value) ? Setting::TYPE_INTEGER : Setting::TYPE_FLOAT) : Setting::TYPE_STRING));

                Setting::set("library.{$key}", $value, $type, 'library');
            }

            DB::commit();

            Toast::dispatch($this, 'success', __('Library policy settings successfully updated.'));
        } catch (\Exception $e) {
            DB::rollBack();
            Toast::dispatch($this, 'error', $e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100 flex items-center gap-2">
                <span class="inline-flex items-center justify-center p-2 rounded-xl bg-purple-500/10 text-purple-600 dark:text-purple-400">
                    <x-icon name="cog-6-tooth" class="w-6 h-6" />
                </span>
                {{ __('Library Policy & Configuration') }}
            </h1>
            <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                {{ __('Configure borrowing durations, fine calculations, security deposit rules, and multi-channel reminder channels.') }}
            </p>
        </div>

        <div>
            <button
                type="button"
                wire:click="save"
                class="inline-flex items-center gap-2 px-5 py-2.5 text-sm font-semibold rounded-xl bg-purple-600 hover:bg-purple-700 text-white shadow-sm transition"
            >
                <x-icon name="check" class="w-4 h-4" />
                {{ __('Save Policy Settings') }}
            </button>
        </div>
    </div>

    <form wire:submit="save" class="space-y-6">
        <!-- 1. Borrowing Timelines & Quotas -->
        <div class="p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-5">
            <div class="flex items-center gap-2 pb-3 border-b border-zinc-200 dark:border-zinc-800">
                <x-icon name="clock" class="w-5 h-5 text-purple-600 dark:text-purple-400" />
                <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ __('Borrowing Duration & Quotas') }}</h3>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Default Book Loan Duration (Days) *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.default_loan_days"
                        min="1"
                        max="90"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('Standard loan period for borrowers before renewal or return is due (default: 15 days).') }}
                    </p>
                    @error('form.default_loan_days') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Maximum Books Allowed per Borrower *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.max_books_allowed"
                        min="1"
                        max="20"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('Maximum concurrent active loans permitted per library card.') }}
                    </p>
                    @error('form.max_books_allowed') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Maximum Renewals Allowed *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.max_renewals"
                        min="0"
                        max="10"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('Number of times a borrower may extend an active loan without returning the physical copy.') }}
                    </p>
                    @error('form.max_renewals') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Extension Period per Renewal (Days) *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.renewal_duration_days"
                        min="1"
                        max="90"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('Additional days granted when an active book loan is renewed (default: 15 days).') }}
                    </p>
                    @error('form.renewal_duration_days') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                </div>

                <div class="col-span-1 md:col-span-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-start gap-3 p-3.5 rounded-xl border border-purple-200 dark:border-purple-900/40 bg-purple-50/50 dark:bg-purple-950/20">
                        <input
                            type="checkbox"
                            id="require_renewal_approval"
                            wire:model.live="form.require_renewal_approval"
                            class="mt-1 w-4 h-4 text-purple-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-purple-500"
                        />
                        <label for="require_renewal_approval" class="cursor-pointer">
                            <span class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ __('Require Librarian / Admin Approval for Student Loan Renewals') }}
                            </span>
                            <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                                {{ __('When enabled, students requesting online loan renewals must be approved by a Librarian or Admin at the Circulation Desk before the due date is extended.') }}
                            </span>
                        </label>
                    </div>
                </div>
            </div>
        </div>

        <!-- 2. Overdue Fines & Penalties -->
        <div class="p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-5">
            <div class="flex items-center gap-2 pb-3 border-b border-zinc-200 dark:border-zinc-800">
                <x-icon name="coins" class="w-5 h-5 text-amber-500" />
                <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ __('Overdue Fines & Penalties Policy') }}</h3>
            </div>

            <div class="space-y-4">
                <div class="flex items-start gap-3 p-4 rounded-xl border border-amber-200 dark:border-amber-900/40 bg-amber-50/50 dark:bg-amber-950/20">
                    <input
                        type="checkbox"
                        id="fines_enabled"
                        wire:model.live="form.fines_enabled"
                        class="mt-1 w-4 h-4 text-purple-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-purple-500"
                    />
                    <label for="fines_enabled" class="cursor-pointer">
                        <span class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Enable Overdue Fine & Penalty Assessment') }}
                        </span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('When enabled, loans exceeding the 15-day timeline automatically accrue fine amounts until renewed or returned.') }}
                        </span>
                    </label>
                </div>

                @if($form['fines_enabled'])
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6 pt-2">
                        <div>
                            <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                                {{ __('Fine Rate per Day Overdue (₹) *') }}
                            </label>
                            <input
                                type="number"
                                step="0.50"
                                wire:model="form.fine_per_day"
                                min="0"
                                class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                            />
                            <p class="mt-1 text-2xs text-zinc-400">
                                {{ __('Daily overdue charge accrued per day past the loan due date (e.g. ₹5.00/day).') }}
                            </p>
                            @error('form.fine_per_day') <span class="text-xs text-rose-600">{{ $message }}</span> @enderror
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <!-- 3. Security Deposit & Bypass Policy -->
        <div class="p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-5">
            <div class="flex items-center gap-2 pb-3 border-b border-zinc-200 dark:border-zinc-800">
                <x-icon name="shield-check" class="w-5 h-5 text-emerald-600 dark:text-emerald-400" />
                <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ __('Library Security Deposit & Staff Bypass') }}</h3>
            </div>

            <div class="space-y-4">
                <div class="flex items-start gap-3 p-4 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/40">
                    <input
                        type="checkbox"
                        id="require_security_deposit"
                        wire:model="form.require_security_deposit"
                        class="mt-1 w-4 h-4 text-purple-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-purple-500"
                    />
                    <label for="require_security_deposit" class="cursor-pointer">
                        <span class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Enforce Security Deposit Threshold Rule') }}
                        </span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('Requires that total value of currently borrowed books must be less than or equal to member\'s deposited security amount.') }}
                        </span>
                    </label>
                </div>

                <div class="flex items-start gap-3 p-4 rounded-xl border border-purple-200 dark:border-purple-900/40 bg-purple-50/50 dark:bg-purple-950/20">
                    <input
                        type="checkbox"
                        id="allow_deposit_bypass"
                        wire:model="form.allow_deposit_bypass"
                        class="mt-1 w-4 h-4 text-purple-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-purple-500"
                    />
                    <label for="allow_deposit_bypass" class="cursor-pointer">
                        <span class="block text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                            {{ __('Permit Librarian Security Deposit Bypass (Special Authorization)') }}
                        </span>
                        <span class="block text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('Allows librarians to temporarily issue books without security deposit checks (with mandatory recorded justification) for faculty, staff, or short-term institutional borrowing.') }}
                        </span>
                    </label>
                </div>
            </div>
        </div>

        <!-- 4. Reservations & Multi-Channel Reminders -->
        <div class="p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-5">
            <div class="flex items-center gap-2 pb-3 border-b border-zinc-200 dark:border-zinc-800">
                <x-icon name="bell" class="w-5 h-5 text-blue-600 dark:text-blue-400" />
                <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ __('Holds, Reservations & Reminder Channels') }}</h3>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Reservation Hold Expiry (Days) *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.reservation_expiry_days"
                        min="1"
                        max="30"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('Days a reserved book is kept on hold for a student before being automatically released.') }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Send Due Date Notice (Days in Advance) *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.reminder_days_before"
                        min="0"
                        max="14"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-purple-500"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('Days before the 15-day due date to send an upcoming return notice.') }}
                    </p>
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-2">
                    {{ __('Active Communication Channels for Return Reminders') }}
                </label>
                <div class="grid grid-cols-2 sm:grid-cols-5 gap-3">
                    @foreach([
                        'database' => 'In-App Notification',
                        'email' => 'Email',
                        'sms' => 'SMS Gateway',
                        'whatsapp' => 'WhatsApp',
                        'telegram' => 'Telegram Bot',
                    ] as $channel => $label)
                        <label class="p-3 rounded-xl border border-zinc-200 dark:border-zinc-800 bg-zinc-50 dark:bg-zinc-800/40 flex items-center gap-2 cursor-pointer hover:bg-zinc-100 dark:hover:bg-zinc-800 transition">
                            <input
                                type="checkbox"
                                value="{{ $channel }}"
                                wire:model="form.reminder_channels"
                                class="w-4 h-4 text-purple-600 rounded border-zinc-300 dark:border-zinc-700 focus:ring-purple-500"
                            />
                            <span class="text-xs font-medium text-zinc-800 dark:text-zinc-200">{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- 5. In-House Library Reading Room Facility -->
        <div class="p-6 rounded-2xl border border-zinc-200 dark:border-zinc-800 bg-white dark:bg-zinc-900 shadow-xs space-y-5">
            <div class="flex items-center justify-between pb-3 border-b border-zinc-200 dark:border-zinc-800">
                <div class="flex items-center gap-2">
                    <x-icon name="book-open" class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                    <div>
                        <h3 class="font-bold text-base text-zinc-900 dark:text-zinc-100">{{ __('In-House Library Reading Room Facility') }}</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                            {{ __('Serve one or multiple books, journals, or reference materials for reading in library rooms without checking security deposits.') }}
                        </p>
                    </div>
                </div>

                <label class="relative inline-flex items-center cursor-pointer">
                    <input type="checkbox" wire:model="form.inhouse_reading_enabled" class="sr-only peer">
                    <div class="w-11 h-6 bg-zinc-200 peer-focus:outline-hidden rounded-full peer dark:bg-zinc-700 peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:start-[2px] after:bg-white after:border-zinc-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-zinc-600 peer-checked:bg-teal-600"></div>
                </label>
            </div>

            <div class="p-4 rounded-xl border border-teal-200 dark:border-teal-900/40 bg-teal-50/50 dark:bg-teal-950/20 text-xs text-teal-900 dark:text-teal-200 flex items-start gap-2.5">
                <x-icon name="information-circle" class="w-4 h-4 text-teal-600 dark:text-teal-400 mt-0.5 shrink-0" />
                <div>
                    <span class="font-semibold">{{ __('Zero Security Deposit Requirement') }}</span>
                    <p class="mt-0.5 text-zinc-600 dark:text-zinc-300 text-2xs">
                        {{ __('Books and journals served for in-house library reading do NOT check the borrower\'s security deposit criteria. All issued materials must be returned before official hours end and can be taken back anytime within the day by the librarian.') }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Official Reading Hours Start Time *') }}
                    </label>
                    <input
                        type="time"
                        wire:model="form.inhouse_reading_start_time"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-teal-500 font-mono"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('e.g. 11:00 AM — Reading room opens for students.') }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Official Reading Hours End Time (Closing) *') }}
                    </label>
                    <input
                        type="time"
                        wire:model="form.inhouse_reading_end_time"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-teal-500 font-mono"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('e.g. 05:00 PM (17:00) — Daily deadline to return all reading room items.') }}
                    </p>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ __('Max Books/Journals Per Session *') }}
                    </label>
                    <input
                        type="number"
                        wire:model="form.inhouse_reading_max_items"
                        min="1"
                        max="20"
                        class="w-full px-3 py-2 text-sm rounded-lg border border-zinc-200 dark:border-zinc-700 bg-white dark:bg-zinc-800 text-zinc-900 dark:text-zinc-100 focus:ring-2 focus:ring-teal-500 font-mono"
                    />
                    <p class="mt-1 text-2xs text-zinc-400">
                        {{ __('Maximum items a reader can hold concurrently in the reading room.') }}
                    </p>
                </div>
            </div>
        </div>

        <div class="flex justify-end">
            <button
                type="submit"
                class="inline-flex items-center gap-2 px-6 py-2.5 text-sm font-semibold rounded-xl bg-purple-600 hover:bg-purple-700 text-white shadow-sm transition"
            >
                <x-icon name="check" class="w-4 h-4" />
                {{ __('Save Policy Settings') }}
            </button>
        </div>
    </form>
</div>
