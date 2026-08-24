<?php

use App\Models\TicketCategory;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Ticket Categories & SLAs')] class extends Component {
    public ?int $editingCategoryId = null;

    public array $categoryForm = [
        'name' => '',
        'slug' => '',
        'description' => '',
        'icon' => 'tag',
        'color_badge' => 'blue',
        'default_priority' => 'medium',
        'sla_response_hours' => 24,
        'sla_resolution_hours' => 72,
        'default_assigned_user_id' => null,
        'is_active' => true,
        'sort_order' => 0,
    ];

    public function openCreateModal(): void
    {
        $this->editingCategoryId = null;
        $this->categoryForm = [
            'name' => '',
            'slug' => '',
            'description' => '',
            'icon' => 'tag',
            'color_badge' => 'blue',
            'default_priority' => 'medium',
            'sla_response_hours' => 24,
            'sla_resolution_hours' => 72,
            'default_assigned_user_id' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
        $this->dispatch('modal-open', name: 'category-modal');
    }

    public function openEditModal(int $id): void
    {
        $this->editingCategoryId = $id;
        $cat = TicketCategory::findOrFail($id);
        $this->categoryForm = [
            'name' => $cat->name,
            'slug' => $cat->slug,
            'description' => $cat->description ?? '',
            'icon' => $cat->icon ?? 'tag',
            'color_badge' => $cat->color_badge ?? 'blue',
            'default_priority' => $cat->default_priority ?? 'medium',
            'sla_response_hours' => $cat->sla_response_hours,
            'sla_resolution_hours' => $cat->sla_resolution_hours,
            'default_assigned_user_id' => $cat->default_assigned_user_id,
            'is_active' => (bool) $cat->is_active,
            'sort_order' => $cat->sort_order,
        ];
        $this->dispatch('modal-open', name: 'category-modal');
    }

    public function saveCategory(): void
    {
        $this->validate([
            'categoryForm.name' => ['required', 'string', 'max:100'],
            'categoryForm.description' => ['nullable', 'string', 'max:500'],
            'categoryForm.icon' => ['required', 'string', 'max:50'],
            'categoryForm.color_badge' => ['required', 'string'],
            'categoryForm.default_priority' => ['required', 'in:low,medium,high,urgent'],
            'categoryForm.sla_response_hours' => ['required', 'integer', 'min:1', 'max:720'],
            'categoryForm.sla_resolution_hours' => ['required', 'integer', 'min:1', 'max:2160'],
            'categoryForm.default_assigned_user_id' => ['nullable', 'exists:users,id'],
            'categoryForm.is_active' => ['boolean'],
            'categoryForm.sort_order' => ['required', 'integer', 'min:0'],
        ]);

        $data = $this->categoryForm;
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        if ($this->editingCategoryId) {
            $cat = TicketCategory::findOrFail($this->editingCategoryId);
            $cat->update($data);
            Toast::dispatch($this, 'success', __('Category updated successfully.'));
        } else {
            TicketCategory::create($data);
            Toast::dispatch($this, 'success', __('Category created successfully.'));
        }

        $this->dispatch('modal-close', name: 'category-modal');
    }

    public ?int $deletingCategoryId = null;
    public ?string $deletingCategoryName = null;

    public function confirmDelete(int $id): void
    {
        $cat = TicketCategory::findOrFail($id);
        $this->deletingCategoryId = $cat->id;
        $this->deletingCategoryName = $cat->name;
        $this->dispatch('modal-open', name: 'delete-category-modal');
    }

    public function deleteCategory(int $id): void
    {
        $this->deletingCategoryId = $id;
        $this->executeDelete();
    }

    public function executeDelete(): void
    {
        if (! $this->deletingCategoryId) {
            return;
        }

        $cat = TicketCategory::findOrFail($this->deletingCategoryId);
        $cat->delete();

        $this->deletingCategoryId = null;
        $this->dispatch('modal-close', name: 'delete-category-modal');
        Toast::dispatch($this, 'success', __('Category deleted successfully.'));
    }

    public function with(): array
    {
        $staffUsers = User::whereHas('roles', fn ($q) => $q->whereIn('name', ['Super Administrator', 'Administrator', 'Staff', 'Faculty', 'Admissions Officer']))->get();

        return [
            'categories' => TicketCategory::with('defaultAssignee')->ordered()->get(),
            'staffUsers' => $staffUsers,
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
                    <x-icon name="tag" class="h-5 w-5"/>
                </span>
                {{ __('Ticket Categories & SLA Rules') }}
            </h1>
            <p class="text-xs sm:text-sm text-muted-foreground mt-1">
                {{ __('Define support departments, automated SLA response and resolution time targets, and default staff assignments.') }}
            </p>
        </div>

        <x-ui.button variant="default" size="sm" wire:click="openCreateModal">
            <x-icon name="plus" class="h-3.5 w-3.5 mr-1.5"/>
            {{ __('New Category') }}
        </x-ui.button>
    </div>

    {{-- Categories Table --}}
    <div class="rounded-xl border border-border bg-card shadow-2xs overflow-hidden p-4 sm:p-6 space-y-4">
        <div class="overflow-x-auto rounded-lg border border-border">
            <table class="w-full text-left text-xs">
                <thead class="bg-secondary/40 text-muted-foreground uppercase text-[10px] tracking-wider border-b border-border">
                    <tr>
                        <th class="p-3">{{ __('Category Name') }}</th>
                        <th class="p-3">{{ __('Description') }}</th>
                        <th class="p-3">{{ __('Default Priority') }}</th>
                        <th class="p-3">{{ __('SLA Response') }}</th>
                        <th class="p-3">{{ __('SLA Resolution') }}</th>
                        <th class="p-3">{{ __('Default Agent') }}</th>
                        <th class="p-3">{{ __('Status') }}</th>
                        <th class="p-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($categories as $cat)
                        <tr wire:key="cat-row-{{ $cat->id }}" class="hover:bg-secondary/15 transition-colors">
                            <td class="p-3 font-semibold text-foreground">
                                <div class="flex items-center gap-2">
                                    <span class="p-1.5 rounded-md bg-secondary text-primary">
                                        <x-icon name="{{ $cat->icon ?: 'tag' }}" class="h-3.5 w-3.5"/>
                                    </span>
                                    <div>
                                        <span>{{ $cat->name }}</span>
                                        <div class="text-[10px] text-muted-foreground font-mono">{{ $cat->slug }}</div>
                                    </div>
                                </div>
                            </td>
                            <td class="p-3 text-muted-foreground max-w-[220px] truncate">
                                {{ $cat->description ?: '—' }}
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="match($cat->default_priority) { 'urgent' => 'destructive', 'high' => 'rose', 'medium' => 'amber', default => 'emerald' }" class="text-[10px] capitalize">
                                    {{ $cat->default_priority }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3 font-medium text-foreground">
                                {{ $cat->sla_response_hours }} {{ __('hrs') }}
                            </td>
                            <td class="p-3 font-medium text-foreground">
                                {{ $cat->sla_resolution_hours }} {{ __('hrs') }}
                            </td>
                            <td class="p-3 text-muted-foreground">
                                {{ $cat->defaultAssignee?->name ?? __('Unassigned') }}
                            </td>
                            <td class="p-3">
                                <x-ui.badge :color="$cat->is_active ? 'emerald' : 'secondary'" class="text-[10px]">
                                    {{ $cat->is_active ? __('Active') : __('Disabled') }}
                                </x-ui.badge>
                            </td>
                            <td class="p-3 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    <button type="button" wire:click="openEditModal({{ $cat->id }})" class="p-1.5 rounded-md text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors cursor-pointer" title="{{ __('Edit') }}">
                                        <x-icon name="edit-2" class="h-3.5 w-3.5"/>
                                    </button>
                                    <button type="button" wire:click="confirmDelete({{ $cat->id }})" class="p-1.5 rounded-md text-destructive hover:bg-destructive/10 transition-colors cursor-pointer" title="{{ __('Delete') }}">
                                        <x-icon name="trash" class="h-3.5 w-3.5"/>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="p-8 text-center text-muted-foreground text-xs">
                                {{ __('No categories created yet.') }}
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    {{-- Category Create/Edit Modal --}}
    <x-ui.modal name="category-modal" max-width="max-w-xl" :title="$editingCategoryId ? __('Edit Category & SLA') : __('Create Ticket Category')" :description="__('Configure category department metadata and target SLA durations.')">
        <form wire:submit="saveCategory" class="space-y-5 pt-1">
            <x-ui.input
                wire:model="categoryForm.name"
                :label="__('Category Name') . ' *'"
                placeholder="e.g. Technical Support, Fee Accounts"
            />

            <x-ui.input
                wire:model="categoryForm.description"
                :label="__('Short Description')"
                placeholder="Brief summary displayed to users..."
            />

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.select
                    wire:model="categoryForm.default_priority"
                    :label="__('Default Priority') . ' *'"
                    :options="['low' => __('Low'), 'medium' => __('Medium'), 'high' => __('High'), 'urgent' => __('Urgent')]"
                />

                <x-ui.select
                    wire:model="categoryForm.color_badge"
                    :label="__('Badge Color') . ' *'"
                    :options="['blue' => 'Blue', 'emerald' => 'Emerald', 'amber' => 'Amber', 'indigo' => 'Indigo', 'rose' => 'Rose', 'zinc' => 'Zinc']"
                />
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <x-ui.input
                    wire:model="categoryForm.sla_response_hours"
                    type="number"
                    min="1"
                    :label="__('SLA Response Target (Hours)') . ' *'"
                />

                <x-ui.input
                    wire:model="categoryForm.sla_resolution_hours"
                    type="number"
                    min="1"
                    :label="__('SLA Resolution Target (Hours)') . ' *'"
                />
            </div>

            <x-ui.select
                wire:model="categoryForm.default_assigned_user_id"
                :label="__('Default Assigned Agent')"
                :options="['' => __('Unassigned')] + $staffUsers->pluck('name', 'id')->toArray()"
            />

            <div class="flex items-center justify-end gap-2.5 pt-4 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('category-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="default" size="sm" type="submit">
                    <x-icon name="check" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Save Category') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete Category Confirmation Modal --}}
    <x-ui.modal name="delete-category-modal" max-width="max-w-md" :title="__('Delete Ticket Category')" :description="__('Please confirm before deleting this category.')">
        <div class="space-y-4 pt-1">
            <div class="p-4 rounded-xl bg-destructive/10 border border-destructive/20 text-destructive text-xs space-y-2">
                <div class="font-bold flex items-center gap-2">
                    <x-icon name="alert-triangle" class="h-4 w-4 shrink-0"/>
                    <span>{{ __('Confirm Category Deletion') }}</span>
                </div>
                <p class="text-[11px] opacity-90 leading-relaxed">
                    {{ __('Are you sure you want to delete category ":name"? Existing tickets linked to this category will preserve their records.', ['name' => $deletingCategoryName]) }}
                </p>
            </div>

            <div class="flex items-center justify-end gap-2.5 pt-3 border-t border-border">
                <x-ui.button variant="outline" size="sm" type="button" x-data x-on:click="$store.modals.close('delete-category-modal')">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button variant="destructive" size="sm" type="button" wire:click="executeDelete">
                    <x-icon name="trash" class="h-3.5 w-3.5 mr-1.5"/>
                    {{ __('Delete Category') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>