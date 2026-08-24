<?php

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Services\TicketService;
use App\Support\Toast;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Ticket Conversation')] class extends Component {
    use WithFileUploads;

    public $ticket;
    public string $replyMessage = '';
    public array $replyAttachments = [];

    // Satisfaction Rating
    public int $rating = 5;
    public string $feedback = '';

    public function mount(string|Ticket $ticket): void
    {
        if ($ticket instanceof Ticket) {
            $this->ticket = $ticket;
        } else {
            $this->ticket = Ticket::with(['category', 'assignedTo', 'attachments', 'messages.user', 'messages.attachments'])
                ->where('ticket_number', $ticket)
                ->firstOrFail();
        }

        // Ensure user can only view their own ticket
        if (! Gate::allows('view', $this->ticket)) {
            abort(403, 'Unauthorized access to support ticket.');
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

        $service->replyTicket(
            ticket: $this->ticket,
            data: [
                'message' => $this->replyMessage,
                'type' => TicketMessage::TYPE_PUBLIC_REPLY,
            ],
            sender: auth()->user(),
            uploadedFiles: $this->replyAttachments
        );

        $this->reset(['replyMessage', 'replyAttachments']);
        $this->ticket->refresh();

        Toast::dispatch($this, 'success', __('Your reply has been posted successfully.'));
    }

    public function markAsResolved(): void
    {
        /** @var TicketService $service */
        $service = app(TicketService::class);
        $service->updateStatus($this->ticket, Ticket::STATUS_RESOLVED, auth()->user(), 'Marked as resolved by student/user.');

        $this->ticket->refresh();
        Toast::dispatch($this, 'success', __('Ticket marked as resolved. Please share your satisfaction rating below.'));
    }

    public function reopenTicket(): void
    {
        /** @var TicketService $service */
        $service = app(TicketService::class);
        $service->updateStatus($this->ticket, Ticket::STATUS_OPEN, auth()->user(), 'Re-opened by student/user.');

        $this->ticket->refresh();
        Toast::dispatch($this, 'success', __('Ticket has been re-opened.'));
    }

    public function submitRating(): void
    {
        $this->validate([
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'feedback' => ['nullable', 'string', 'max:1000'],
        ]);

        /** @var TicketService $service */
        $service = app(TicketService::class);
        $service->rateTicket($this->ticket, $this->rating, $this->feedback, auth()->user());

        $this->ticket->refresh();
        Toast::dispatch($this, 'success', __('Thank you for your valuable feedback!'));
    }

    public function with(): array
    {
        // For user portal, hide internal staff notes
        $publicMessages = $this->ticket->messages()
            ->with(['user', 'attachments'])
            ->where('type', '!=', TicketMessage::TYPE_INTERNAL_NOTE)
            ->get();

        return [
            'publicMessages' => $publicMessages,
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-6">
    {{-- Top Back Nav & Quick Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
        <div>
            <a href="{{ route('tickets.index') }}" class="text-xs text-muted-foreground hover:text-primary transition-colors flex items-center gap-1 mb-1">
                <x-icon name="arrow-left" class="h-3 w-3"/>
                {{ __('Back to My Tickets') }}
            </a>
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
                @if ($ticket->category)
                    <x-ui.badge :color="$ticket->category->color_badge" class="text-xs">
                        {{ $ticket->category->name }}
                    </x-ui.badge>
                @endif
            </div>
            <h2 class="text-base font-semibold text-foreground mt-1">
                {{ $ticket->subject }}
            </h2>
        </div>

        <div class="flex items-center gap-2">
            @if ($ticket->isOpen())
                <x-ui.button variant="outline" size="sm" wire:click="markAsResolved">
                    <x-icon name="check-circle" class="h-3.5 w-3.5 mr-1.5 text-emerald-500"/>
                    {{ __('Mark as Resolved') }}
                </x-ui.button>
            @else
                <x-ui.button variant="outline" size="sm" wire:click="reopenTicket">
                    <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Reopen Ticket') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    {{-- Satisfaction Rating Banner if Resolved & not yet rated --}}
    @if ($ticket->isClosed())
        <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 space-y-3">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-2">
                    <x-icon name="star" class="h-5 w-5 text-amber-500 fill-amber-500"/>
                    <h3 class="text-xs font-bold text-foreground uppercase tracking-wider">
                        {{ __('Support Satisfaction Rating') }}
                    </h3>
                </div>
                @if ($ticket->satisfaction_rating)
                    <x-ui.badge color="emerald" class="text-xs">
                        {{ $ticket->satisfaction_rating }} / 5 {{ __('Stars') }}
                    </x-ui.badge>
                @endif
            </div>

            @if ($ticket->satisfaction_rating)
                <p class="text-xs text-muted-foreground">
                    {{ __('You rated this resolution :rating / 5 stars.', ['rating' => $ticket->satisfaction_rating]) }}
                    @if ($ticket->satisfaction_feedback)
                        <span class="italic font-medium text-foreground">"{{ $ticket->satisfaction_feedback }}"</span>
                    @endif
                </p>
            @else
                <form wire:submit="submitRating" class="space-y-3">
                    <p class="text-xs text-muted-foreground">
                        {{ __('How satisfied are you with the resolution provided by our support team?') }}
                    </p>
                    <div class="flex items-center gap-3">
                        @for ($i = 1; $i <= 5; $i++)
                            <label class="cursor-pointer">
                                <input type="radio" wire:model="rating" value="{{ $i }}" class="sr-only"/>
                                <span class="flex h-8 w-8 items-center justify-center rounded-lg border text-sm font-bold transition-all {{ $rating === $i ? 'border-amber-500 bg-amber-500 text-white shadow-xs' : 'border-border bg-background text-muted-foreground hover:text-foreground' }}">
                                    {{ $i }}★
                                </span>
                            </label>
                        @endfor
                    </div>
                    <input
                        type="text"
                        wire:model="feedback"
                        placeholder="{{ __('Optional feedback or comments...') }}"
                        class="w-full px-3 py-1.5 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 text-foreground"
                    />
                    <x-ui.button variant="default" size="sm" type="submit">
                        {{ __('Submit Feedback') }}
                    </x-ui.button>
                </form>
            @endif
        </div>
    @endif

    {{-- Thread Conversation Stream --}}
    <div class="space-y-4">
        @foreach ($publicMessages as $msg)
            @php
                $isStaff = $msg->user && ($msg->user->hasRole('Super Administrator') || $msg->user->hasRole('Administrator') || $msg->user->can('tickets.reply'));
                $isSystem = $msg->isSystemEvent();
            @endphp

            @if ($isSystem)
                {{-- System Timeline Event --}}
                <div class="flex items-center justify-center my-3">
                    <div class="px-3 py-1 rounded-full border border-border bg-secondary/40 text-[11px] text-muted-foreground flex items-center gap-1.5">
                        <x-icon name="info" class="h-3 w-3 text-primary"/>
                        <span>{{ $msg->message }}</span>
                        <span class="text-muted-foreground/60">&bull; {{ $msg->created_at->diffForHumans() }}</span>
                    </div>
                </div>
            @else
                {{-- Message Bubble --}}
                <div class="rounded-xl border {{ $isStaff ? 'border-primary/30 bg-primary/5' : 'border-border bg-card' }} p-4 sm:p-5 shadow-2xs space-y-3">
                    <div class="flex items-start justify-between gap-3">
                        <div class="flex items-center gap-2.5">
                            <div class="flex h-8 w-8 items-center justify-center rounded-full {{ $isStaff ? 'bg-primary text-primary-foreground font-bold text-xs' : 'bg-secondary text-foreground text-xs font-semibold' }}">
                                {{ substr($msg->senderDisplayName(), 0, 2) }}
                            </div>
                            <div>
                                <div class="flex items-center gap-2">
                                    <span class="text-xs font-bold text-foreground">{{ $msg->senderDisplayName() }}</span>
                                    @if ($isStaff)
                                        <x-ui.badge color="blue" class="text-[9px]">{{ __('Support Staff') }}</x-ui.badge>
                                    @endif
                                </div>
                                <div class="text-[10px] text-muted-foreground">{{ $msg->created_at->format('d M Y, h:i A') }} ({{ $msg->created_at->diffForHumans() }})</div>
                            </div>
                        </div>
                    </div>

                    <div class="text-xs text-foreground leading-relaxed whitespace-pre-line pl-10">
                        {!! nl2br(e($msg->message)) !!}
                    </div>

                    {{-- Message Attachments --}}
                    @if ($msg->attachments->isNotEmpty())
                        <div class="pl-10 pt-2 flex flex-wrap items-center gap-2">
                            @foreach ($msg->attachments as $att)
                                <a
                                    href="{{ Storage::disk($att->disk)->url($att->path) }}"
                                    target="_blank"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-border bg-background text-[11px] font-medium text-foreground hover:bg-secondary transition-colors"
                                >
                                    <x-icon name="{{ $att->isImage() ? 'image' : 'file-text' }}" class="h-3.5 w-3.5 text-primary"/>
                                    <span class="truncate max-w-[160px]">{{ $att->original_filename }}</span>
                                    <span class="text-[10px] text-muted-foreground font-mono">({{ $att->formattedSize() }})</span>
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            @endif
        @endforeach
    </div>

    {{-- Reply Box (if ticket is open or user re-opens) --}}
    <div class="rounded-xl border border-border bg-card p-5 shadow-2xs space-y-4">
        <h3 class="text-xs font-bold text-foreground uppercase tracking-wider flex items-center gap-2">
            <x-icon name="reply" class="h-4 w-4 text-primary"/>
            {{ __('Post a Reply') }}
        </h3>

        <form wire:submit="postReply" class="space-y-3">
            <textarea
                wire:model="replyMessage"
                rows="4"
                placeholder="{{ __('Type your message to support here...') }}"
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
                        class="text-[11px] text-muted-foreground file:mr-2 file:py-1 file:px-2.5 file:rounded-md file:border-0 file:text-xs file:font-medium file:bg-secondary file:text-foreground hover:file:bg-secondary/80 cursor-pointer"
                    />
                </div>

                <x-ui.button variant="default" size="sm" type="submit">
                    <x-icon name="send" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Send Reply') }}
                </x-ui.button>
            </div>
            @error('replyAttachments.*') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
        </form>
    </div>
</div>