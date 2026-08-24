<?php

use App\Models\Ticket;
use App\Models\TicketCannedResponse;
use App\Models\TicketCategory;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\TicketService;
use App\Support\Toast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Ticket Desk Workspace')] class extends Component {
    use WithFileUploads;

    public $ticket;
    public string $activeTab = 'public'; // public, internal

    // Reply Box
    public string $replyMessage = '';
    public array $replyAttachments = [];
    public string $replyNewStatus = '';

    // Quick Attribute Controls
    public string $editStatus = '';
    public string $editPriority = '';
    public ?int $editAssigneeId = null;
    public ?int $editCategoryId = null;

    public function mount(string|Ticket $ticket): void
    {
        if ($ticket instanceof Ticket) {
            $this->ticket = $ticket;
        } else {
            $this->ticket = Ticket::with([
                'category',
                'assignedTo',
                'user.studentProfile.enrollments.batch.course',
                'attachments.uploader',
                'messages.user',
                'messages.attachments',
            ])
                ->where('ticket_number', $ticket)
                ->firstOrFail();
        }

        if (! Gate::allows('view', $this->ticket)) {
            abort(403, 'Unauthorized access.');
        }

        $this->editStatus = $this->ticket->status;
        $this->editPriority = $this->ticket->priority;
        $this->editAssigneeId = $this->ticket->assigned_to_user_id;
        $this->editCategoryId = $this->ticket->category_id;
    }

    public function insertCannedMacro(int $cannedId): void
    {
        $canned = TicketCannedResponse::find($cannedId);
        if ($canned) {
            $rendered = $canned->renderContent($this->ticket, auth()->user());
            $this->replyMessage = ! empty($this->replyMessage) ? $this->replyMessage."\n\n".$rendered : $rendered;
            Toast::dispatch($this, 'info', __("Macro ':name' applied.", ['name' => $canned->title]));
        }
    }

    public function postReply(): void
    {
        $this->validate([
            'replyMessage' => ['required', 'string', 'min:2'],
            'replyAttachments.*' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,zip,txt'],
        ]);

        /** @var TicketService $service */
        $service = app(TicketService::class);

        $type = $this->activeTab === 'internal' ? TicketMessage::TYPE_INTERNAL_NOTE : TicketMessage::TYPE_PUBLIC_REPLY;

        $service->replyTicket(
            ticket: $this->ticket,
            data: [
                'message' => $this->replyMessage,
                'type' => $type,
                'new_status' => ! empty($this->replyNewStatus) ? $this->replyNewStatus : null,
            ],
            sender: auth()->user(),
            uploadedFiles: $this->replyAttachments
        );

        $this->reset(['replyMessage', 'replyAttachments', 'replyNewStatus']);
        $this->ticket->refresh();
        $this->editStatus = $this->ticket->status;

        Toast::dispatch($this, 'success', $type === TicketMessage::TYPE_INTERNAL_NOTE ? __('Internal staff note saved.') : __('Public reply sent to customer.'));
    }

    public function updateStatus(string $status): void
    {
        /** @var TicketService $service */
        $service = app(TicketService::class);
        $service->updateStatus($this->ticket, $status, auth()->user());

        $this->ticket->refresh();
        $this->editStatus = $this->ticket->status;
        Toast::dispatch($this, 'success', __("Ticket status updated to ':status'.", ['status' => $this->ticket->statusLabel()]));
    }

    public function updatedEditPriority(string $val): void
    {
        /** @var TicketService $service */
        $service = app(TicketService::class);
        $service->updatePriority($this->ticket, $val, auth()->user());

        $this->ticket->refresh();
        Toast::dispatch($this, 'success', __('Priority updated.'));
    }

    public function updatedEditAssigneeId(?int $val): void
    {
        /** @var TicketService $service */
        $service = app(TicketService::class);
        $service->assignTicket($this->ticket, $val ? User::find($val) : null, auth()->user());

        $this->ticket->refresh();
        Toast::dispatch($this, 'success', __('Assignee updated.'));
    }

    public function updatedEditCategoryId(?int $val): void
    {
        $this->ticket->update(['category_id' => $val]);
        $this->ticket->refresh();
        Toast::dispatch($this, 'success', __('Category updated.'));
    }

    public function with(): array
    {
        $staffUsers = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Administrator', 'Administrator', 'Staff', 'Faculty', 'Admissions Officer']))->get();
        $categories = TicketCategory::active()->ordered()->get();
        $cannedResponses = TicketCannedResponse::active()->get();

        return [
            'staffUsers' => $staffUsers,
            'categories' => $categories,
            'cannedResponses' => $cannedResponses,
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Workspace Top Bar --}}
    <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-4 border-b border-border pb-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('admin.tickets.index') }}" class="text-xs text-muted-foreground hover:text-primary transition-colors flex items-center gap-1">
                    <x-icon name="arrow-left" class="h-3 w-3"/>
                    {{ __('Back to Helpdesk Desk') }}
                </a>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-bold tracking-tight text-foreground font-mono">
                    #{{ $ticket->ticket_number }}
                </h1>
                <x-ui.badge :color="$ticket->statusBadgeColor()" class="text-xs capitalize">
                    {{ $ticket->statusLabel() }}
                </x-ui.badge>
                <x-ui.badge :color="$ticket->priorityBadgeColor()" class="text-xs">
                    {{ $ticket->priorityLabel() }}
                </x-ui.badge>
                @if ($ticket->is_sla_response_breached || $ticket->is_sla_resolution_breached)
                    <x-ui.badge color="destructive" class="text-xs font-bold">
                        <x-icon name="alert-triangle" class="h-3 w-3 mr-1"/>
                        {{ __('SLA Breached') }}
                    </x-ui.badge>
                @endif
            </div>
            <h2 class="text-base font-semibold text-foreground mt-1">
                {{ $ticket->subject }}
            </h2>
        </div>

        {{-- Quick Workflow State Buttons --}}
        <div class="flex flex-wrap items-center gap-2">
            @if ($ticket->status !== 'in_progress')
                <x-ui.button variant="outline" size="sm" wire:click="updateStatus('in_progress')">
                    <x-icon name="play" class="h-3.5 w-3.5 mr-1 text-amber-500"/>
                    {{ __('In Progress') }}
                </x-ui.button>
            @endif

            @if ($ticket->status !== 'pending_user')
                <x-ui.button variant="outline" size="sm" wire:click="updateStatus('pending_user')">
                    <x-icon name="clock" class="h-3.5 w-3.5 mr-1 text-purple-500"/>
                    {{ __('Await Customer') }}
                </x-ui.button>
            @endif

            @if ($ticket->status !== 'resolved')
                <x-ui.button variant="default" size="sm" wire:click="updateStatus('resolved')">
                    <x-icon name="check-circle" class="h-3.5 w-3.5 mr-1 text-emerald-300"/>
                    {{ __('Resolve') }}
                </x-ui.button>
            @else
                <x-ui.button variant="outline" size="sm" wire:click="updateStatus('open')">
                    <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1"/>
                    {{ __('Reopen') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    {{-- Main Workspace Split Layout: Thread & Sidebar --}}
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 items-start">
        {{-- Left 2 Columns: Conversation Thread & Composer --}}
        <div class="lg:col-span-2 space-y-6">
            {{-- Thread Stream --}}
            <div class="space-y-4">
                @foreach ($ticket->messages as $msg)
                    @php
                        $isInternal = $msg->isInternalNote();
                        $isSystem = $msg->isSystemEvent();
                        $isStaff = $msg->user && ($msg->user->hasRole('Super Administrator') || $msg->user->hasRole('Administrator') || $msg->user->can('tickets.reply'));
                    @endphp

                    @if ($isSystem)
                        {{-- System Event --}}
                        <div class="flex items-center justify-center my-3">
                            <div class="px-3 py-1 rounded-full border border-border bg-secondary/40 text-[11px] text-muted-foreground flex items-center gap-1.5">
                                <x-icon name="info" class="h-3 w-3 text-primary"/>
                                <span>{{ $msg->message }}</span>
                                <span class="text-muted-foreground/60">&bull; {{ $msg->created_at->format('d M, h:i A') }}</span>
                            </div>
                        </div>
                    @elseif ($isInternal)
                        {{-- Internal Staff Note (Amber Highlighted) --}}
                        <div class="rounded-xl border border-amber-500/40 bg-amber-500/10 dark:bg-amber-950/20 p-4 sm:p-5 shadow-2xs space-y-2.5">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="flex h-7 w-7 items-center justify-center rounded-lg bg-amber-500 text-white font-bold text-xs">
                                        <x-icon name="lock" class="h-3.5 w-3.5"/>
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-foreground">{{ $msg->senderDisplayName() }}</span>
                                            <x-ui.badge color="amber" class="text-[9px] font-bold uppercase">{{ __('Internal Note (Staff Only)') }}</x-ui.badge>
                                        </div>
                                        <span class="text-[10px] text-muted-foreground">{{ $msg->created_at->format('d M Y, h:i A') }}</span>
                                    </div>
                                </div>
                            </div>

                            <div class="text-xs text-foreground leading-relaxed whitespace-pre-line pl-9">
                                {!! nl2br(e($msg->message)) !!}
                            </div>

                            @if ($msg->attachments->isNotEmpty())
                                <div class="pl-9 pt-2 flex flex-wrap gap-2">
                                    @foreach ($msg->attachments as $att)
                                        <a href="{{ Storage::disk($att->disk)->url($att->path) }}" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-amber-500/30 bg-background text-[11px] font-medium text-foreground hover:bg-secondary">
                                            <x-icon name="paperclip" class="h-3.5 w-3.5 text-amber-500"/>
                                            <span class="truncate max-w-[140px]">{{ $att->original_filename }}</span>
                                            <span class="text-[10px] text-muted-foreground">({{ $att->formattedSize() }})</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @else
                        {{-- Public Reply --}}
                        <div class="rounded-xl border {{ $isStaff ? 'border-primary/30 bg-primary/5' : 'border-border bg-card' }} p-4 sm:p-5 shadow-2xs space-y-2.5">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-2.5">
                                    <div class="flex h-7 w-7 items-center justify-center rounded-full {{ $isStaff ? 'bg-primary text-primary-foreground font-bold text-xs' : 'bg-secondary text-foreground font-semibold text-xs' }}">
                                        {{ substr($msg->senderDisplayName(), 0, 2) }}
                                    </div>
                                    <div>
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs font-bold text-foreground">{{ $msg->senderDisplayName() }}</span>
                                            @if ($isStaff)
                                                <x-ui.badge color="blue" class="text-[9px]">{{ __('Agent') }}</x-ui.badge>
                                            @else
                                                <x-ui.badge color="secondary" class="text-[9px]">{{ __('Customer') }}</x-ui.badge>
                                            @endif
                                        </div>
                                        <span class="text-[10px] text-muted-foreground">{{ $msg->created_at->format('d M Y, h:i A') }} ({{ $msg->created_at->diffForHumans() }})</span>
                                    </div>
                                </div>
                            </div>

                            <div class="text-xs text-foreground leading-relaxed whitespace-pre-line pl-9">
                                {!! nl2br(e($msg->message)) !!}
                            </div>

                            @if ($msg->attachments->isNotEmpty())
                                <div class="pl-9 pt-2 flex flex-wrap gap-2">
                                    @foreach ($msg->attachments as $att)
                                        <a href="{{ Storage::disk($att->disk)->url($att->path) }}" target="_blank" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-border bg-background text-[11px] font-medium text-foreground hover:bg-secondary">
                                            <x-icon name="{{ $att->isImage() ? 'image' : 'file-text' }}" class="h-3.5 w-3.5 text-primary"/>
                                            <span class="truncate max-w-[140px]">{{ $att->original_filename }}</span>
                                            <span class="text-[10px] text-muted-foreground">({{ $att->formattedSize() }})</span>
                                        </a>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                @endforeach
            </div>

            {{-- Master Reply Composer --}}
            <div class="rounded-xl border border-border bg-card shadow-2xs overflow-hidden">
                {{-- Tabs: Public Reply vs Internal Note --}}
                <div class="flex items-center justify-between border-b border-border bg-secondary/30 px-4 py-2">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            wire:click="$set('activeTab', 'public')"
                            class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors flex items-center gap-1.5 {{ $activeTab === 'public' ? 'bg-background text-primary shadow-2xs border border-border' : 'text-muted-foreground hover:text-foreground' }}"
                        >
                            <x-icon name="send" class="h-3 w-3"/>
                            {{ __('Public Reply (To User)') }}
                        </button>

                        <button
                            type="button"
                            wire:click="$set('activeTab', 'internal')"
                            class="px-3 py-1.5 text-xs font-semibold rounded-md transition-colors flex items-center gap-1.5 {{ $activeTab === 'internal' ? 'bg-amber-500/20 text-amber-900 dark:text-amber-200 border border-amber-500/30' : 'text-muted-foreground hover:text-foreground' }}"
                        >
                            <x-icon name="lock" class="h-3 w-3 text-amber-500"/>
                            {{ __('Internal Staff Note') }}
                        </button>
                    </div>

                    {{-- Canned Response Macro Picker --}}
                    @if ($cannedResponses->isNotEmpty())
                        <x-ui.dropdown width="w-64" align="end">
                            <x-slot:trigger>
                                <button type="button" class="inline-flex items-center gap-1 text-xs text-primary hover:underline cursor-pointer font-medium">
                                    <x-icon name="message-square-quote" class="h-3.5 w-3.5"/>
                                    {{ __('Insert Macro') }}
                                </button>
                            </x-slot:trigger>

                            @foreach ($cannedResponses as $cr)
                                <x-ui.dropdown.item wire:click="insertCannedMacro({{ $cr->id }})">
                                    <div class="text-xs font-medium text-foreground">{{ $cr->title }}</div>
                                    @if ($cr->shortcut)
                                        <div class="text-[10px] text-muted-foreground font-mono">{{ $cr->shortcut }}</div>
                                    @endif
                                </x-ui.dropdown.item>
                            @endforeach
                        </x-ui.dropdown>
                    @endif
                </div>

                {{-- Composer Form --}}
                <form wire:submit="postReply" class="p-4 space-y-3">
                    @if ($activeTab === 'internal')
                        <div class="p-2.5 rounded-lg border border-amber-500/30 bg-amber-500/10 text-amber-900 dark:text-amber-200 text-[11px] flex items-center gap-2">
                            <x-icon name="shield" class="h-4 w-4 shrink-0 text-amber-500"/>
                            <span>{{ __('Internal notes are strictly confidential and visible ONLY to support agents and administrators.') }}</span>
                        </div>
                    @endif

                    <textarea
                        wire:model="replyMessage"
                        rows="5"
                        placeholder="{{ $activeTab === 'internal' ? __('Write private internal note for other agents...') : __('Type your public response to the student/customer...') }}"
                        class="w-full px-3.5 py-2 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground placeholder:text-muted-foreground font-sans resize-y"
                    ></textarea>
                    @error('replyMessage') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror

                    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-3 pt-2">
                        <div class="flex items-center gap-2">
                            <input
                                type="file"
                                wire:model="replyAttachments"
                                multiple
                                accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.zip,.txt"
                                class="text-[11px] text-muted-foreground file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-medium file:bg-secondary file:text-foreground cursor-pointer"
                            />
                        </div>

                        <div class="flex items-center gap-2">
                            @if ($activeTab === 'public')
                                <select wire:model="replyNewStatus" class="py-1 px-2.5 text-xs bg-background rounded-lg border border-border text-foreground">
                                    <option value="">{{ __('Keep Current Status') }}</option>
                                    <option value="pending_user">{{ __('Set Awaiting Customer') }}</option>
                                    <option value="in_progress">{{ __('Set In Progress') }}</option>
                                    <option value="resolved">{{ __('Set Resolved') }}</option>
                                </select>
                            @endif

                            <x-ui.button :variant="$activeTab === 'internal' ? 'default' : 'default'" size="sm" type="submit">
                                <x-icon name="send" class="h-3.5 w-3.5 mr-1.5"/>
                                {{ $activeTab === 'internal' ? __('Save Internal Note') : __('Send Reply') }}
                            </x-ui.button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        {{-- Right Sidebar: Ticket Metadata & Customer Dossier --}}
        <div class="space-y-4">
            {{-- Card 1: Ticket Controls --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-4">
                <h3 class="text-xs font-bold text-foreground uppercase tracking-wider border-b border-border pb-2">
                    {{ __('Ticket Properties') }}
                </h3>

                {{-- Status Selector --}}
                <div class="space-y-1">
                    <label class="text-[11px] text-muted-foreground font-medium">{{ __('Status') }}</label>
                    <select wire:model.live="editStatus" wire:change="updateStatus($event.target.value)" class="w-full py-1.5 px-3 text-xs bg-background rounded-lg border border-border font-medium text-foreground">
                        <option value="open">{{ __('Open') }}</option>
                        <option value="in_progress">{{ __('In Progress') }}</option>
                        <option value="pending_user">{{ __('Awaiting Customer') }}</option>
                        <option value="on_hold">{{ __('On Hold') }}</option>
                        <option value="resolved">{{ __('Resolved') }}</option>
                        <option value="closed">{{ __('Closed') }}</option>
                    </select>
                </div>

                {{-- Priority Selector --}}
                <div class="space-y-1">
                    <label class="text-[11px] text-muted-foreground font-medium">{{ __('Priority') }}</label>
                    <select wire:model.live="editPriority" class="w-full py-1.5 px-3 text-xs bg-background rounded-lg border border-border font-medium text-foreground">
                        <option value="low">{{ __('Low') }}</option>
                        <option value="medium">{{ __('Medium') }}</option>
                        <option value="high">{{ __('High') }}</option>
                        <option value="urgent">{{ __('Urgent') }}</option>
                    </select>
                </div>

                {{-- Category Selector --}}
                <div class="space-y-1">
                    <label class="text-[11px] text-muted-foreground font-medium">{{ __('Category') }}</label>
                    <select wire:model.live="editCategoryId" class="w-full py-1.5 px-3 text-xs bg-background rounded-lg border border-border font-medium text-foreground">
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->id }}">{{ $cat->name }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Assignee Selector --}}
                <div class="space-y-1">
                    <label class="text-[11px] text-muted-foreground font-medium">{{ __('Assigned Staff Agent') }}</label>
                    <select wire:model.live="editAssigneeId" class="w-full py-1.5 px-3 text-xs bg-background rounded-lg border border-border font-medium text-foreground">
                        <option value="">{{ __('Unassigned') }}</option>
                        @foreach ($staffUsers as $staff)
                            <option value="{{ $staff->id }}">{{ $staff->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Card 2: SLA Timers & Deadlines --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-3">
                <h3 class="text-xs font-bold text-foreground uppercase tracking-wider border-b border-border pb-2 flex items-center justify-between">
                    <span>{{ __('SLA Targets') }}</span>
                    <x-icon name="clock" class="h-3.5 w-3.5 text-primary"/>
                </h3>

                {{-- First Response SLA --}}
                <div class="space-y-1 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('First Response Target:') }}</span>
                        @if ($ticket->first_responded_at)
                            <span class="text-emerald-600 dark:text-emerald-400 font-bold flex items-center gap-1">
                                <x-icon name="check" class="h-3 w-3"/>
                                {{ $ticket->first_responded_at->format('d M, h:i A') }}
                            </span>
                        @elseif ($ticket->first_response_due_at)
                            <span class="font-semibold {{ $ticket->is_sla_response_breached ? 'text-destructive font-bold' : 'text-foreground' }}">
                                {{ $ticket->first_response_due_at->diffForHumans() }}
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Resolution SLA --}}
                <div class="space-y-1 text-xs">
                    <div class="flex items-center justify-between">
                        <span class="text-muted-foreground">{{ __('Resolution Target:') }}</span>
                        @if ($ticket->resolved_at)
                            <span class="text-emerald-600 dark:text-emerald-400 font-bold flex items-center gap-1">
                                <x-icon name="check" class="h-3 w-3"/>
                                {{ $ticket->resolved_at->format('d M, h:i A') }}
                            </span>
                        @elseif ($ticket->resolution_due_at)
                            <span class="font-semibold {{ $ticket->is_sla_resolution_breached ? 'text-destructive font-bold' : 'text-foreground' }}">
                                {{ $ticket->resolution_due_at->diffForHumans() }}
                            </span>
                        @endif
                    </div>
                </div>
            </div>

            {{-- Card 3: Customer Dossier --}}
            <div class="rounded-xl border border-border bg-card p-4 shadow-2xs space-y-3">
                <h3 class="text-xs font-bold text-foreground uppercase tracking-wider border-b border-border pb-2">
                    {{ __('Customer Dossier') }}
                </h3>

                <div class="space-y-2 text-xs">
                    <div class="flex items-center gap-2.5">
                        <div class="flex h-9 w-9 items-center justify-center rounded-full bg-secondary font-bold text-foreground">
                            {{ substr($ticket->submitterName(), 0, 2) }}
                        </div>
                        <div>
                            <div class="font-semibold text-foreground">{{ $ticket->submitterName() }}</div>
                            <div class="text-[10px] text-muted-foreground font-mono">{{ $ticket->submitterEmail() }}</div>
                        </div>
                    </div>

                    @if ($ticket->guest_phone)
                        <div class="flex items-center gap-2 text-muted-foreground pt-1">
                            <x-icon name="phone" class="h-3.5 w-3.5 text-primary"/>
                            <span>{{ $ticket->guest_phone }}</span>
                        </div>
                    @endif

                    @if ($ticket->user)
                        <div class="pt-2 border-t border-border space-y-1">
                            <span class="text-[10px] uppercase font-semibold text-muted-foreground">{{ __('Account Status') }}</span>
                            <div class="text-[11px] text-foreground">
                                {{ __('Registered User') }} &bull; {{ $ticket->user->created_at->format('d M Y') }}
                            </div>
                        </div>
                    @endif
                </div>
            </div>

            {{-- Card 4: Satisfaction Score (if rated) --}}
            @if ($ticket->satisfaction_rating)
                <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 shadow-2xs space-y-2">
                    <h3 class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center justify-between">
                        <span>{{ __('CSAT Satisfaction') }}</span>
                        <span class="text-amber-600 dark:text-amber-400 font-bold">{{ $ticket->satisfaction_rating }} / 5 ★</span>
                    </h3>
                    @if ($ticket->satisfaction_feedback)
                        <p class="text-xs text-foreground italic">
                            "{{ $ticket->satisfaction_feedback }}"
                        </p>
                    @endif
                </div>
            @endif
        </div>
    </div>
</div>