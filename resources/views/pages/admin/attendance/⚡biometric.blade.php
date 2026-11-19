<?php

use App\Models\AuditLog;
use App\Models\BiometricDevice;
use App\Models\BiometricLog;
use App\Models\User;
use App\Services\Attendance\AttendanceService;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Biometric Machine Integration Hub')] class extends Component {
    use WithPagination;

    // Modal state for device CRUD
    public bool $isEditing = false;
    public ?int $editingDeviceId = null;
    public string $deviceName = '';
    public string $deviceSerialNumber = '';
    public string $deviceIp = '';
    public int $devicePort = 4370;
    public string $deviceLocation = '';
    public string $deviceType = 'hybrid';
    public string $deviceStatus = 'online';

    // Delete / regenerate confirmations
    public ?int $confirmTokenDeviceId = null;
    public ?int $deleteDeviceId = null;

    // Simulation punch test
    public string $simPin = '';
    public string $simCard = '';
    public string $simPunchType = 'check_in';
    public ?int $simDeviceId = null;

    public int $logsPerPage = 15;

    #[Computed]
    public function devices()
    {
        return BiometricDevice::withCount(['logs', 'attendances'])->orderBy('id')->get();
    }

    #[Computed]
    public function rawLogs()
    {
        return BiometricLog::with(['device', 'user'])
            ->latest('punch_time')
            ->paginate($this->logsPerPage);
    }

    public function openCreateDeviceModal(): void
    {
        $this->isEditing = false;
        $this->editingDeviceId = null;
        $this->deviceName = '';
        $this->deviceSerialNumber = 'BIO-' . strtoupper(Str::random(8));
        $this->deviceIp = '192.168.1.201';
        $this->devicePort = 4370;
        $this->deviceLocation = 'Main Academic Block Entrance';
        $this->deviceType = 'hybrid';
        $this->deviceStatus = 'online';
        $this->dispatch('modal-open', name: 'device-form-modal');
    }

    public function openEditDeviceModal(int $id): void
    {
        $device = BiometricDevice::findOrFail($id);
        $this->isEditing = true;
        $this->editingDeviceId = $device->id;
        $this->deviceName = $device->device_name;
        $this->deviceSerialNumber = $device->device_serial_number;
        $this->deviceIp = $device->device_ip ?? '';
        $this->devicePort = (int) $device->port;
        $this->deviceLocation = $device->location ?? '';
        $this->deviceType = $device->device_type;
        $this->deviceStatus = $device->status;
        $this->dispatch('modal-open', name: 'device-form-modal');
    }

    public function saveDevice(): void
    {
        $this->validate([
            'deviceName' => 'required|string|max:100',
            'deviceSerialNumber' => 'required|string|max:100|unique:biometric_devices,device_serial_number,' . $this->editingDeviceId,
            'devicePort' => 'required|integer|min:1|max:65535',
            'deviceType' => 'required|string',
        ]);

        try {
            if ($this->isEditing) {
                $device = BiometricDevice::findOrFail($this->editingDeviceId);
                $device->update([
                    'device_name' => $this->deviceName,
                    'device_serial_number' => $this->deviceSerialNumber,
                    'device_ip' => $this->deviceIp,
                    'port' => $this->devicePort,
                    'location' => $this->deviceLocation,
                    'device_type' => $this->deviceType,
                    'status' => $this->deviceStatus,
                    'updated_by' => auth()->id(),
                ]);
                Toast::dispatch($this, 'success', __('Biometric device ":name" updated successfully.', ['name' => $device->device_name]));
            } else {
                $device = BiometricDevice::create([
                    'device_name' => $this->deviceName,
                    'device_serial_number' => $this->deviceSerialNumber,
                    'device_ip' => $this->deviceIp,
                    'port' => $this->devicePort,
                    'location' => $this->deviceLocation,
                    'device_type' => $this->deviceType,
                    'status' => $this->deviceStatus,
                    'api_token' => 'bio_' . Str::random(40),
                    'created_by' => auth()->id(),
                ]);
                Toast::dispatch($this, 'success', __('Biometric device ":name" registered successfully.', ['name' => $device->device_name]));
            }

            $this->dispatch('modal-close', name: 'device-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save device: :err', ['err' => $e->getMessage()]));
        }
    }

    public function confirmRegenerateToken(int $id): void
    {
        $this->confirmTokenDeviceId = $id;
        $this->dispatch('modal-open', name: 'regenerate-token-modal');
    }

    public function regenerateToken(): void
    {
        if (! $this->confirmTokenDeviceId) {
            return;
        }

        try {
            $device = BiometricDevice::findOrFail($this->confirmTokenDeviceId);
            $device->update([
                'api_token' => 'bio_' . Str::random(40),
            ]);
            Toast::dispatch($this, 'success', __('Generated fresh API key for ":name".', ['name' => $device->device_name]));
            $this->dispatch('modal-close', name: 'regenerate-token-modal');
            $this->confirmTokenDeviceId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to regenerate key: :err', ['err' => $e->getMessage()]));
        }
    }

    public function confirmDeleteDevice(int $id): void
    {
        $this->deleteDeviceId = $id;
        $this->dispatch('modal-open', name: 'delete-device-modal');
    }

    public function deleteDevice(): void
    {
        if (! $this->deleteDeviceId) {
            return;
        }

        try {
            $device = BiometricDevice::findOrFail($this->deleteDeviceId);
            $device->delete();
            Toast::dispatch($this, 'success', __('Device removed successfully.'));
            $this->dispatch('modal-close', name: 'delete-device-modal');
            $this->deleteDeviceId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to remove device: :err', ['err' => $e->getMessage()]));
        }
    }

    public function processPunches(AttendanceService $attendanceService): void
    {
        try {
            $result = $attendanceService->processBiometricPunches(500);
            Toast::dispatch($this, 'success', __('Processed :processed punches successfully (:errors errors).', [
                'processed' => $result['processed'],
                'errors' => $result['errors'],
            ]));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to process punches: :err', ['err' => $e->getMessage()]));
        }
    }

    public function simulatePunch(AttendanceService $attendanceService): void
    {
        $this->validate([
            'simPin' => 'required|string',
        ]);

        try {
            $device = $this->simDeviceId ? BiometricDevice::find($this->simDeviceId) : BiometricDevice::first();

            $log = BiometricLog::create([
                'biometric_device_id' => $device?->id,
                'biometric_pin' => $this->simPin,
                'card_number' => $this->simCard ?: null,
                'punch_time' => Carbon::now(),
                'punch_type' => $this->simPunchType,
                'raw_payload' => ['simulated' => true],
                'is_processed' => false,
            ]);

            // Process immediately
            $attendanceService->processBiometricPunches(10);

            Toast::dispatch($this, 'success', __('Simulated punch recorded and processed for PIN/ID: :pin', ['pin' => $this->simPin]));
            $this->simPin = '';
            $this->simCard = '';
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Simulation failed: :err', ['err' => $e->getMessage()]));
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
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Biometric Devices & Machine Hub') }}</h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Manage fingerprint, facial recognition, and RFID smart card attendance terminals with real-time webhooks.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <x-ui.button
                variant="outline"
                size="sm"
                wire:click="processPunches"
                wire:loading.attr="disabled"
                class="cursor-pointer"
            >
                <x-icon name="refresh-cw" class="w-4 h-4 mr-1.5" wire:loading.class="animate-spin" wire:target="processPunches" />
                {{ __('Process Raw Logs') }}
            </x-ui.button>

            @can('attendance.settings')
                <x-ui.button
                    variant="default"
                    size="sm"
                    wire:click="openCreateDeviceModal"
                    class="cursor-pointer bg-primary text-primary-foreground font-semibold"
                >
                    <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                    {{ __('Register Device') }}
                </x-ui.button>
            @endcan
        </div>
    </div>

    <!-- Registered Devices Grid -->
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        @forelse($this->devices as $dev)
            <div class="p-4 rounded-xl border border-border bg-card shadow-xs flex flex-col justify-between">
                <div>
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border mb-1.5 {{ $dev->statusBadgeClass() }}">
                                <span class="w-1.5 h-1.5 rounded-full {{ $dev->status === 'online' ? 'bg-emerald-500' : 'bg-rose-500' }} mr-1"></span>
                                {{ ucfirst($dev->status) }}
                            </span>
                            <h3 class="font-bold text-foreground text-sm">{{ $dev->device_name }}</h3>
                            <span class="text-xs text-muted-foreground block font-mono">{{ $dev->device_serial_number }}</span>
                        </div>
                        <span class="p-2 rounded-lg bg-teal-500/10 text-teal-600 dark:text-teal-400">
                            <x-icon name="{{ match($dev->device_type) { 'facial_recognition' => 'scan-face', 'rfid_card' => 'credit-card', default => 'fingerprint' } }}" class="w-5 h-5" />
                        </span>
                    </div>

                    <div class="mt-4 pt-3 border-t border-border/60 grid grid-cols-2 gap-2 text-xs">
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('IP & Port') }}</span>
                            <span class="font-mono text-foreground">{{ $dev->device_ip ?: 'DHCP' }}:{{ $dev->port }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Location') }}</span>
                            <span class="text-foreground truncate block">{{ $dev->location ?: 'Unassigned' }}</span>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Total Logs Captured') }}</span>
                            <strong class="font-mono text-foreground">{{ number_format($dev->logs_count) }}</strong>
                        </div>
                        <div>
                            <span class="text-[10px] text-muted-foreground block">{{ __('Last Heartbeat') }}</span>
                            <span class="text-foreground font-mono text-[11px]">
                                {{ $dev->last_heartbeat_at ? $dev->last_heartbeat_at->shortRelativeDiffForHumans() : __('Never') }}
                            </span>
                        </div>
                    </div>

                    <!-- API Key Snippet -->
                    <div class="mt-3 p-2 rounded-lg bg-muted/60 border border-border/40 text-[11px]">
                        <div class="flex items-center justify-between text-muted-foreground mb-1">
                            <span>{{ __('Device API Token') }}</span>
                            <button
                                type="button"
                                wire:click="confirmRegenerateToken({{ $dev->id }})"
                                class="text-[10px] text-primary hover:underline cursor-pointer"
                            >
                                {{ __('Rotate Key') }}
                            </button>
                        </div>
                        <code class="font-mono text-[10px] text-foreground block truncate select-all">{{ $dev->api_token }}</code>
                    </div>
                </div>

                <div class="mt-4 pt-3 border-t border-border flex items-center justify-end gap-1">
                    <x-ui.button variant="ghost" size="xs" wire:click="openEditDeviceModal({{ $dev->id }})" class="cursor-pointer text-xs">
                        <x-icon name="edit" class="w-3.5 h-3.5 mr-1" />
                        {{ __('Edit') }}
                    </x-ui.button>
                    <x-ui.button
                        variant="ghost"
                        size="xs"
                        wire:click="confirmDeleteDevice({{ $dev->id }})"
                        class="cursor-pointer text-xs text-rose-600 hover:bg-rose-500/10"
                    >
                        <x-icon name="trash-2" class="w-3.5 h-3.5 mr-1" />
                        {{ __('Remove') }}
                    </x-ui.button>
                </div>
            </div>
        @empty
            <div class="col-span-full p-8 text-center rounded-xl border border-dashed border-border bg-card">
                <x-icon name="fingerprint" class="w-10 h-10 mx-auto text-muted-foreground/40 mb-2" />
                <h3 class="font-semibold text-foreground text-sm">{{ __('No Biometric Devices Registered') }}</h3>
                <p class="text-xs text-muted-foreground mt-1 max-w-sm mx-auto">
                    {{ __('Connect biometric fingerprint or RFID attendance machines using our REST webhook API.') }}
                </p>
                <div class="mt-4">
                    <x-ui.button variant="default" size="sm" wire:click="openCreateDeviceModal">
                        {{ __('Register First Machine') }}
                    </x-ui.button>
                </div>
            </div>
        @endforelse
    </div>

    <!-- Quick Simulation & API Guide -->
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
        <!-- Live Punch Test Simulator -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <h3 class="text-sm font-semibold text-foreground flex items-center gap-2">
                <x-icon name="play-circle" class="w-4 h-4 text-emerald-600" />
                {{ __('Simulate Inbound Biometric Punch') }}
            </h3>
            <p class="text-xs text-muted-foreground mt-1 mb-4">
                {{ __('Test user identification against biometric_pin, student_id, or RFID card without physical device.') }}
            </p>

            <form wire:submit="simulatePunch" class="space-y-3">
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Biometric PIN / Student ID / Roll') }} *</label>
                        <input
                            type="text"
                            wire:model="simPin"
                            placeholder="{{ __('e.g. 2026001 or STU-101') }}"
                            class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg font-mono"
                        />
                    </div>
                    <div>
                        <label class="block text-xs font-medium text-muted-foreground mb-1">{{ __('Punch Type') }}</label>
                        <select wire:model="simPunchType" class="w-full px-3 py-1.5 text-xs bg-background border border-border rounded-lg">
                            <option value="check_in">{{ __('Check-In (In)') }}</option>
                            <option value="check_out">{{ __('Check-Out (Out)') }}</option>
                            <option value="auto">{{ __('Auto-Detect') }}</option>
                        </select>
                    </div>
                </div>

                <div class="flex justify-end pt-2">
                    <x-ui.button type="submit" variant="default" size="sm" class="bg-emerald-600 hover:bg-emerald-700 text-white font-medium">
                        <x-icon name="zap" class="w-3.5 h-3.5 mr-1" />
                        {{ __('Simulate Punch') }}
                    </x-ui.button>
                </div>
            </form>
        </div>

        <!-- Webhook Integration Endpoint Guide -->
        <div class="p-4 rounded-xl border border-border bg-card shadow-xs">
            <h3 class="text-sm font-semibold text-foreground flex items-center gap-2">
                <x-icon name="code" class="w-4 h-4 text-indigo-600" />
                {{ __('Biometric Machine Webhook API Spec') }}
            </h3>
            <p class="text-xs text-muted-foreground mt-1 mb-2">
                {{ __('Configure this endpoint on your ZKTeco or middleware server to sync punches in real-time:') }}
            </p>

            <div class="p-2.5 rounded-lg bg-muted text-[11px] font-mono select-all overflow-x-auto text-foreground">
                POST {{ url('/api/v1/biometric/punch') }}<br>
                Headers: Authorization: Bearer &lt;API_TOKEN&gt;
            </div>

            <div class="mt-2 text-[11px] text-muted-foreground">
                JSON Body: <code>{"biometric_pin": "2026001", "punch_time": "2026-10-22 09:45:00", "punch_type": "check_in"}</code>
            </div>
        </div>
    </div>

    <!-- Raw Biometric Logs Table -->
    <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
        <div class="px-4 py-3 border-b border-border flex items-center justify-between">
            <h2 class="text-sm font-semibold text-foreground">
                {{ __('Raw Punch Ingest Audit Logs') }}
            </h2>
            <x-ui.button variant="outline" size="xs" wire:click="processPunches" class="cursor-pointer">
                <x-icon name="play" class="w-3.5 h-3.5 mr-1" />
                {{ __('Run Batch Processing') }}
            </x-ui.button>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs divide-y divide-border">
                <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                    <tr>
                        <th class="px-4 py-3">#</th>
                        <th class="px-4 py-3">{{ __('Device') }}</th>
                        <th class="px-4 py-3">{{ __('Punch Timestamp') }}</th>
                        <th class="px-4 py-3">{{ __('Identifier (PIN / Card)') }}</th>
                        <th class="px-4 py-3">{{ __('Matched System User') }}</th>
                        <th class="px-4 py-3">{{ __('Type') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Status') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border/60 font-mono text-[11px]">
                    @forelse($this->rawLogs as $log)
                        <tr class="hover:bg-muted/30 transition-colors">
                            <td class="px-4 py-3 text-muted-foreground">{{ $log->id }}</td>
                            <td class="px-4 py-3 font-sans">
                                <span class="font-medium text-foreground block">{{ $log->device?->device_name ?? __('Standalone') }}</span>
                            </td>
                            <td class="px-4 py-3 text-foreground">
                                {{ $log->punch_time ? $log->punch_time->format('Y-m-d H:i:s') : 'N/A' }}
                            </td>
                            <td class="px-4 py-3">
                                <span class="font-bold text-foreground">{{ $log->biometric_pin ?: ($log->card_number ?: 'N/A') }}</span>
                            </td>
                            <td class="px-4 py-3 font-sans">
                                @if($log->user)
                                    <span class="font-medium text-foreground block">{{ $log->user->name }}</span>
                                    <span class="text-[10px] text-muted-foreground">{{ $log->user->student_id ?? $log->user->employee_id }}</span>
                                @else
                                    <span class="text-rose-500 font-semibold">{{ __('Unmatched ID') }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 uppercase text-[10px]">
                                {{ $log->punch_type }}
                            </td>
                            <td class="px-4 py-3 text-right">
                                @if($log->is_processed)
                                    <span class="inline-flex items-center gap-1 text-emerald-600 dark:text-emerald-400 font-semibold text-[10px] bg-emerald-500/10 px-2 py-0.5 rounded-full border border-emerald-500/20">
                                        <x-icon name="check" class="w-3 h-3" />
                                        {{ __('Processed') }}
                                    </span>
                                @else
                                    <span class="inline-flex items-center gap-1 text-amber-600 dark:text-amber-400 font-semibold text-[10px] bg-amber-500/10 px-2 py-0.5 rounded-full border border-amber-500/20">
                                        {{ __('Pending Sync') }}
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-10 text-center text-muted-foreground font-sans">
                                {{ __('No raw biometric punch logs captured yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if($this->rawLogs->hasPages())
            <div class="px-4 py-3 border-t border-border">
                {{ $this->rawLogs->links() }}
            </div>
        @endif
    </div>

    <!-- Device Form Modal -->
    <x-ui.modal name="device-form-modal" :title="$isEditing ? __('Edit Biometric Machine') : __('Register Biometric Machine')">
        <form wire:submit="saveDevice" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Device Name') }} *</label>
                <input type="text" wire:model="deviceName" placeholder="{{ __('e.g. Main Gate Face Scanner') }}" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg" />
                @error('deviceName') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Serial Number') }} *</label>
                    <input type="text" wire:model="deviceSerialNumber" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                    @error('deviceSerialNumber') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Device Type') }} *</label>
                    <select wire:model="deviceType" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                        <option value="hybrid">{{ __('Hybrid (Fingerprint + Face + Card)') }}</option>
                        <option value="fingerprint">{{ __('Fingerprint Scanner') }}</option>
                        <option value="facial_recognition">{{ __('Facial Recognition Terminal') }}</option>
                        <option value="rfid_card">{{ __('RFID Smart Card Reader') }}</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('IP Address') }}</label>
                    <input type="text" wire:model="deviceIp" placeholder="192.168.1.200" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Port') }} *</label>
                    <input type="number" wire:model="devicePort" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Physical Location') }}</label>
                <input type="text" wire:model="deviceLocation" placeholder="{{ __('Academic Building Block A, Ground Floor') }}" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg" />
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Connection Status') }}</label>
                <select wire:model="deviceStatus" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                    <option value="online">{{ __('Online') }}</option>
                    <option value="offline">{{ __('Offline') }}</option>
                    <option value="maintenance">{{ __('Maintenance') }}</option>
                </select>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'device-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-primary text-primary-foreground font-semibold">
                    {{ __('Save Terminal') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Rotate Token Modal -->
    <x-ui.modal name="regenerate-token-modal" maxWidth="max-w-md">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-amber-500/10 text-amber-600 flex items-center justify-center shrink-0">
                    <x-icon name="key" class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Regenerate API Token?') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('Current biometric device push middleware will immediately require the new token to send punch logs.') }}
                    </p>
                </div>
            </div>

            <div class="pt-3 border-t border-border flex justify-end gap-2">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$dispatch('modal-close', { name: 'regenerate-token-modal' })"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="default"
                    size="sm"
                    wire:click="regenerateToken"
                    class="bg-amber-600 hover:bg-amber-700 text-white"
                >
                    {{ __('Regenerate Token') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>

    <!-- Delete Terminal Modal -->
    <x-ui.modal name="delete-device-modal" maxWidth="max-w-md">
        <div class="space-y-4">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center shrink-0">
                    <x-icon name="trash-2" class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Remove Biometric Terminal?') }}</h3>
                    <p class="text-xs text-muted-foreground mt-0.5">
                        {{ __('This removes the terminal definition from active monitoring. Existing attendance records will remain intact.') }}
                    </p>
                </div>
            </div>

            <div class="pt-3 border-t border-border flex justify-end gap-2">
                <x-ui.button
                    type="button"
                    variant="outline"
                    size="sm"
                    x-on:click="$dispatch('modal-close', { name: 'delete-device-modal' })"
                >
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button
                    type="button"
                    variant="destructive"
                    size="sm"
                    wire:click="deleteDevice"
                >
                    {{ __('Delete Terminal') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
