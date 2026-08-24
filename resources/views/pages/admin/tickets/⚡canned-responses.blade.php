<?php

use App\Models\TicketCannedResponse;
use App\Models\TicketCategory;
use App\Support\Toast;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Canned Responses & Macros')] class extends Component {
    public ?int $editingMacroId = null;

    public array $macroForm = [
        'title' => '',
        'shortcut' => '',
        'category_id' => null,
        'content' => '',
        'is_active' => true,
    ];

    public function openCreateModal(): void
    {
        $this->editingMacroId = null;
        $this->macroForm = [
            'title' => '',
            'shortcut' => '',
            'category_id' => null,
            'content' => '',
            'is_active' => true,
        ];
        $this->dispatch('modal-open', name: 'canned-modal');
    }

    public function openEditModal(int $id): void
    {
        $this->editingMacroId = $id;
        $m = TicketCannedResponse::findOrFail($id);
        $this->macroForm = [
            'title' => $m->title,
            'shortcut' => $m->shortcut ?? '',
            'category_id' => $m->category_id,
            'content' => $m->content,
            'is_active' => (bool) $m->is_active,
        ];
        $this->dispatch('modal-open', name: 'canned-modal');
    }

    public function saveMacro(): void
    {
        $this->validate([
            'macroForm.title' => ['required', 'string', 'max:100'],
            'macroForm.shortcut' => ['nullable', 'string', 'max:50'],
            'macroForm.category_id' => ['nullable', 'exists:ticket_categories,id'],
            'macroForm.content' => ['required', 'string', 'min:5'],
            'macroForm.is_active' => ['boolean'],
        ]);

        if ($this->editingMacroId) {
            $m = TicketCannedResponse::findOrFail($this->editingMacroId);
            $m->update($this->macroForm);
            Toast::dispatch($this, 'success', __('Canned response updated.'));
        } else {
            TicketCannedResponse::create(array_merge($this->macroForm, ['created_by' => auth()->id()]));
            Toast::dispatch($this, 'success', __('Canned response created.'));
        }

        $this->dispatch('modal-close', name: 'canned-modal');
    }

    public ?int $deletingMacroId = null;
    public ?string $deletingMacroTitle = null;

    public function confirmDelete(int $id): void
    {
        $m = TicketCannedResponse::findOrFail($id);
        $this->deletingMacroId = $m->id;
        $this->deletingMacroTitle = $m->title;
        $this->dispatch('modal-open', name: 'delete-macro-modal');
    }

    public function deleteMacro(int $id): void
    {
        $this->deletingMacroId = $id;
        $this->executeDelete();
    }

    public function executeDelete(): void
    {
        if (! $this->deletingMacroId) {
            return;
        }

        TicketCannedResponse::findOrFail($this->deletingMacroId)->delete();

        $this->deletingMacroId = null;
        $this->dispatch('modal-close', name: 'delete-macro-modal');
        Toast::dispatch($this, 'success', __('Canned response deleted successfully.'));
    }

    public function with(): array
    {
        return [
            'macros' => TicketCannedResponse::with('category')->latest('id')->get(),
            'categories' => TicketCategory::active()->ordered()->get(),
        ];
    }
}; ?>

