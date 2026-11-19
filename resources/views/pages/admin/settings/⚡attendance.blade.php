<?php

use App\Models\AuditLog;
use App\Models\Setting;
use App\Services\Attendance\AttendanceNotificationService;
use App\Support\Toast;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Attendance & Leave Settings')] class extends Component {
    public bool $notifyPresent = true;
    public bool $notifyAbsent = true;
    public bool $notifyLate = true;
    public array $channels = ['database', 'email', 'sms', 'whatsapp'];
    public int $mandatoryPercentage = 75;
    public bool $dailyAutoNotifyEnabled = false;
    public string $dailyAutoNotifyTime = '18:00';
    public bool $allowStaffWebCheckin = true;
    public bool $autoSyncLeave = true;
    public bool $allowHalfDay = true;
    public int $defaultStaffQuota = 15;
    public int $defaultStudentQuota = 10;

    public function mount(): void
    {
        $this->notifyPresent = (bool) Setting::get('attendance.notify_student_present', true);
        $this->notifyAbsent = (bool) Setting::get('attendance.notify_student_absent', true);
        $this->notifyLate = (bool) Setting::get('attendance.notify_student_late', true);

        $channelsSetting = Setting::get('attendance.notification_channels', ['database', 'email', 'sms', 'whatsapp']);
        if (is_string($channelsSetting)) {
            $channelsSetting = json_decode($channelsSetting, true) ?: ['database'];
        }
        $this->channels = (array) $channelsSetting;

        $this->mandatoryPercentage = (int) Setting::get('attendance.mandatory_percentage', 75);
        $this->dailyAutoNotifyEnabled = (bool) Setting::get('attendance.daily_auto_notify_enabled', false);
        $this->dailyAutoNotifyTime = (string) Setting::get('attendance.daily_auto_notify_time', '18:00');
        $this->allowStaffWebCheckin = (bool) Setting::get('attendance.allow_staff_web_checkin', true);
        $this->autoSyncLeave = (bool) Setting::get('leave.auto_sync_to_attendance', true);
        $this->allowHalfDay = (bool) Setting::get('leave.allow_half_day', true);
        $this->defaultStaffQuota = (int) Setting::get('leave.default_annual_staff_quota', 15);
        $this->defaultStudentQuota = (int) Setting::get('leave.default_annual_student_quota', 10);
    }

    public function saveSettings(): void
    {
        try {
            $updates = [
                'attendance.notify_student_present' => $this->notifyPresent ? '1' : '0',
                'attendance.notify_student_absent' => $this->notifyAbsent ? '1' : '0',
                'attendance.notify_student_late' => $this->notifyLate ? '1' : '0',
                'attendance.notification_channels' => json_encode($this->channels),
                'attendance.mandatory_percentage' => (string) $this->mandatoryPercentage,
                'attendance.daily_auto_notify_enabled' => $this->dailyAutoNotifyEnabled ? '1' : '0',
                'attendance.daily_auto_notify_time' => $this->dailyAutoNotifyTime,
                'attendance.allow_staff_web_checkin' => $this->allowStaffWebCheckin ? '1' : '0',
                'leave.auto_sync_to_attendance' => $this->autoSyncLeave ? '1' : '0',
                'leave.allow_half_day' => $this->allowHalfDay ? '1' : '0',
                'leave.default_annual_staff_quota' => (string) $this->defaultStaffQuota,
                'leave.default_annual_student_quota' => (string) $this->defaultStudentQuota,
            ];

            foreach ($updates as $key => $val) {
                Setting::set($key, $val);
            }

            AuditLog::record(
                action: 'settings_updated',
                details: $updates,
                description: 'Updated Attendance and Leave Management policies and notification channels'
            );

            Toast::dispatch($this, 'success', __('Attendance & Leave settings saved successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save settings: :err', ['err' => $e->getMessage()]));
        }
    }

    public function sendTestNotification(AttendanceNotificationService $notificationService): void
    {
        try {
            $user = auth()->user();
            $notificationService->sendAttendanceReminder(
                $user,
                'test',
                __('This is a test notification from SNTCSSC Attendance Management confirming your channels are operational.'),
                $this->channels
            );

            Toast::dispatch($this, 'success', __('Test notification dispatched to :email across active channels.', ['email' => $user->email]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to send test alert: :err', ['err' => $e->getMessage()]));
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.attendance.index') }}" wire:navigate class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted">
                    <x-icon name="arrow-left" class="w-5 h-5" />
                </a>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Attendance & Leave Policies') }}</h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Configure notification channels (In-app, Email, SMS, WhatsApp, Telegram), automated absent reminders, and leave quotas.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button variant="outline" size="sm" wire:click="sendTestNotification" class="cursor-pointer">
                <x-icon name="send" class="w-4 h-4 mr-1.5" />
                {{ __('Send Test Alert') }}
            </x-ui.button>

            <x-ui.button variant="default" size="sm" wire:click="saveSettings" class="cursor-pointer bg-primary text-primary-foreground font-semibold">
                <x-icon name="save" class="w-4 h-4 mr-1.5" />
                {{ __('Save Settings') }}
            </x-ui.button>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
        <!-- 1. Real-Time Notification Triggers -->
        <div class="p-5 rounded-xl border border-border bg-card shadow-xs space-y-4">
            <div class="flex items-center gap-2 pb-3 border-b border-border">
                <x-icon name="bell" class="w-5 h-5 text-indigo-600 dark:text-indigo-400" />
                <h2 class="text-sm font-bold text-foreground">{{ __('Instant Attendance Notifications') }}</h2>
            </div>

            <div class="space-y-3">
                <label class="flex items-start gap-3 p-3 rounded-lg border border-border bg-muted/20 cursor-pointer">
                    <input type="checkbox" wire:model="notifyPresent" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                    <div>
                        <strong class="text-xs text-foreground block">{{ __('Notify Student When Marked Present') }}</strong>
                        <span class="text-[11px] text-muted-foreground">{{ __('Sends positive confirmation of classroom presence with punch timestamp.') }}</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-3 rounded-lg border border-border bg-muted/20 cursor-pointer">
                    <input type="checkbox" wire:model="notifyAbsent" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                    <div>
                        <strong class="text-xs text-foreground block">{{ __('Notify Student When Marked Absent') }}</strong>
                        <span class="text-[11px] text-muted-foreground">{{ __('Sends immediate absence alert with attendance percentage warning.') }}</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-3 rounded-lg border border-border bg-muted/20 cursor-pointer">
                    <input type="checkbox" wire:model="notifyLate" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                    <div>
                        <strong class="text-xs text-foreground block">{{ __('Notify Student When Marked Late') }}</strong>
                        <span class="text-[11px] text-muted-foreground">{{ __('Notifies when punch time exceeds the late grace window threshold.') }}</span>
                    </div>
                </label>
            </div>

            <!-- Active Channels Selection -->
            <div class="pt-3 border-t border-border">
                <label class="block text-xs font-semibold text-foreground mb-2">{{ __('Enabled Delivery Channels') }}</label>
                <div class="grid grid-cols-2 sm:grid-cols-3 gap-2 text-xs">
                    @foreach(['database' => 'In-App Notification', 'email' => 'Email Dispatch', 'sms' => 'SMS Gateway', 'whatsapp' => 'WhatsApp Service', 'telegram' => 'Telegram Bot'] as $key => $label)
                        <label class="flex items-center gap-2 p-2 rounded-lg border border-border bg-muted/20 cursor-pointer">
                            <input type="checkbox" value="{{ $key }}" wire:model="channels" class="rounded border-border text-primary focus:ring-primary" />
                            <span>{{ $label }}</span>
                        </label>
                    @endforeach
                </div>
            </div>
        </div>

        <!-- 2. Academic Thresholds & Automated Reminders -->
        <div class="p-5 rounded-xl border border-border bg-card shadow-xs space-y-4">
            <div class="flex items-center gap-2 pb-3 border-b border-border">
                <x-icon name="shield-alert" class="w-5 h-5 text-amber-600 dark:text-amber-400" />
                <h2 class="text-sm font-bold text-foreground">{{ __('Thresholds & Automated Cron Jobs') }}</h2>
            </div>

            <div class="space-y-4">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Mandatory Minimum Attendance Percentage (%)') }} *
                    </label>
                    <input
                        type="number"
                        min="50"
                        max="100"
                        wire:model="mandatoryPercentage"
                        class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg"
                    />
                    <span class="text-[11px] text-muted-foreground mt-0.5 block">
                        {{ __('Students falling below this threshold are flagged in the Defaulters Register.') }}
                    </span>
                </div>

                <div class="p-3.5 rounded-lg border border-border bg-muted/20 space-y-3">
                    <label class="flex items-start gap-3 cursor-pointer">
                        <input type="checkbox" wire:model="dailyAutoNotifyEnabled" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                        <div>
                            <strong class="text-xs text-foreground block">{{ __('Enable Automatic Daily Absentee Notifications') }}</strong>
                            <span class="text-[11px] text-muted-foreground">{{ __('Scheduled command (attendance:daily-notify) dispatches alerts automatically every evening.') }}</span>
                        </div>
                    </label>

                    @if($dailyAutoNotifyEnabled)
                        <div class="pt-2 border-t border-border">
                            <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Daily Dispatch Time') }}</label>
                            <input type="time" wire:model="dailyAutoNotifyTime" class="w-48 px-3 py-1.5 text-xs font-mono bg-background border border-border rounded-lg" />
                        </div>
                    @endif
                </div>

                <label class="flex items-start gap-3 p-3 rounded-lg border border-border bg-muted/20 cursor-pointer">
                    <input type="checkbox" wire:model="allowStaffWebCheckin" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                    <div>
                        <strong class="text-xs text-foreground block">{{ __('Allow Office Staff Web Check-In / Check-Out') }}</strong>
                        <span class="text-[11px] text-muted-foreground">{{ __('Enables office staff and faculty to clock in/out directly from their dashboard.') }}</span>
                    </div>
                </label>
            </div>
        </div>

        <!-- 3. Leave Integration & Sync Policies -->
        <div class="col-span-full p-5 rounded-xl border border-border bg-card shadow-xs space-y-4">
            <div class="flex items-center gap-2 pb-3 border-b border-border">
                <x-icon name="repeat" class="w-5 h-5 text-teal-600 dark:text-teal-400" />
                <h2 class="text-sm font-bold text-foreground">{{ __('Leave Management Integration & Sync Policies') }}</h2>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <label class="flex items-start gap-3 p-3 rounded-lg border border-border bg-muted/20 cursor-pointer">
                    <input type="checkbox" wire:model="autoSyncLeave" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                    <div>
                        <strong class="text-xs text-foreground block">{{ __('Auto-Sync Approved Leaves to Attendance Register') }}</strong>
                        <span class="text-[11px] text-muted-foreground">{{ __('When leave is approved, system automatically creates "On Leave" attendance records for those dates.') }}</span>
                    </div>
                </label>

                <label class="flex items-start gap-3 p-3 rounded-lg border border-border bg-muted/20 cursor-pointer">
                    <input type="checkbox" wire:model="allowHalfDay" class="mt-0.5 rounded border-border text-primary focus:ring-primary" />
                    <div>
                        <strong class="text-xs text-foreground block">{{ __('Allow Half-Day Leave Applications') }}</strong>
                        <span class="text-[11px] text-muted-foreground">{{ __('Permits applicants to select First Half or Second Half 0.5-day leaves.') }}</span>
                    </div>
                </label>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Default Annual Office Staff Leave Quota (Days)') }}</label>
                    <input type="number" min="0" max="60" wire:model="defaultStaffQuota" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Default Annual Student Leave Quota (Days)') }}</label>
                    <input type="number" min="0" max="60" wire:model="defaultStudentQuota" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
            </div>
        </div>
    </div>
</div>
