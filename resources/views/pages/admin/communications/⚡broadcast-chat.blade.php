<?php

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\ChatService;
use App\Support\Toast;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Live Chat Broadcast Hub')] class extends Component {
    use WithPagination;

    // Filters
    public string $search = '';
    public string $roleFilter = 'all';
    public string $statusFilter = 'all';

    // Selection
    public array $selectedUserIds = [];
    public bool $selectAll = false;

    // Broadcast Composition
    public string $messageBody = '';
    public bool $sendInAppNotification = true;
    public bool $sendExternalAlerts = true;

    // Dispatch Results
    public bool $isDispatched = false;
    public int $sentCount = 0;
    public int $failedCount = 0;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedRoleFilter(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function toggleSelectAll(): void
    {
        if ($this->selectAll) {
            $ids = $this->getFilteredUsersQuery()->pluck('id')->map(fn ($id) => (string) $id)->toArray();
            $this->selectedUserIds = $ids;
        } else {
            $this->selectedUserIds = [];
        }
    }

    public function selectAllFiltered(): void
    {
        $ids = $this->getFilteredUsersQuery()->pluck('id')->map(fn ($id) => (string) $id)->toArray();
        $this->selectedUserIds = array_unique(array_merge($this->selectedUserIds, $ids));
        $this->selectAll = true;
        Toast::dispatch($this, 'info', __('Selected :count users.', ['count' => count($ids)]));
    }

    public function clearSelection(): void
    {
        $this->selectedUserIds = [];
        $this->selectAll = false;
    }

    public function insertPlaceholder(string $tag): void
    {
        $this->messageBody .= " {$tag}";
    }

    protected function getFilteredUsersQuery()
    {
        $currentUserId = auth()->id();

        $query = User::query()
            ->where('id', '!=', $currentUserId)
            ->whereNull('deleted_at');

        if (! empty(trim($this->search))) {
            $search = trim($this->search);
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($this->roleFilter !== 'all') {
            $query->whereHas('roles', fn ($q) => $q->where('name', $this->roleFilter));
        }

        if ($this->statusFilter !== 'all') {
            $isActive = $this->statusFilter === 'active';
            $query->where('status', $isActive);
        }

        return $query;
    }

    public function dispatchBroadcast(): void
    {
        $this->validate([
            'messageBody' => ['required', 'string', 'min:2', 'max:5000'],
            'selectedUserIds' => ['required', 'array', 'min:1'],
        ], [
            'selectedUserIds.min' => __('Please select at least one recipient user.'),
            'messageBody.required' => __('Please compose a broadcast message.'),
        ]);

        $currentUser = auth()->user();
        /** @var ChatService $chatService */
        $chatService = app(ChatService::class);

        $targetUsers = User::whereIn('id', $this->selectedUserIds)->get();

        $result = $chatService->sendBroadcastMessage(
            admin: $currentUser,
            targetUsers: $targetUsers,
            bodyTemplate: $this->messageBody
        );

        $this->sentCount = $result['sent_count'];
        $this->failedCount = $result['failed_count'];
        $this->isDispatched = true;

        Toast::dispatch($this, 'success', __('Personalized broadcast successfully delivered to :count users!', ['count' => $this->sentCount]));
    }

    public function resetComposer(): void
    {
        $this->messageBody = '';
        $this->selectedUserIds = [];
        $this->selectAll = false;
        $this->isDispatched = false;
        $this->sentCount = 0;
        $this->failedCount = 0;
    }

    public function with(): array
    {
        $users = $this->getFilteredUsersQuery()
            ->with(['roles'])
            ->orderBy('name')
            ->paginate(12);

        $roles = Role::orderBy('name')->get();
        $sampleUser = ! empty($this->selectedUserIds)
            ? User::find($this->selectedUserIds[0])
            : $users->first();

        $samplePreview = '';
        if ($sampleUser && ! empty($this->messageBody)) {
            $samplePreview = str_replace(
                ['{name}', '{first_name}', '{email}', '{phone}', '{role}'],
                [
                    $sampleUser->name,
                    explode(' ', $sampleUser->name)[0] ?? $sampleUser->name,
                    $sampleUser->email,
                    $sampleUser->phone ?? '+91 90000 00000',
                    $sampleUser->roles->first()?->name ?? 'Student',
                ],
                $this->messageBody
            );
        }

        return [
            'users' => $users,
            'roles' => $roles,
            'sampleUser' => $sampleUser,
            'samplePreview' => $samplePreview,
            'totalMatching' => $this->getFilteredUsersQuery()->count(),
        ];
    }
};
?>

<div class="space-y-6 max-w-7xl mx-auto pb-16">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-border pb-4">
        <div>
            <div class="flex items-center gap-2 text-xs text-muted-foreground mb-1">
                <a href="{{ route('dashboard') }}" wire:navigate class="hover:text-foreground">{{ __('Dashboard') }}</a>
                <x-icon name="chevron-right" class="h-3 w-3" />
                <a href="{{ route('admin.chat.index') }}" wire:navigate class="hover:text-foreground">{{ __('Live Chat') }}</a>
                <x-icon name="chevron-right" class="h-3 w-3" />
                <span class="text-foreground font-medium">{{ __('Bulk Broadcast Hub') }}</span>
            </div>
            <h1 class="text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <div class="p-2 rounded-lg bg-emerald-500/10 text-emerald-500">
                    <x-icon name="send" class="h-6 w-6" />
                </div>
                <span>{{ __('Live Chat Dedicated Broadcast Hub') }}</span>
            </h1>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Select specific user cohorts, customize personalized messaging variables, and dispatch simultaneous direct live chat messages at enterprise scale.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('admin.chat.index') }}" wire:navigate>
                <x-ui.button variant="secondary" icon="message-square">
                    {{ __('Open Live Chat Portal') }}
                </x-ui.button>
            </a>
        </div>
    </div>

    @if ($isDispatched)
        <!-- Success Banner -->
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-5 text-emerald-800 dark:text-emerald-300 space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="h-10 w-10 rounded-full bg-emerald-500 text-white flex items-center justify-center font-bold">
                        <x-icon name="check" class="h-5 w-5" />
                    </div>
                    <div>
                        <h3 class="font-bold text-base">{{ __('Personalized Broadcast Dispatched Successfully!') }}</h3>
                        <p class="text-xs opacity-90">{{ __('Sent :sent messages (:failed failed). Messages are now delivered to users in their live chat windows.', ['sent' => $sentCount, 'failed' => $failedCount]) }}</p>
                    </div>
                </div>
                <x-ui.button wire:click="resetComposer" variant="default" size="sm" icon="plus">
                    {{ __('Send Another Broadcast') }}
                </x-ui.button>
            </div>
        </div>
    @endif

    <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
        <!-- Left Panel: Recipient Selection & Multi-Filter Matrix -->
        <div class="lg:col-span-7 space-y-4">
            <div class="rounded-xl border border-border bg-card p-4 sm:p-5 shadow-xs space-y-4">
                <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 border-b border-border pb-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="users" class="h-4 w-4 text-primary" />
                        <h2 class="text-sm font-semibold text-foreground">{{ __('Select Target Recipients') }}</h2>
                        <span class="px-2 py-0.5 rounded-full text-xs font-mono bg-primary/10 text-primary">
                            {{ count($selectedUserIds) }} {{ __('Selected') }}
                        </span>
                    </div>

                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="selectAllFiltered"
                            class="text-xs font-medium text-primary hover:underline cursor-pointer"
                        >
                            {{ __('Select All (:total)', ['total' => $totalMatching]) }}
                        </button>
                        <span class="text-muted-foreground">|</span>
                        <button
                            type="button"
                            wire:click="clearSelection"
                            class="text-xs font-medium text-muted-foreground hover:text-foreground cursor-pointer"
                        >
                            {{ __('Clear') }}
                        </button>
                    </div>
                </div>

                <!-- Search & Filters -->
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2.5">
                    <div class="sm:col-span-1">
                        <x-ui.input
                            wire:model.live.debounce.300ms="search"
                            placeholder="{{ __('Search user…') }}"
                            icon="search"
                        />
                    </div>
                    <div>
                        <select wire:model.live="roleFilter" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs focus:border-primary focus:ring-1 focus:ring-primary">
                            <option value="all">{{ __('All Roles') }}</option>
                            @foreach ($roles as $role)
                                <option value="{{ $role->name }}">{{ $role->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <select wire:model.live="statusFilter" class="w-full rounded-lg border border-border bg-background px-3 py-2 text-xs focus:border-primary focus:ring-1 focus:ring-primary">
                            <option value="all">{{ __('All Statuses') }}</option>
                            <option value="active">{{ __('Active Users') }}</option>
                            <option value="inactive">{{ __('Inactive Users') }}</option>
                        </select>
                    </div>
                </div>

                <!-- Users Grid -->
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-2.5 max-h-[460px] overflow-y-auto pr-1 scrollbar-thin scrollbar-thumb-border">
                    @forelse ($users as $user)
                        @php
                            $isSelected = in_array((string) $user->id, $selectedUserIds, true);
                        @endphp
                        <label
                            class="flex items-center gap-3 p-2.5 rounded-lg border transition-all cursor-pointer select-none {{ $isSelected ? 'border-primary bg-primary/5 ring-1 ring-primary' : 'border-border bg-background hover:bg-secondary/30' }}"
                        >
                            <input
                                type="checkbox"
                                wire:model.live="selectedUserIds"
                                value="{{ (string) $user->id }}"
                                class="rounded border-border text-primary focus:ring-primary h-4 w-4"
                            />
                            <x-ui.avatar :name="$user->name" :initials="$user->initials()" :src="$user->avatarUrl()" size="size-8 text-xs" />
                            <div class="min-w-0 flex-1">
                                <p class="text-xs font-semibold text-foreground truncate">{{ $user->name }}</p>
                                <p class="text-[11px] text-muted-foreground truncate">{{ $user->email }}</p>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    <span class="text-[10px] px-1.5 py-0.2 rounded bg-secondary text-secondary-foreground font-medium">
                                        {{ $user->roles->first()?->name ?? 'User' }}
                                    </span>
                                    @if ($user->phone)
                                        <span class="text-[10px] text-muted-foreground">{{ $user->phone }}</span>
                                    @endif
                                </div>
                            </div>
                        </label>
                    @empty
                        <div class="col-span-2 py-8 text-center text-muted-foreground text-xs">
                            <x-icon name="user-x" class="h-6 w-6 mx-auto mb-1 opacity-50" />
                            {{ __('No users found matching your filters.') }}
                        </div>
                    @endforelse
                </div>

                <div class="pt-2 border-t border-border">
                    {{ $users->links() }}
                </div>
            </div>
        </div>

        <!-- Right Panel: Message Composer & Personalization Tags -->
        <div class="lg:col-span-5 space-y-4">
            <div class="rounded-xl border border-border bg-card p-4 sm:p-5 shadow-xs space-y-4">
                <div class="flex items-center justify-between border-b border-border pb-3">
                    <div class="flex items-center gap-2">
                        <x-icon name="edit-3" class="h-4 w-4 text-primary" />
                        <h2 class="text-sm font-semibold text-foreground">{{ __('Compose Personalized Message') }}</h2>
                    </div>
                </div>

                <!-- Personalization Chips -->
                <div class="space-y-1.5">
                    <label class="block text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                        {{ __('Click to Insert Dynamic Placeholder:') }}
                    </label>
                    <div class="flex flex-wrap gap-1.5">
                        <button type="button" wire:click="insertPlaceholder('{name}')" class="px-2 py-1 rounded bg-secondary hover:bg-primary/10 hover:text-primary text-[11px] font-mono transition-colors cursor-pointer">
                            {name}
                        </button>
                        <button type="button" wire:click="insertPlaceholder('{first_name}')" class="px-2 py-1 rounded bg-secondary hover:bg-primary/10 hover:text-primary text-[11px] font-mono transition-colors cursor-pointer">
                            {first_name}
                        </button>
                        <button type="button" wire:click="insertPlaceholder('{email}')" class="px-2 py-1 rounded bg-secondary hover:bg-primary/10 hover:text-primary text-[11px] font-mono transition-colors cursor-pointer">
                            {email}
                        </button>
                        <button type="button" wire:click="insertPlaceholder('{phone}')" class="px-2 py-1 rounded bg-secondary hover:bg-primary/10 hover:text-primary text-[11px] font-mono transition-colors cursor-pointer">
                            {phone}
                        </button>
                        <button type="button" wire:click="insertPlaceholder('{role}')" class="px-2 py-1 rounded bg-secondary hover:bg-primary/10 hover:text-primary text-[11px] font-mono transition-colors cursor-pointer">
                            {role}
                        </button>
                    </div>
                </div>

                <!-- Message Body -->
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">
                        {{ __('Broadcast Body') }}
                    </label>
                    <textarea
                        wire:model.live.debounce.150ms="messageBody"
                        rows="7"
                        placeholder="{{ __('Hello {first_name}, this is an important announcement regarding...') }}"
                        class="w-full rounded-lg border border-border bg-background p-3 text-sm focus:border-primary focus:ring-1 focus:ring-primary leading-relaxed"
                    ></textarea>
                    @error('messageBody')
                        <p class="text-xs text-rose-500 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <!-- Live Dynamic Preview -->
                @if ($samplePreview && $sampleUser)
                    <div class="rounded-lg border border-border bg-secondary/30 p-3 space-y-1.5">
                        <div class="flex items-center justify-between text-[11px] text-muted-foreground">
                            <span class="font-semibold text-foreground flex items-center gap-1">
                                <x-icon name="eye" class="h-3.5 w-3.5 text-primary" />
                                {{ __('Sample Preview for :name', ['name' => $sampleUser->name]) }}
                            </span>
                            <span class="text-[10px]">{{ __('Live Render') }}</span>
                        </div>
                        <div class="p-2.5 rounded-md bg-background border border-border text-xs text-foreground whitespace-pre-wrap font-sans">
                            {{ $samplePreview }}
                        </div>
                    </div>
                @endif

                <!-- Dispatch Button -->
                <div class="pt-2">
                    <x-ui.button
                        wire:click="dispatchBroadcast"
                        variant="default"
                        icon="send"
                        class="w-full justify-center py-2.5"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove>
                            {{ __('Dispatch Broadcast to :count Users', ['count' => count($selectedUserIds)]) }}
                        </span>
                        <span wire:loading class="flex items-center gap-2">
                            <x-icon name="refresh-cw" class="h-4 w-4 animate-spin" />
                            {{ __('Dispatching to recipients…') }}
                        </span>
                    </x-ui.button>
                </div>
            </div>
        </div>
    </div>
</div>
