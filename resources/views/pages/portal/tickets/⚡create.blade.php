<?php

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Services\TicketService;
use App\Support\Toast;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.app')] #[Title('Open Support Ticket')] class extends Component {
    use WithFileUploads;

    public ?int $category_id = null;
    public string $priority = Ticket::PRIORITY_MEDIUM;
    public string $subject = '';
    public string $description = '';
    public array $attachments = [];

    public function mount(): void
    {
        $firstCategory = TicketCategory::active()->ordered()->first();
        if ($firstCategory) {
            $this->category_id = $firstCategory->id;
            $this->priority = $firstCategory->default_priority ?? Ticket::PRIORITY_MEDIUM;
        }
    }

    public function updatedCategoryId(int $id): void
    {
        $cat = TicketCategory::find($id);
        if ($cat && $cat->default_priority) {
            $this->priority = $cat->default_priority;
        }
    }

    public function submitTicket(): void
    {
        $this->validate([
            'category_id' => ['required', 'exists:ticket_categories,id'],
            'priority' => ['required', 'in:low,medium,high,urgent'],
            'subject' => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'min:10'],
            'attachments.*' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,zip,txt'], // max 10MB each
        ]);

        /** @var TicketService $service */
        $service = app(TicketService::class);

        $ticket = $service->createTicket(
            data: [
                'category_id' => $this->category_id,
                'priority' => $this->priority,
                'subject' => $this->subject,
                'description' => $this->description,
                'source' => Ticket::SOURCE_PORTAL,
            ],
            creator: auth()->user(),
            uploadedFiles: $this->attachments
        );

        Toast::dispatch($this, 'success', __("Support ticket #:number has been submitted successfully.", ['number' => $ticket->ticket_number]));

        $this->redirect(route('tickets.show', $ticket->ticket_number), navigate: true);
    }

    public function with(): array
    {
        return [
            'categories' => TicketCategory::active()->ordered()->get(),
        ];
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-6">
    {{-- Header --}}
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('tickets.index') }}" class="text-xs text-muted-foreground hover:text-primary transition-colors flex items-center gap-1">
                    <x-icon name="arrow-left" class="h-3 w-3"/>
                    {{ __('Back to My Tickets') }}
                </a>
            </div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <x-icon name="plus-circle" class="h-5 w-5"/>
                </span>
                {{ __('Open a Support Ticket') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Please provide clear details regarding your issue or inquiry so our team can assist you promptly.') }}
            </p>
        </div>
    </div>

    {{-- Submission Form Card --}}
    <div class="rounded-xl border border-border bg-card p-6 shadow-2xs">
        <form wire:submit="submitTicket" class="space-y-6">
            {{-- Step 1: Select Category --}}
            <div class="space-y-3">
                <label class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('1. Select Query Category') }} <span class="text-destructive">*</span>
                </label>
                <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-3">
                    @foreach ($categories as $cat)
                        <label
                            class="relative flex flex-col p-3.5 rounded-xl border cursor-pointer transition-all {{ $category_id === $cat->id ? 'border-primary bg-primary/5 ring-2 ring-primary/20' : 'border-border bg-background hover:bg-secondary/30' }}"
                        >
                            <input
                                type="radio"
                                wire:model.live="category_id"
                                value="{{ $cat->id }}"
                                class="sr-only"
                            />
                            <div class="flex items-center gap-2.5 mb-1.5">
                                <span class="flex h-7 w-7 items-center justify-center rounded-lg bg-secondary text-primary">
                                    <x-icon name="{{ $cat->icon ?: 'tag' }}" class="h-4 w-4"/>
                                </span>
                                <span class="text-xs font-semibold text-foreground">{{ $cat->name }}</span>
                            </div>
                            <p class="text-[11px] text-muted-foreground line-clamp-2">
                                {{ $cat->description }}
                            </p>
                        </label>
                    @endforeach
                </div>
                @error('category_id') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Step 2: Priority Selection --}}
            <div class="space-y-2">
                <label class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('2. Urgency / Priority Level') }} <span class="text-destructive">*</span>
                </label>
                <div class="flex flex-wrap items-center gap-2">
                    @foreach (['low' => __('Low (General Query)'), 'medium' => __('Medium (Standard Support)'), 'high' => __('High (Urgent Academic/Fee)'), 'urgent' => __('Urgent (Critical System Issue)')] as $key => $label)
                        <label class="cursor-pointer">
                            <input type="radio" wire:model="priority" value="{{ $key }}" class="sr-only"/>
                            <span class="inline-flex items-center px-3 py-1.5 rounded-lg text-xs font-medium border transition-colors {{ $priority === $key ? 'border-primary bg-primary text-primary-foreground font-semibold shadow-2xs' : 'border-border bg-background text-muted-foreground hover:text-foreground' }}">
                                {{ $label }}
                            </span>
                        </label>
                    @endforeach
                </div>
                @error('priority') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Step 3: Subject & Description --}}
            <div class="space-y-4">
                <div>
                    <label class="text-xs font-bold text-foreground uppercase tracking-wider block mb-1.5">
                        {{ __('3. Subject Summary') }} <span class="text-destructive">*</span>
                    </label>
                    <input
                        type="text"
                        wire:model="subject"
                        placeholder="{{ __('e.g. Unable to download Mock Test #3 Question Paper') }}"
                        class="w-full px-3.5 py-2 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground placeholder:text-muted-foreground"
                    />
                    @error('subject') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="text-xs font-bold text-foreground uppercase tracking-wider block mb-1.5">
                        {{ __('4. Detailed Description') }} <span class="text-destructive">*</span>
                    </label>
                    <textarea
                        wire:model="description"
                        rows="6"
                        placeholder="{{ __('Please describe your issue in detail, including steps to reproduce, course name, batch, or error messages encountered...') }}"
                        class="w-full px-3.5 py-2 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground placeholder:text-muted-foreground font-sans resize-y"
                    ></textarea>
                    @error('description') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            {{-- Step 4: Attachments --}}
            <div class="space-y-2 p-4 rounded-xl border border-border bg-secondary/15">
                <label class="text-xs font-bold text-foreground uppercase tracking-wider block">
                    {{ __('5. Supporting Documents / Screenshots (Optional)') }}
                </label>
                <p class="text-[11px] text-muted-foreground">
                    {{ __('Attach screenshots, fee payment receipts, PDF notes, or logs. Max 10MB per file.') }}
                </p>

                <input
                    type="file"
                    wire:model="attachments"
                    multiple
                    accept=".jpg,.jpeg,.png,.webp,.pdf,.doc,.docx,.zip,.txt"
                    class="block w-full text-xs text-muted-foreground file:mr-3 file:py-1.5 file:px-3 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-primary file:text-primary-foreground hover:file:bg-primary/90 cursor-pointer"
                />

                <div wire:loading wire:target="attachments" class="text-xs text-primary flex items-center gap-1.5 mt-2">
                    <x-icon name="refresh-cw" class="h-3.5 w-3.5 animate-spin"/>
                    {{ __('Uploading attachments...') }}
                </div>

                @error('attachments.*') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
            </div>

            {{-- Actions --}}
            <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" :href="route('tickets.index')">
                    {{ __('Cancel') }}
                </x-ui.button>

                <x-ui.button variant="default" size="sm" type="submit">
                    <x-icon name="send" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Submit Support Ticket') }}
                </x-ui.button>
            </div>
        </form>
    </div>
</div>