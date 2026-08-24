<?php

use App\Models\CommunicationCampaign;
use App\Models\EmailTemplate;
use App\Models\Setting;
use App\Models\SmsTemplate;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\CommunicationService;
use App\Support\Toast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Compose & Bulk Messaging')] class extends Component {
    use WithPagination;

    #[Url(as: 'tab')]
    public string $activeTab = 'compose'; // 'compose', 'drafts', 'history'

    // Form fields
    public ?int $editingCampaignId = null;
    public string $title = '';
    public string $channel = 'email'; // 'email', 'sms', 'both'
    public string $recipientType = 'individual'; // 'individual', 'role', 'all_users', 'custom_list'
    public string $recipientRole = 'member';
    public array $selectedUserIds = [];
    public string $userSearch = '';
    public string $customRecipientsText = '';
    public string $selectedTemplateCode = '';
    public string $subject = '';
    public string $content = '';
    public string $editorMode = 'visual'; // 'visual', 'html'

    // Modals
    public bool $confirmSendModalOpen = false;

    public function updatedChannel(): void
    {
        $this->selectedTemplateCode = '';
    }

    public function updatedSelectedTemplateCode(string $code): void
    {
        if (empty($code)) {
            return;
        }

        if ($this->channel === 'email' || $this->channel === 'both') {
            $template = EmailTemplate::where('code', $code)->first();
            if ($template) {
                $this->subject = $template->subject;
                $this->content = $template->body;
            }
        } else {
            $template = SmsTemplate::where('code', $code)->first();
            if ($template) {
                $this->content = $template->body;
            }
        }
    }

    public function insertTag(string $tag): void
    {
        $this->content .= ' {'.$tag.'}';
    }

    public function removeSelectedUser(int $userId): void
    {
        $idStr = (string) $userId;
        $this->selectedUserIds = array_values(array_filter($this->selectedUserIds, fn ($id) => (string) $id !== $idStr));
    }

    public function clearSelectedUsers(): void
    {
        $this->selectedUserIds = [];
    }

    public function selectAllFoundUsers(): void
    {
        $query = User::query()
            ->when($this->userSearch !== '', function ($q) {
                $term = '%'.$this->userSearch.'%';
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            })
            ->limit(50)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->toArray();

        $this->selectedUserIds = array_values(array_unique(array_merge($this->selectedUserIds, $query)));
    }

    public function saveDraft(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:150'],
            'channel' => ['required', 'string', 'in:email,sms,both'],
            'recipientType' => ['required', 'string', 'in:individual,role,all_users,custom_list'],
            'content' => ['required', 'string'],
        ]);

        try {
            DB::transaction(function () {
                $recipientIds = match ($this->recipientType) {
                    'individual' => array_values(array_unique(array_map('intval', $this->selectedUserIds))),
                    'custom_list' => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $this->customRecipientsText)))),
                    default => null,
                };

                $payload = [
                    'title' => $this->title,
                    'channel' => $this->channel,
                    'recipient_type' => $this->recipientType,
                    'recipient_role' => $this->recipientType === 'role' ? $this->recipientRole : null,
                    'recipient_ids' => $recipientIds,
                    'template_code' => $this->selectedTemplateCode ?: null,
                    'subject' => $this->channel !== 'sms' ? $this->subject : null,
                    'content' => $this->content,
                    'status' => CommunicationCampaign::STATUS_DRAFT,
                    'updated_by' => auth()->id(),
                ];

                if ($this->editingCampaignId) {
                    $campaign = CommunicationCampaign::findOrFail($this->editingCampaignId);
                    $campaign->update($payload);
                    Toast::dispatch($this, 'success', __('Draft updated successfully.'));
                } else {
                    $payload['created_by'] = auth()->id();
                    $campaign = CommunicationCampaign::create($payload);
                    $this->editingCampaignId = $campaign->id;
                    Toast::dispatch($this, 'success', __('Draft saved successfully.'));
                }

                AuditLogService::log(
                    event: 'communication_draft_saved',
                    description: "Saved communication draft '{$campaign->title}'",
                    userId: auth()->id()
                );
            });
        } catch (\Throwable $e) {
            Log::error('Failed to save draft: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save draft: :error', ['error' => $e->getMessage()]));
        }
    }

    public function loadDraft(int $id): void
    {
        $campaign = CommunicationCampaign::findOrFail($id);
        $this->editingCampaignId = $campaign->id;
        $this->title = $campaign->title;
        $this->channel = $campaign->channel;
        $this->recipientType = $campaign->recipient_type;
        $this->recipientRole = $campaign->recipient_role ?? 'member';
        $this->selectedUserIds = array_map('strval', (array) ($campaign->recipient_ids ?? []));
        $this->customRecipientsText = $campaign->recipient_type === 'custom_list' ? implode("\n", (array) ($campaign->recipient_ids ?? [])) : '';
        $this->selectedTemplateCode = $campaign->template_code ?? '';
        $this->subject = $campaign->subject ?? '';
        $this->content = $campaign->content;

        $this->activeTab = 'compose';
        Toast::dispatch($this, 'info', __('Draft loaded into composer.'));
    }

    public function deleteDraft(int $id): void
    {
        try {
            $campaign = CommunicationCampaign::findOrFail($id);
            $campaign->delete();

            if ($this->editingCampaignId === $id) {
                $this->resetComposer();
            }

            Toast::dispatch($this, 'success', __('Draft deleted.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete draft.'));
        }
    }

    public function resetComposer(): void
    {
        $this->editingCampaignId = null;
        $this->title = '';
        $this->channel = 'email';
        $this->recipientType = 'individual';
        $this->recipientRole = 'member';
        $this->selectedUserIds = [];
        $this->customRecipientsText = '';
        $this->selectedTemplateCode = '';
        $this->subject = '';
        $this->content = '';
        $this->editorMode = 'visual';
    }

    public function openConfirmSendModal(): void
    {
        $rules = [
            'title' => ['required', 'string', 'max:150'],
            'channel' => ['required', 'string', 'in:email,sms,both'],
            'recipientType' => ['required', 'string', 'in:individual,role,all_users,custom_list'],
            'content' => ['required', 'string', 'min:3'],
        ];

        if ($this->channel === 'email' || $this->channel === 'both') {
            $rules['subject'] = ['required', 'string', 'max:255'];
        }

        if ($this->recipientType === 'individual' && empty($this->selectedUserIds)) {
            $this->addError('selectedUserIds', __('Please select at least one recipient user.'));

            return;
        }

        if ($this->recipientType === 'custom_list' && empty(trim($this->customRecipientsText))) {
            $this->addError('customRecipientsText', __('Please enter at least one recipient email or phone number.'));

            return;
        }

        $this->validate($rules);
        $this->confirmSendModalOpen = true;
    }

    public function sendNow(CommunicationService $communicationService): void
    {
        $this->confirmSendModalOpen = false;

        try {
            DB::transaction(function () use ($communicationService) {
                $recipientIds = match ($this->recipientType) {
                    'individual' => array_values(array_unique(array_map('intval', $this->selectedUserIds))),
                    'custom_list' => array_values(array_filter(array_map('trim', preg_split('/[\r\n,]+/', $this->customRecipientsText)))),
                    default => null,
                };

                $campaign = CommunicationCampaign::updateOrCreate(
                    ['id' => $this->editingCampaignId],
                    [
                        'title' => $this->title,
                        'channel' => $this->channel,
                        'recipient_type' => $this->recipientType,
                        'recipient_role' => $this->recipientType === 'role' ? $this->recipientRole : null,
                        'recipient_ids' => $recipientIds,
                        'template_code' => $this->selectedTemplateCode ?: null,
                        'subject' => $this->channel !== 'sms' ? $this->subject : null,
                        'content' => $this->content,
                        'created_by' => auth()->id(),
                        'updated_by' => auth()->id(),
                    ]
                );

                $result = $communicationService->dispatchCampaign($campaign, auth()->id());

                if ($result['success']) {
                    Toast::dispatch($this, 'success', $result['message']);
                    $this->resetComposer();
                    $this->activeTab = 'history';
                } else {
                    Toast::dispatch($this, 'error', $result['message']);
                }
            });
        } catch (\Throwable $e) {
            Log::error('Campaign dispatch error: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Dispatch failed: :error', ['error' => $e->getMessage()]));
        }
    }

    public function sendDraftNow(int $id, CommunicationService $communicationService): void
    {
        try {
            $campaign = CommunicationCampaign::findOrFail($id);
            $result = $communicationService->dispatchCampaign($campaign, auth()->id());

            if ($result['success']) {
                Toast::dispatch($this, 'success', $result['message']);
                $this->activeTab = 'history';
            } else {
                Toast::dispatch($this, 'error', $result['message']);
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to send draft: :error', ['error' => $e->getMessage()]));
        }
    }

    public function with(CommunicationService $communicationService): array
    {
        // Users for picker
        $usersQuery = User::query()
            ->when($this->userSearch !== '', function ($q) {
                $term = '%'.$this->userSearch.'%';
                $q->where('name', 'like', $term)
                    ->orWhere('email', 'like', $term)
                    ->orWhere('phone', 'like', $term);
            })
            ->limit(20)
            ->get();

        // Selected user objects for display pills
        $selectedUsers = ! empty($this->selectedUserIds)
            ? User::whereIn('id', $this->selectedUserIds)->get()
            : collect();

        // Templates available based on channel
        $emailTemplates = EmailTemplate::active()->get();
        $smsTemplates = SmsTemplate::active()->get();

        // Drafts
        $drafts = CommunicationCampaign::drafts()->latest('updated_at')->paginate(10, ['*'], 'draftsPage');

        // Broadcast History
        $history = CommunicationCampaign::active()->latest('sent_at')->paginate(10, ['*'], 'historyPage');

        // Sample Personalization Preview
        $previewVariables = [
            'name' => 'Jane Doe',
            'email' => 'jane.doe@example.com',
            'phone' => '+91 98765 43210',
            'app_name' => Setting::appName(),
            'date' => now()->format('d M Y'),
            'time' => now()->format('h:i A'),
        ];

        $previewSubject = $communicationService->personalizeString($this->subject ?: 'Sample Notification Subject', $previewVariables);
        $previewContent = $communicationService->personalizeString($this->content ?: 'Hello {name}, your announcement message will appear here.', $previewVariables);

        return [
            'users' => $usersQuery,
            'selectedUsers' => $selectedUsers,
            'emailTemplates' => $emailTemplates,
            'smsTemplates' => $smsTemplates,
            'drafts' => $drafts,
            'history' => $history,
            'previewSubject' => $previewSubject,
            'previewContent' => $previewContent,
            'appName' => Setting::appName(),
        ];
    }
}; ?>