<div class="space-y-6">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2 mb-1">
                <a href="{{ route('admin.tickets.index') }}" class="text-xs text-muted-foreground hover:text-primary transition-colors flex items-center gap-1">
                    <x-icon name="arrow-left" class="h-3 w-3"/>
                    {{ __('Back to Helpdesk Desk') }}
                </a>
            </div>
            <h1 class="text-xl sm:text-2xl font-bold tracking-tight text-foreground flex items-center gap-2.5">
                <span class="flex h-9 w-9 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <x-icon name="message-square-quote" class="h-5 w-5"/>
                </span>
                {{ __('Canned Responses & Macros') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Create pre-written response templates with dynamic variable placeholders for quick agent insertion.') }}
            </p>
        </div>

        <x-ui.button variant="default" size="sm" wire:click="openCreateModal">
            <x-icon name="plus" class="h-3.5 w-3.5 mr-1.5"/>
            {{ __('New Canned Macro') }}
        </x-ui.button>
    </div>

    {{-- Macros Table --}}
    <div class="rounded-xl border border-border bg-card shadow-2xs overflow-hidden p-4 sm:p-6 space-y-4">
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-xs">
                <thead class="bg-secondary/40 text-muted-foreground uppercase text-[10px] tracking-wider border-b border-border">
                    <tr>
                        <th class="p-3">{{ __('Macro Title') }}</th>
                        <th class="p-3">{{ __('Shortcut Trigger') }}</th>
                        <th class="p-3">{{ __('Category Scoped') }}</th>
                        <th class="p-3">{{ __('Template Content Preview') }}</th>
                        <th class="p-3">{{ __('Status') }}</th>
                        <th class="p-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($macros as $m)
                        <tr wire:key="macro-row-{{ $m->id }}" class="hover:bg-secondary/15 transition-colors">
                            <td class="p-3 font-semibold text-foreground">
                                {{ $m->title }}
                            </td>
                            <td class="p-3 font-mono text-primary font-bold">
                                {{ $m->shortcut ?: '—' }}
                            </td>
                            <td class="p-3">
                                @if ($m->category)
                                    <x-ui.badge :color="$m->category->color_badge" class="text-[10px]">
                                        {{ $m->category->name }}
                                    </x-ui.badge>
                                @else
                                    <span class="text-muted-foreground">{{ __('All Categories') }}</span>
                                @endif
                            </td>
                            <td class="p-3 text-muted-foreground max-w-[280px] truncate">
                                {{ $m->content }}
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="$m->is_active ? 'emerald' : 'secondary'" class="text-[10px]">
                                    {{ $m->is_active ? __('Active') : __('Disabled') }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" wire:click="openEditModal({{ $m->id }})" class="p-1.5 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer" title="{{ __('Edit') }}">
                                        <x-icon name="edit-2" class="h-3.5 w-3.5"/>
                                    </button>
                                    <button type="button" wire:click="confirmDelete({{ $m->id }})" class="p-1.5 rounded-md text-destructive hover:bg-destructive/10 transition-colors cursor-pointer" title="{{ __('Delete') }}">
                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="p-8 text-center text-muted-foreground text-xs">
                                {{ __('No canned response macros created yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create / Edit Modal --}}
    <x-ui.modal name="canned-modal" max-width="max-w-xl" :title="$editingMacroId ? __('Edit Canned Macro') : __('Create Canned Macro')" :description="__('Pre-formatted responses with dynamic variable placeholders for one-click agent insertion.')">
        <form wire:submit="saveMacro" class="space-y-5 pt-1">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input
                    wire:model="macroForm.title"
                    :label="__('Macro Title') . ' *'"
                    placeholder="e.g. Acknowledge Receipt"
                />

                <x-ui.input
                    wire:model="macroForm.shortcut"
                    :label="__('Quick Shortcut Code')"
                    placeholder="e.g. /ack, /fee-info"
                />
            </div>

            <x-ui.select
                wire:model="macroForm.category_id"
                :label="__('Category Scope (Optional)')"
                :options="['' => __('Available across all categories')] + $categories->pluck('name', 'id')->toArray()"
            />

            <div class="space-y-2">
                <div class="flex items-center justify-between">
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground block">
                        {{ __('Response Template Text') }} <span class="text-destructive">*</span>
                    </label>
                </div>

                {{-- Variable Chips --}}
                <div class="flex flex-wrap items-center gap-1.5 p-2 rounded-lg bg-secondary/30 border border-border">
                    <span class="text-[10px] font-semibold text-muted-foreground uppercase mr-1">{{ __('Variables:') }}</span>
                    @foreach (['{user_name}' => __('User Name'), '{ticket_number}' => __('Ticket #'), '{agent_name}' => __('Agent Name'), '{app_name}' => __('App Name')] as $token => $tokenLabel)
                        <button
                            type="button"
                            x-on:click="$wire.macroForm.content = ($wire.macroForm.content ? $wire.macroForm.content + ' ' : '') + '{{ $token }}'"
                            class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md bg-background text-[11px] font-mono text-primary border border-border hover:border-primary/50 transition-colors cursor-pointer"
                            title="{{ $tokenLabel }}"
                        >
                            {{ $token }}
                        </button>
                    @endforeach
                </div>

                <textarea
                    wire:model="macroForm.content"
                    rows="6"
                    class="w-full px-3.5 py-2 text-xs bg-background rounded-lg border border-border focus:outline-hidden focus:ring-2 focus:ring-primary/20 focus:border-primary text-foreground placeholder:text-muted-foreground font-sans resize-y"
                    placeholder="Hello {user_name},&#10;&#10;Thank you for contacting {app_name} regarding #{ticket_number}..."
                ></textarea>
                @error('macroForm.content') <p class="text-xs text-destructive mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('canned-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" size="sm" type="submit">
                    <x-icon name="check" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Save Macro') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete Macro Confirmation Modal --}}
    <x-ui.modal name="delete-macro-modal" max-width="max-w-md" :title="__('Delete Canned Macro')" :description="__('Please confirm before removing this response template.')">
        <div class="space-y-4 pt-1">
            <div class="p-4 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs space-y-2">
                <div class="font-bold flex items-center gap-2">
                    <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                    <span>{{ __('Confirm Macro Deletion') }}</span>
                </div>
                <p class="text-[11px] opacity-90 leading-relaxed">
                    {{ __('Are you sure you want to delete macro ":title"? Staff agents will no longer be able to insert this shortcut in ticket responses.', ['title' => $deletingMacroTitle]) }}
                </p>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('delete-macro-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" size="sm" type="button" wire:click="executeDelete">
                    <x-icon name="trash" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Delete Macro') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>