<div class="space-y-6 max-w-7xl">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div class="flex items-center gap-3">
            <div class="flex h-11 w-11 items-center justify-center rounded-xl bg-primary/10 text-primary">
                <x-icon name="send" class="h-6 w-6"/>
            </div>
            <div>
                <h1 class="text-2xl font-bold tracking-tight">{{ __('Compose & Bulk Messaging') }}</h1>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Send personalised SMS and emails to individual users or bulk audiences with saved drafts and dynamic tag replacement.') }}</p>
            </div>
        </div>

        <div class="flex items-center gap-2.5">
            <a
                href="{{ route('admin.communications.logs') }}"
                wire:navigate
                class="inline-flex items-center gap-1.5 px-3.5 py-2 rounded-lg text-xs font-semibold border border-border hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
            >
                <x-icon name="activity" class="h-4 w-4"/>
                {{ __('View Delivery Logs') }}
            </a>
        </div>
    </div>

    {{-- Tabs Navigation --}}
    <div class="flex items-center border-b border-border gap-2">
        <button
            type="button"
            wire:click="$set('activeTab', 'compose')"
            class="px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'compose' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="edit-3" class="h-4 w-4"/>
            {{ $editingCampaignId ? __('Editing Draft') : __('Compose Message') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'drafts')"
            class="px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'drafts' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="file-text" class="h-4 w-4"/>
            {{ __('Saved Drafts') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'history')"
            class="px-4 py-2.5 text-xs font-semibold border-b-2 transition-colors cursor-pointer flex items-center gap-1.5 {{ $activeTab === 'history' ? 'border-primary text-primary' : 'border-transparent text-muted-foreground hover:text-foreground' }}"
        >
            <x-icon name="history" class="h-4 w-4"/>
            {{ __('Broadcast History') }}
        </button>
    </div>

    {{-- TAB 1: COMPOSE --}}
    @if ($activeTab === 'compose')
        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6">
            {{-- Left Column: Form Setup & Content (7 Cols) --}}
            <div class="lg:col-span-7 space-y-5">
                <div class="rounded-xl border border-border bg-card p-5 shadow-xs space-y-4">
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <h2 class="text-sm font-bold text-foreground">{{ __('Message Details & Audience') }}</h2>
                        @if ($editingCampaignId)
                            <button type="button" wire:click="resetComposer" class="text-xs text-muted-foreground hover:text-foreground cursor-pointer flex items-center gap-1">
                                <x-icon name="plus" class="h-3.5 w-3.5"/>
                                {{ __('Start New') }}
                            </button>
                        @endif
                    </div>

                    {{-- Campaign Title --}}
                    <div>
                        <x-ui.input
                            wire:model="title"
                            :label="__('Campaign / Message Title') . ' *'"
                            placeholder="{{ __('e.g., Weekly Mock Test Reminder or Admission Welcome') }}"
                            required
                        />
                    </div>

                    {{-- Channel Selector --}}
                    <div>
                        <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">
                            {{ __('Dispatch Channel') }} *
                        </label>
                        <div class="grid grid-cols-3 gap-2.5">
                            <label class="flex items-center gap-2.5 p-3 rounded-xl border cursor-pointer transition-colors {{ $channel === 'email' ? 'border-primary bg-primary/5 text-foreground' : 'border-border bg-transparent text-muted-foreground hover:bg-secondary/30' }}">
                                <input type="radio" wire:model.live="channel" value="email" class="text-primary focus:ring-primary h-4 w-4"/>
                                <div class="flex items-center gap-1.5 text-xs font-bold">
                                    <x-icon name="mail" class="h-4 w-4 text-violet-500"/>
                                    {{ __('Email') }}
                                </div>
                            </label>

                            <label class="flex items-center gap-2.5 p-3 rounded-xl border cursor-pointer transition-colors {{ $channel === 'sms' ? 'border-primary bg-primary/5 text-foreground' : 'border-border bg-transparent text-muted-foreground hover:bg-secondary/30' }}">
                                <input type="radio" wire:model.live="channel" value="sms" class="text-primary focus:ring-primary h-4 w-4"/>
                                <div class="flex items-center gap-1.5 text-xs font-bold">
                                    <x-icon name="smartphone" class="h-4 w-4 text-cyan-500"/>
                                    {{ __('SMS') }}
                                </div>
                            </label>

                            <label class="flex items-center gap-2.5 p-3 rounded-xl border cursor-pointer transition-colors {{ $channel === 'both' ? 'border-primary bg-primary/5 text-foreground' : 'border-border bg-transparent text-muted-foreground hover:bg-secondary/30' }}">
                                <input type="radio" wire:model.live="channel" value="both" class="text-primary focus:ring-primary h-4 w-4"/>
                                <div class="flex items-center gap-1.5 text-xs font-bold">
                                    <x-icon name="send" class="h-4 w-4 text-emerald-500"/>
                                    {{ __('Both') }}
                                </div>
                            </label>
                        </div>
                    </div>

                    {{-- Recipient Targeting --}}
                    <div>
                        <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-2">
                            {{ __('Target Audience') }} *
                        </label>
                        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 mb-3">
                            <button
                                type="button"
                                wire:click="$set('recipientType', 'individual')"
                                class="px-2.5 py-2 rounded-lg text-xs font-medium border cursor-pointer transition-colors text-center {{ $recipientType === 'individual' ? 'bg-primary text-primary-foreground font-semibold border-primary' : 'border-border text-muted-foreground hover:bg-secondary' }}"
                            >
                                {{ __('Individual') }}
                            </button>
                            <button
                                type="button"
                                wire:click="$set('recipientType', 'role')"
                                class="px-2.5 py-2 rounded-lg text-xs font-medium border cursor-pointer transition-colors text-center {{ $recipientType === 'role' ? 'bg-primary text-primary-foreground font-semibold border-primary' : 'border-border text-muted-foreground hover:bg-secondary' }}"
                            >
                                {{ __('By Role') }}
                            </button>
                            <button
                                type="button"
                                wire:click="$set('recipientType', 'all_users')"
                                class="px-2.5 py-2 rounded-lg text-xs font-medium border cursor-pointer transition-colors text-center {{ $recipientType === 'all_users' ? 'bg-primary text-primary-foreground font-semibold border-primary' : 'border-border text-muted-foreground hover:bg-secondary' }}"
                            >
                                {{ __('All Users') }}
                            </button>
                            <button
                                type="button"
                                wire:click="$set('recipientType', 'custom_list')"
                                class="px-2.5 py-2 rounded-lg text-xs font-medium border cursor-pointer transition-colors text-center {{ $recipientType === 'custom_list' ? 'bg-primary text-primary-foreground font-semibold border-primary' : 'border-border text-muted-foreground hover:bg-secondary' }}"
                            >
                                {{ __('Custom List') }}
                            </button>
                        </div>

                        {{-- Individual User Picker --}}
                        @if ($recipientType === 'individual')
                            <div class="space-y-3 p-3.5 rounded-xl bg-secondary/20 border border-border">
                                <div class="flex items-center justify-between gap-2">
                                    <div class="relative flex-1">
                                        <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-3.5 w-3.5 text-muted-foreground"/>
                                        <input
                                            type="text"
                                            wire:model.live.debounce.250ms="userSearch"
                                            placeholder="{{ __('Search users by name, email, phone...') }}"
                                            class="h-8 w-full rounded-md border border-input bg-transparent pl-8 pr-3 text-xs outline-none focus:border-ring"
                                        />
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="selectAllFoundUsers"
                                        class="px-2.5 py-1.5 text-[11px] font-medium rounded-md border border-border bg-card hover:bg-secondary text-muted-foreground hover:text-foreground cursor-pointer shrink-0"
                                    >
                                        {{ __('Select Visible') }}
                                    </button>
                                </div>

                                {{-- Selected User Tags/Pills --}}
                                @if ($selectedUsers->isNotEmpty())
                                    <div class="space-y-1.5 pt-1 border-t border-border/40">
                                        <div class="flex items-center justify-between text-[11px] font-semibold text-muted-foreground">
                                            <span>{{ __('Selected Recipients (:count)', ['count' => $selectedUsers->count()]) }}</span>
                                            <button type="button" wire:click="clearSelectedUsers" class="text-rose-500 hover:underline cursor-pointer font-normal">
                                                {{ __('Clear All') }}
                                            </button>
                                        </div>
                                        <div class="flex flex-wrap gap-1.5 max-h-24 overflow-y-auto pr-1">
                                            @foreach ($selectedUsers as $su)
                                                <span class="inline-flex items-center gap-1.5 px-2 py-0.5 rounded-md bg-primary/10 text-primary border border-primary/20 text-[11px] font-medium">
                                                    <span>{{ $su->name }}</span>
                                                    <button
                                                        type="button"
                                                        wire:click="removeSelectedUser({{ $su->id }})"
                                                        class="text-primary/70 hover:text-primary cursor-pointer"
                                                        title="{{ __('Remove') }}"
                                                    >
                                                        <x-icon name="x" class="h-3 w-3"/>
                                                    </button>
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                @endif

                                {{-- Search Results List --}}
                                <div class="max-h-44 overflow-y-auto space-y-1 divide-y divide-border/30 rounded-lg border border-border/60 bg-card p-1">
                                    @forelse ($users as $u)
                                        @php $isUserChecked = in_array((string) $u->id, $selectedUserIds); @endphp
                                        <label
                                            class="flex items-center justify-between p-2 rounded-md hover:bg-secondary cursor-pointer transition-colors {{ $isUserChecked ? 'bg-primary/5' : '' }}"
                                        >
                                            <div class="flex items-center gap-2.5 min-w-0 flex-1">
                                                <div class="h-7 w-7 rounded-full bg-secondary flex items-center justify-center text-[10px] font-bold text-foreground shrink-0 border border-border">
                                                    {{ $u->initials() }}
                                                </div>
                                                <div class="truncate text-xs min-w-0">
                                                    <div class="font-semibold text-foreground truncate">{{ $u->name }}</div>
                                                    <div class="text-[11px] text-muted-foreground truncate">{{ $u->email ?: $u->phone }}</div>
                                                </div>
                                            </div>
                                            <input
                                                type="checkbox"
                                                wire:model.live="selectedUserIds"
                                                value="{{ (string) $u->id }}"
                                                class="rounded border-input text-primary focus:ring-primary h-4 w-4 cursor-pointer shrink-0 ml-2"
                                            />
                                        </label>
                                    @empty
                                        <p class="text-xs text-muted-foreground text-center py-4">{{ __('No matching users found.') }}</p>
                                    @endforelse
                                </div>
                                @error('selectedUserIds') <span class="text-xs text-rose-500 block">{{ $message }}</span> @enderror
                            </div>
                        @elseif ($recipientType === 'role')
                            <div class="p-3.5 rounded-xl bg-secondary/20 border border-border space-y-2">
                                <label class="text-xs font-medium text-foreground">{{ __('Select Target Role') }}</label>
                                <select
                                    wire:model="recipientRole"
                                    class="h-9 w-full rounded-lg border border-input bg-transparent px-3 text-xs outline-none focus:border-ring"
                                >
                                    <option value="owner">{{ __('Owners & High Administrators') }}</option>
                                    <option value="admin">{{ __('Staff & Academic Administrators') }}</option>
                                    <option value="member">{{ __('Students & General Members') }}</option>
                                </select>
                            </div>
                        @elseif ($recipientType === 'custom_list')
                            <div class="p-3.5 rounded-xl bg-secondary/20 border border-border space-y-2">
                                <label class="text-xs font-medium text-foreground">{{ __('Enter Phone Numbers or Email Addresses (Comma or Newline separated)') }}</label>
                                <textarea
                                    wire:model="customRecipientsText"
                                    rows="3"
                                    placeholder="student1@gmail.com, +919876543210&#10;student2@gmail.com"
                                    class="w-full rounded-lg border border-input bg-transparent p-2.5 text-xs outline-none focus:border-ring font-mono"
                                ></textarea>
                                @error('customRecipientsText') <span class="text-xs text-rose-500 block">{{ $message }}</span> @enderror
                            </div>
                        @elseif ($recipientType === 'all_users')
                            <div class="p-3.5 rounded-xl bg-emerald-500/10 border border-emerald-500/20 text-emerald-700 dark:text-emerald-400 text-xs flex items-center gap-2">
                                <x-icon name="users" class="h-4 w-4 shrink-0"/>
                                <span>{{ __('This broadcast will be delivered to ALL registered users across the system.') }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Template Preset Loader --}}
                    <div>
                        <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">
                            {{ __('Pre-fill From Template (Optional)') }}
                        </label>
                        <select
                            wire:model.live="selectedTemplateCode"
                            class="h-9 w-full rounded-lg border border-input bg-transparent px-3 text-xs outline-none focus:border-ring text-muted-foreground"
                        >
                            <option value="">{{ __('-- Select Template to Pre-fill --') }}</option>
                            @if ($channel === 'email' || $channel === 'both')
                                <optgroup label="{{ __('Email Templates') }}">
                                    @foreach ($emailTemplates as $t)
                                        <option value="{{ $t->code }}">{{ $t->name }} ({{ $t->code }})</option>
                                    @endforeach
                                </optgroup>
                            @endif
                            @if ($channel === 'sms' || $channel === 'both')
                                <optgroup label="{{ __('SMS Templates') }}">
                                    @foreach ($smsTemplates as $t)
                                        <option value="{{ $t->code }}">{{ $t->name }} ({{ $t->code }})</option>
                                    @endforeach
                                </optgroup>
                            @endif
                        </select>
                    </div>

                    {{-- Email Subject line --}}
                    @if ($channel === 'email' || $channel === 'both')
                        <div>
                            <x-ui.input
                                wire:model.live="subject"
                                :label="__('Email Subject Line') . ' *'"
                                placeholder="{{ __('e.g., Important Notice: Upcoming Civil Services Mock Test') }}"
                                required
                            />
                        </div>
                    @endif

                    {{-- Personalisation Tag Toolbar --}}
                    <div>
                        <div class="flex items-center justify-between mb-1.5">
                            <label class="text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ __('Personalisation Tags') }}
                            </label>
                            <span class="text-[10px] text-muted-foreground">{{ __('Click to insert tag') }}</span>
                        </div>
                        <div class="flex flex-wrap gap-1.5">
                            @foreach (['name' => 'User Name', 'email' => 'User Email', 'phone' => 'Phone Number', 'app_name' => 'App Name', 'date' => 'Current Date', 'time' => 'Current Time'] as $tagKey => $tagDesc)
                                <button
                                    type="button"
                                    wire:click="insertTag('{{ $tagKey }}')"
                                    class="inline-flex items-center gap-1 px-2.5 py-1 rounded-md bg-secondary/80 hover:bg-secondary text-[11px] font-mono text-foreground border border-border cursor-pointer transition-colors"
                                    title="{{ $tagDesc }}"
                                >
                                    <x-icon name="plus" class="h-3 w-3 text-primary"/>
                                    {{ '{'.$tagKey.'}' }}
                                </button>
                            @endforeach
                        </div>
                    </div>

                    {{-- Content Body with Editor Mode Toggle --}}
                    <div class="space-y-1.5">
                        <div class="flex items-center justify-between">
                            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider">
                                {{ __('Message Body / Content') }} *
                            </label>

                            @if ($channel === 'email' || $channel === 'both')
                                <div class="flex items-center rounded-lg border border-border bg-secondary/30 p-0.5 text-[11px]">
                                    <button
                                        type="button"
                                        wire:click="$set('editorMode', 'visual')"
                                        class="px-2 py-0.5 rounded transition-colors cursor-pointer {{ $editorMode === 'visual' ? 'bg-background text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                                    >
                                        {{ __('Editor') }}
                                    </button>
                                    <button
                                        type="button"
                                        wire:click="$set('editorMode', 'html')"
                                        class="px-2 py-0.5 rounded transition-colors cursor-pointer {{ $editorMode === 'html' ? 'bg-background text-foreground font-semibold shadow-xs' : 'text-muted-foreground hover:text-foreground' }}"
                                    >
                                        {{ __('HTML Source') }}
                                    </button>
                                </div>
                            @endif
                        </div>

                        <textarea
                            wire:model.live.debounce.250ms="content"
                            rows="7"
                            placeholder="{{ __('Dear {name}, we would like to inform you that...') }}"
                            class="w-full rounded-lg border border-input bg-transparent p-3 text-xs outline-none focus:border-ring leading-relaxed {{ $editorMode === 'html' ? 'font-mono text-[11px]' : '' }}"
                            required
                        ></textarea>
                        @error('content') <span class="text-xs text-rose-500 block mt-1">{{ $message }}</span> @enderror

                        @if ($channel === 'sms' || $channel === 'both')
                            <div class="flex justify-between items-center text-[11px] text-muted-foreground pt-1">
                                <span>{{ __('Characters: :count', ['count' => strlen($content)]) }}</span>
                                <span>{{ __('Estimated SMS parts: :parts', ['parts' => max(1, ceil(strlen($content) / 160))]) }}</span>
                            </div>
                        @endif
                    </div>

                    {{-- Actions Bar --}}
                    <div class="flex items-center justify-between pt-4 border-t border-border">
                        <x-ui.button
                            type="button"
                            variant="outline"
                            wire:click="saveDraft"
                            wire:loading.attr="disabled"
                            wire:target="saveDraft"
                        >
                            <x-icon name="save" class="h-4 w-4 mr-1.5"/>
                            {{ __('Save as Draft') }}
                        </x-ui.button>

                        <x-ui.button
                            type="button"
                            wire:click="openConfirmSendModal"
                        >
                            <x-icon name="send" class="h-4 w-4 mr-1.5"/>
                            {{ __('Send Message Now') }}
                        </x-ui.button>
                    </div>
                </div>
            </div>

            {{-- Right Column: Live Personalised Preview (5 Cols) --}}
            <div class="lg:col-span-5 space-y-4">
                <div class="sticky top-20 rounded-xl border border-border bg-card p-5 shadow-xs space-y-4">
                    <div class="flex items-center justify-between border-b border-border pb-3">
                        <div class="flex items-center gap-2">
                            <x-icon name="eye" class="h-4 w-4 text-primary"/>
                            <h3 class="text-sm font-bold text-foreground">{{ __('Live Recipient Preview') }}</h3>
                        </div>
                        <span class="text-[10px] font-mono text-muted-foreground uppercase bg-secondary px-2 py-0.5 rounded">
                            {{ __('Sample Simulation') }}
                        </span>
                    </div>

                    <div class="p-3 rounded-lg bg-secondary/30 border border-border text-xs space-y-1">
                        <div class="text-muted-foreground">{{ __('Simulating personalization for recipient:') }}</div>
                        <div class="font-bold text-foreground">Jane Doe &bull; jane.doe@example.com &bull; +91 98765 43210</div>
                    </div>

                    {{-- Rendered Email Preview (Safely formatted without raw code clutter) --}}
                    @if ($channel === 'email' || $channel === 'both')
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Email Inbox Preview') }}</span>
                                <span class="text-[10px] text-violet-600 font-semibold">{{ __('Visual Output') }}</span>
                            </div>
                            <div class="rounded-xl border border-border bg-white dark:bg-slate-900 p-4 text-slate-900 dark:text-slate-100 text-xs shadow-inner space-y-3 overflow-x-auto max-h-96">
                                <div class="border-b border-slate-200 dark:border-slate-800 pb-2">
                                    <div class="font-bold text-sm text-slate-900 dark:text-white">{{ $previewSubject }}</div>
                                    <div class="text-[11px] text-slate-500 mt-0.5">From: {{ $appName }} &lt;noreply@sntcssc.in&gt;</div>
                                    <div class="text-[11px] text-slate-500">To: Jane Doe &lt;jane.doe@example.com&gt;</div>
                                </div>
                                <div class="prose prose-xs max-w-none text-slate-800 dark:text-slate-200">
                                    @if (str_contains($previewContent, '<') && str_contains($previewContent, '>'))
                                        {!! $previewContent !!}
                                    @else
                                        <div class="whitespace-pre-wrap leading-relaxed">
                                            {{ $previewContent }}
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endif

                    {{-- Rendered SMS Preview --}}
                    @if ($channel === 'sms' || $channel === 'both')
                        <div class="space-y-2">
                            <div class="flex items-center justify-between">
                                <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('SMS Preview (Phone View)') }}</span>
                                <span class="text-[10px] text-cyan-600 font-semibold">SNTCSS</span>
                            </div>
                            <div class="p-3.5 rounded-2xl bg-secondary/80 border border-border max-w-sm space-y-1.5 shadow-xs">
                                <div class="flex items-center justify-between text-[10px] text-muted-foreground font-mono">
                                    <span class="font-semibold text-foreground">SNTCSS</span>
                                    <span>{{ now()->format('h:i A') }}</span>
                                </div>
                                <p class="text-xs text-foreground font-mono leading-relaxed whitespace-pre-wrap">
                                    {{ strip_tags($previewContent) }}
                                </p>
                            </div>
                        </div>
                    @endif
                </div>
            </div>
        </div>
    @endif

    {{-- TAB 2: SAVED DRAFTS --}}
    @if ($activeTab === 'drafts')
        <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border bg-secondary/30 text-muted-foreground uppercase text-[10px] font-semibold tracking-wider">
                        <tr>
                            <th class="px-4 py-3">{{ __('Draft Title') }}</th>
                            <th class="px-4 py-3">{{ __('Channel') }}</th>
                            <th class="px-4 py-3">{{ __('Audience Target') }}</th>
                            <th class="px-4 py-3">{{ __('Content Snippet') }}</th>
                            <th class="px-4 py-3">{{ __('Last Modified') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($drafts as $draft)
                            <tr class="hover:bg-secondary/15 transition-colors">
                                <td class="px-4 py-3.5">
                                    <div class="font-bold text-foreground text-sm">{{ $draft->title }}</div>
                                    @if ($draft->subject)
                                        <div class="text-[11px] text-muted-foreground truncate max-w-xs">{{ $draft->subject }}</div>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-secondary text-foreground uppercase border border-border">
                                        {{ $draft->channel }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="font-medium text-muted-foreground capitalize">
                                        {{ str_replace('_', ' ', $draft->recipient_type) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 max-w-xs">
                                    <p class="line-clamp-2 text-muted-foreground text-[11px]">{{ strip_tags($draft->content) }}</p>
                                </td>
                                <td class="px-4 py-3.5 whitespace-nowrap text-muted-foreground text-[11px]">
                                    {{ $draft->updated_at->format('d M Y, h:i A') }}
                                </td>
                                <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                    <div class="flex items-center justify-end gap-1.5">
                                        <x-ui.button size="sm" variant="outline" wire:click="loadDraft({{ $draft->id }})">
                                            <x-icon name="edit-3" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Edit') }}
                                        </x-ui.button>
                                        <x-ui.button size="sm" wire:click="sendDraftNow({{ $draft->id }})" wire:confirm="{{ __('Send this draft immediately?') }}">
                                            <x-icon name="send" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Send Now') }}
                                        </x-ui.button>
                                        <button
                                            type="button"
                                            wire:click="deleteDraft({{ $draft->id }})"
                                            wire:confirm="{{ __('Delete this draft?') }}"
                                            class="p-1.5 rounded-md hover:bg-rose-500/10 text-muted-foreground hover:text-rose-600 transition-colors cursor-pointer"
                                            title="{{ __('Delete Draft') }}"
                                        >
                                            <x-icon name="trash-2" class="h-4 w-4"/>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="text-center py-10 text-muted-foreground">
                                    <x-icon name="file-text" class="h-8 w-8 mx-auto text-muted-foreground/40 mb-2"/>
                                    <p class="text-sm font-medium">{{ __('No saved drafts.') }}</p>
                                    <p class="text-xs text-muted-foreground/80 mt-1">{{ __('Compose a message and click "Save as Draft" to store it here.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($drafts->hasPages())
                <div class="p-4 border-t border-border">
                    {{ $drafts->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- TAB 3: BROADCAST HISTORY --}}
    @if ($activeTab === 'history')
        <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="border-b border-border bg-secondary/30 text-muted-foreground uppercase text-[10px] font-semibold tracking-wider">
                        <tr>
                            <th class="px-4 py-3">{{ __('Campaign Title') }}</th>
                            <th class="px-4 py-3">{{ __('Channel') }}</th>
                            <th class="px-4 py-3">{{ __('Audience') }}</th>
                            <th class="px-4 py-3 text-center">{{ __('Recipients & Delivery') }}</th>
                            <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                            <th class="px-4 py-3">{{ __('Sent Date') }}</th>
                            <th class="px-4 py-3 text-right">{{ __('Logs') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        @forelse ($history as $campaign)
                            <tr class="hover:bg-secondary/15 transition-colors">
                                <td class="px-4 py-3.5">
                                    <div class="font-bold text-foreground text-sm">{{ $campaign->title }}</div>
                                    <div class="text-[11px] text-muted-foreground truncate max-w-xs">{{ $campaign->subject ?: strip_tags($campaign->content) }}</div>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[10px] font-semibold bg-secondary text-foreground uppercase border border-border">
                                        {{ $campaign->channel }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5">
                                    <span class="font-medium text-muted-foreground capitalize">
                                        {{ str_replace('_', ' ', $campaign->recipient_type) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3.5 text-center">
                                    <div class="inline-flex items-center gap-1.5 text-xs font-semibold">
                                        <span class="text-emerald-600">{{ $campaign->sent_count }} {{ __('sent') }}</span>
                                        @if ($campaign->failed_count > 0)
                                            <span class="text-rose-500">/ {{ $campaign->failed_count }} {{ __('failed') }}</span>
                                        @endif
                                    </div>
                                    <div class="text-[10px] text-muted-foreground">
                                        {{ __('Total: :total', ['total' => $campaign->total_recipients]) }}
                                    </div>
                                </td>
                                <td class="px-4 py-3.5 text-center">
                                    @if ($campaign->status === 'completed')
                                        <x-ui.badge color="emerald">{{ __('Completed') }}</x-ui.badge>
                                    @elseif ($campaign->status === 'failed')
                                        <x-ui.badge color="destructive">{{ __('Failed') }}</x-ui.badge>
                                    @else
                                        <x-ui.badge color="amber">{{ ucfirst($campaign->status) }}</x-ui.badge>
                                    @endif
                                </td>
                                <td class="px-4 py-3.5 whitespace-nowrap text-muted-foreground text-[11px]">
                                    {{ $campaign->sent_at?->format('d M Y, h:i A') ?? '—' }}
                                </td>
                                <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                    <a
                                        href="{{ route('admin.communications.logs', ['search' => $campaign->title]) }}"
                                        wire:navigate
                                        class="inline-flex items-center gap-1 px-2.5 py-1.5 rounded-lg border border-border hover:bg-secondary text-muted-foreground hover:text-foreground text-xs transition-colors cursor-pointer"
                                    >
                                        <x-icon name="activity" class="h-3.5 w-3.5"/>
                                        {{ __('View Logs') }}
                                    </a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="text-center py-10 text-muted-foreground">
                                    <x-icon name="history" class="h-8 w-8 mx-auto text-muted-foreground/40 mb-2"/>
                                    <p class="text-sm font-medium">{{ __('No broadcast history.') }}</p>
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            @if ($history->hasPages())
                <div class="p-4 border-t border-border">
                    {{ $history->links() }}
                </div>
            @endif
        </div>
    @endif

    {{-- Confirmation Modal Before Dispatch --}}
    @if ($confirmSendModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-md bg-card border border-border rounded-2xl shadow-xl p-6 space-y-4 animate-in fade-in zoom-in-95">
                <div class="flex items-center gap-3">
                    <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <x-icon name="send" class="h-5 w-5"/>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-foreground">{{ __('Confirm Dispatch') }}</h3>
                        <p class="text-xs text-muted-foreground">{{ __('Are you ready to send this message?') }}</p>
                    </div>
                </div>

                <div class="p-3.5 rounded-xl bg-secondary/30 border border-border text-xs space-y-2">
                    <div><strong>{{ __('Campaign:') }}</strong> {{ $title }}</div>
                    <div><strong>{{ __('Channel:') }}</strong> {{ strtoupper($channel) }}</div>
                    <div><strong>{{ __('Target:') }}</strong> {{ ucfirst(str_replace('_', ' ', $recipientType)) }}</div>
                    <div class="text-muted-foreground pt-1 border-t border-border/50">
                        {{ __('Personalised placeholders such as {name} will be dynamically injected for each recipient.') }}
                    </div>
                </div>

                <div class="flex items-center justify-end gap-2.5 pt-2">
                    <x-ui.button type="button" variant="outline" wire:click="$set('confirmSendModalOpen', false)">
                        {{ __('Cancel') }}
                    </x-ui.button>
                    <x-ui.button type="button" wire:click="sendNow" wire:loading.attr="disabled" wire:target="sendNow">
                        <x-icon name="send" class="h-4 w-4 mr-1.5" wire:loading.remove wire:target="sendNow"/>
                        <x-icon name="refresh-cw" class="h-4 w-4 mr-1.5 animate-spin" wire:loading wire:target="sendNow"/>
                        {{ __('Yes, Send Now') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif
</div>
