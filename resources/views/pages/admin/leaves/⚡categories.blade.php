<?php

use App\Models\AuditLog;
use App\Models\LeaveAllocation;
use App\Models\LeaveCategory;
use App\Models\User;
use App\Support\Toast;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Leave Categories & Policies')] class extends Component {
    public string $activeTab = 'categories'; // 'categories' or 'allocations'

    public int $selectedYear = 2026;

    // Category Modal Form State
    public bool $isEditingCategory = false;
    public ?int $editingCategoryId = null;
    public string $catName = '';
    public string $catCode = '';
    public string $catApplicableTo = 'both';
    public string $catColor = 'blue';
    public float $catAnnualQuota = 12.0;
    public ?float $catMonthlyLimit = null;
    public bool $catRequiresAttachment = false;
    public bool $catRequiresApproval = true;
    public bool $catIsPaid = true;
    public bool $catIsActive = true;
    public string $catDescription = '';

    // Quota Allocation Modal
    public bool $showAllocationModal = false;
    public ?int $allocUserId = null;
    public ?int $allocCategoryId = null;
    public float $allocTotalDays = 12.0;
    public float $allocCarriedForward = 0.0;
    public string $allocNotes = '';

    // Delete confirmation
    public ?int $deleteCategoryId = null;

    public function mount(): void
    {
        $this->selectedYear = (int) Carbon::now()->year;
    }

    #[Computed]
    public function categories()
    {
        return LeaveCategory::withCount(['applications', 'allocations'])->orderBy('id')->get();
    }

    #[Computed]
    public function staffUsers()
    {
        return User::whereHas('roles', function ($q) {
            $q->whereIn('name', ['Administrator', 'Staff', 'Faculty', 'Accountant', 'Librarian']);
        })->orderBy('name')->get();
    }

    #[Computed]
    public function allocations()
    {
        return LeaveAllocation::where('year', $this->selectedYear)
            ->with(['user', 'category'])
            ->orderBy('id', 'desc')
            ->get();
    }

    public function openCreateCategoryModal(): void
    {
        $this->isEditingCategory = false;
        $this->editingCategoryId = null;
        $this->catName = '';
        $this->catCode = '';
        $this->catApplicableTo = 'both';
        $this->catColor = 'blue';
        $this->catAnnualQuota = 12.0;
        $this->catMonthlyLimit = null;
        $this->catRequiresAttachment = false;
        $this->catRequiresApproval = true;
        $this->catIsPaid = true;
        $this->catIsActive = true;
        $this->catDescription = '';
        $this->dispatch('modal-open', name: 'category-form-modal');
    }

    public function openEditCategoryModal(int $id): void
    {
        $category = LeaveCategory::findOrFail($id);
        $this->isEditingCategory = true;
        $this->editingCategoryId = $category->id;
        $this->catName = $category->name;
        $this->catCode = $category->code;
        $this->catApplicableTo = $category->applicable_to;
        $this->catColor = $category->color;
        $this->catAnnualQuota = (float) $category->annual_quota;
        $this->catMonthlyLimit = $category->monthly_limit ? (float) $category->monthly_limit : null;
        $this->catRequiresAttachment = (bool) $category->requires_attachment;
        $this->catRequiresApproval = (bool) $category->requires_approval;
        $this->catIsPaid = (bool) $category->is_paid;
        $this->catIsActive = (bool) $category->is_active;
        $this->catDescription = $category->description ?? '';
        $this->dispatch('modal-open', name: 'category-form-modal');
    }

    public function saveCategory(): void
    {
        $this->validate([
            'catName' => 'required|string|max:100',
            'catCode' => 'required|string|max:30|unique:leave_categories,code,' . $this->editingCategoryId,
            'catApplicableTo' => 'required|in:staff,student,both',
            'catAnnualQuota' => 'required|numeric|min:0',
        ]);

        try {
            $data = [
                'name' => $this->catName,
                'code' => strtoupper(trim($this->catCode)),
                'applicable_to' => $this->catApplicableTo,
                'color' => $this->catColor,
                'annual_quota' => $this->catAnnualQuota,
                'monthly_limit' => $this->catMonthlyLimit,
                'requires_attachment' => $this->catRequiresAttachment,
                'requires_approval' => $this->catRequiresApproval,
                'is_paid' => $this->catIsPaid,
                'is_active' => $this->catIsActive,
                'description' => $this->catDescription ?: null,
            ];

            if ($this->isEditingCategory) {
                $category = LeaveCategory::findOrFail($this->editingCategoryId);
                $category->update($data);
                Toast::dispatch($this, 'success', __('Leave category ":name" updated successfully.', ['name' => $category->name]));
            } else {
                $category = LeaveCategory::create($data);
                Toast::dispatch($this, 'success', __('Leave category ":name" created successfully.', ['name' => $category->name]));
            }

            $this->dispatch('modal-close', name: 'category-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to save category: :err', ['err' => $e->getMessage()]));
        }
    }

    public function confirmDeleteCategory(int $id): void
    {
        $this->deleteCategoryId = $id;
        $this->dispatch('modal-open', name: 'delete-category-modal');
    }

    public function deleteCategory(): void
    {
        if (! $this->deleteCategoryId) {
            return;
        }

        try {
            $category = LeaveCategory::findOrFail($this->deleteCategoryId);
            if ($category->applications()->exists()) {
                Toast::dispatch($this, 'error', __('Cannot delete category with active leave applications. Deactivate it instead.'));
                $this->dispatch('modal-close', name: 'delete-category-modal');
                $this->deleteCategoryId = null;
                return;
            }

            $category->delete();
            Toast::dispatch($this, 'success', __('Category deleted successfully.'));
            $this->dispatch('modal-close', name: 'delete-category-modal');
            $this->deleteCategoryId = null;
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete: :err', ['err' => $e->getMessage()]));
        }
    }

    public function openAllocationModal(): void
    {
        $this->allocUserId = null;
        $this->allocCategoryId = $this->categories->first()?->id;
        $this->allocTotalDays = 12.0;
        $this->allocCarriedForward = 0.0;
        $this->allocNotes = '';
        $this->dispatch('modal-open', name: 'allocation-form-modal');
    }

    public function saveAllocation(): void
    {
        $this->validate([
            'allocUserId' => 'required|exists:users,id',
            'allocCategoryId' => 'required|exists:leave_categories,id',
            'allocTotalDays' => 'required|numeric|min:0',
        ]);

        try {
            LeaveAllocation::updateOrCreate(
                [
                    'user_id' => $this->allocUserId,
                    'leave_category_id' => $this->allocCategoryId,
                    'year' => $this->selectedYear,
                ],
                [
                    'total_days' => $this->allocTotalDays,
                    'carried_forward_days' => $this->allocCarriedForward,
                    'notes' => $this->allocNotes,
                    'created_by' => auth()->id(),
                ]
            );

            Toast::dispatch($this, 'success', __('Leave quota allocation updated successfully.'));
            $this->dispatch('modal-close', name: 'allocation-form-modal');
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to allocate: :err', ['err' => $e->getMessage()]));
        }
    }
};
?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.leaves.index') }}" wire:navigate class="p-1 rounded-md text-muted-foreground hover:text-foreground hover:bg-muted">
                    <x-icon name="arrow-left" class="w-5 h-5" />
                </a>
                <h1 class="text-2xl font-bold tracking-tight text-foreground">{{ __('Leave Categories & Quotas') }}</h1>
            </div>
            <p class="text-sm text-muted-foreground mt-1">
                {{ __('Define leave categories (Casual, Medical, Academic, Duty), document requirements, annual quotas, and staff balances.') }}
            </p>
        </div>

        <div class="flex items-center gap-2">
            @if($activeTab === 'categories')
                <x-ui.button variant="default" size="sm" wire:click="openCreateCategoryModal" class="cursor-pointer bg-primary text-primary-foreground font-semibold">
                    <x-icon name="plus" class="w-4 h-4 mr-1.5" />
                    {{ __('New Category') }}
                </x-ui.button>
            @else
                <x-ui.button variant="default" size="sm" wire:click="openAllocationModal" class="cursor-pointer bg-primary text-primary-foreground font-semibold">
                    <x-icon name="user-plus" class="w-4 h-4 mr-1.5" />
                    {{ __('Allocate Staff Quota') }}
                </x-ui.button>
            @endif
        </div>
    </div>

    <!-- Navigation Tabs -->
    <div class="flex items-center gap-2 pb-1 border-b border-border">
        <button
            type="button"
            wire:click="$set('activeTab', 'categories')"
            class="px-4 py-2 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'categories' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
        >
            <x-icon name="tag" class="w-4 h-4" />
            {{ __('Leave Categories & Policies') }}
        </button>

        <button
            type="button"
            wire:click="$set('activeTab', 'allocations')"
            class="px-4 py-2 text-xs font-semibold rounded-lg transition-colors cursor-pointer flex items-center gap-2 {{ $activeTab === 'allocations' ? 'bg-primary text-primary-foreground shadow-xs' : 'text-muted-foreground hover:bg-muted' }}"
        >
            <x-icon name="pie-chart" class="w-4 h-4" />
            {{ __('Staff Leave Balance Allocations') }}
        </button>
    </div>

    <!-- TAB 1: CATEGORIES -->
    @if($activeTab === 'categories')
        <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
            @foreach($this->categories as $cat)
                <div class="p-5 rounded-xl border border-border bg-card shadow-xs flex flex-col justify-between">
                    <div>
                        <div class="flex items-start justify-between gap-2">
                            <div>
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-bold border mb-1.5 {{ $cat->badgeColorClass() }}">
                                    {{ $cat->code }}
                                </span>
                                <h3 class="font-bold text-foreground text-base">{{ $cat->name }}</h3>
                                <span class="text-xs text-muted-foreground block">
                                    {{ __('Applies to: :role', ['role' => ucfirst($cat->applicable_to)]) }}
                                </span>
                            </div>

                            <span class="p-2 rounded-lg bg-muted text-muted-foreground">
                                <x-icon name="calendar" class="w-5 h-5" />
                            </span>
                        </div>

                        <p class="text-xs text-muted-foreground mt-3 line-clamp-2">
                            {{ $cat->description ?: __('No specific policy description provided.') }}
                        </p>

                        <div class="mt-4 pt-3 border-t border-border/60 grid grid-cols-2 gap-2 text-xs">
                            <div>
                                <span class="text-[10px] text-muted-foreground block">{{ __('Annual Quota') }}</span>
                                <strong class="font-mono text-foreground">{{ $cat->annual_quota }} days</strong>
                            </div>
                            <div>
                                <span class="text-[10px] text-muted-foreground block">{{ __('Monthly Limit') }}</span>
                                <strong class="font-mono text-foreground">{{ $cat->monthly_limit ? $cat->monthly_limit . ' days' : __('None') }}</strong>
                            </div>
                            <div>
                                <span class="text-[10px] text-muted-foreground block">{{ __('Medical / Proof Doc') }}</span>
                                <span class="text-foreground font-semibold {{ $cat->requires_attachment ? 'text-amber-600' : '' }}">
                                    {{ $cat->requires_attachment ? __('Mandatory') : __('Optional') }}
                                </span>
                            </div>
                            <div>
                                <span class="text-[10px] text-muted-foreground block">{{ __('Compensation') }}</span>
                                <span class="text-foreground font-semibold">
                                    {{ $cat->is_paid ? __('Paid Leave') : __('Unpaid') }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 pt-3 border-t border-border flex items-center justify-between">
                        <span class="text-[11px] text-muted-foreground">
                            {{ $cat->applications_count }} {{ __('requests') }}
                        </span>

                        <div class="flex items-center gap-1">
                            <x-ui.button variant="ghost" size="xs" wire:click="openEditCategoryModal({{ $cat->id }})" class="cursor-pointer text-xs">
                                <x-icon name="edit" class="w-3.5 h-3.5 mr-1" />
                                {{ __('Edit') }}
                            </x-ui.button>
                            <x-ui.button
                                variant="ghost"
                                size="xs"
                                wire:click="confirmDeleteCategory({{ $cat->id }})"
                                class="cursor-pointer text-xs text-rose-600 hover:bg-rose-500/10"
                            >
                                <x-icon name="trash-2" class="w-3.5 h-3.5 mr-1" />
                                {{ __('Delete') }}
                            </x-ui.button>
                        </div>
                    </div>
                </div>
            @endforeach
        </div>
    @endif

    <!-- TAB 2: ALLOCATIONS -->
    @if($activeTab === 'allocations')
        <div class="rounded-xl border border-border bg-card shadow-xs overflow-hidden">
            <div class="px-4 py-3 border-b border-border flex items-center justify-between">
                <h2 class="text-sm font-semibold text-foreground">
                    {{ __('Staff Leave Quota Allocations (:year)', ['year' => $selectedYear]) }}
                </h2>
                <div class="flex items-center gap-2">
                    <select wire:model.live="selectedYear" class="px-3 py-1 text-xs bg-background border border-border rounded-lg font-mono">
                        @for($y = Carbon::now()->year + 1; $y >= Carbon::now()->year - 2; $y--)
                            <option value="{{ $y }}">{{ $y }}</option>
                        @endfor
                    </select>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs divide-y divide-border">
                    <thead class="bg-muted/50 text-muted-foreground font-medium uppercase text-[11px] tracking-wider">
                        <tr>
                            <th class="px-4 py-3">{{ __('Staff Member') }}</th>
                            <th class="px-4 py-3">{{ __('Category') }}</th>
                            <th class="px-4 py-3">{{ __('Total Quota') }}</th>
                            <th class="px-4 py-3">{{ __('Used Days') }}</th>
                            <th class="px-4 py-3">{{ __('Pending Days') }}</th>
                            <th class="px-4 py-3">{{ __('Remaining Days') }}</th>
                            <th class="px-4 py-3">{{ __('Notes') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border/60">
                        @forelse($this->allocations as $alloc)
                            <tr class="hover:bg-muted/30 transition-colors">
                                <td class="px-4 py-3">
                                    <strong class="font-medium text-foreground block">{{ $alloc->user?->name }}</strong>
                                    <span class="text-[10px] text-muted-foreground font-mono">{{ $alloc->user?->employee_id ?? $alloc->user?->email }}</span>
                                </td>

                                <td class="px-4 py-3">
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[10px] font-semibold border {{ $alloc->category?->badgeColorClass() }}">
                                        {{ $alloc->category?->name }}
                                    </span>
                                </td>

                                <td class="px-4 py-3 font-mono font-bold text-foreground">
                                    {{ $alloc->total_days }}
                                    @if($alloc->carried_forward_days > 0)
                                        <span class="text-[10px] text-muted-foreground font-normal">(+{{ $alloc->carried_forward_days }} c/f)</span>
                                    @endif
                                </td>

                                <td class="px-4 py-3 font-mono text-rose-600 dark:text-rose-400 font-bold">
                                    {{ $alloc->used_days }}
                                </td>

                                <td class="px-4 py-3 font-mono text-amber-600 dark:text-amber-400">
                                    {{ $alloc->pending_days }}
                                </td>

                                <td class="px-4 py-3 font-mono text-emerald-600 dark:text-emerald-400 font-bold">
                                    {{ $alloc->remainingDays() }}
                                </td>

                                <td class="px-4 py-3 text-muted-foreground">
                                    {{ $alloc->notes ?: '-' }}
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="7" class="px-4 py-10 text-center text-muted-foreground">
                                    {{ __('No quota overrides recorded for :year. Staff will inherit global category defaults automatically.', ['year' => $selectedYear]) }}
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    <!-- Category Modal -->
    <x-ui.modal name="category-form-modal" :title="$isEditingCategory ? __('Edit Leave Category') : __('Create Leave Category')">
        <form wire:submit="saveCategory" class="space-y-4">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Category Name') }} *</label>
                    <input type="text" wire:model="catName" placeholder="{{ __('e.g. Casual Leave') }}" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg" />
                    @error('catName') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Short Code') }} *</label>
                    <input type="text" wire:model="catCode" placeholder="{{ __('e.g. CL') }}" class="w-full px-3 py-2 text-xs font-mono uppercase bg-background border border-border rounded-lg" />
                    @error('catCode') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Applicability') }} *</label>
                    <select wire:model="catApplicableTo" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                        <option value="both">{{ __('Both (Staff & Students)') }}</option>
                        <option value="staff">{{ __('Office Staff & Faculty Only') }}</option>
                        <option value="student">{{ __('Students / Aspirants Only') }}</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Badge Accent Color') }}</label>
                    <select wire:model="catColor" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                        <option value="blue">{{ __('Blue') }}</option>
                        <option value="emerald">{{ __('Emerald Green') }}</option>
                        <option value="rose">{{ __('Rose Red') }}</option>
                        <option value="amber">{{ __('Amber Orange') }}</option>
                        <option value="purple">{{ __('Purple Violet') }}</option>
                        <option value="teal">{{ __('Teal Cyan') }}</option>
                    </select>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Default Annual Quota (Days)') }} *</label>
                    <input type="number" step="0.5" wire:model="catAnnualQuota" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Monthly Limit (Days)') }}</label>
                    <input type="number" step="0.5" wire:model="catMonthlyLimit" placeholder="{{ __('Optional') }}" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div class="space-y-2">
                <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-foreground">
                    <input type="checkbox" wire:model="catRequiresAttachment" class="rounded border-border text-primary focus:ring-primary" />
                    <span>{{ __('Mandatory Supporting Document / Medical Certificate') }}</span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-foreground">
                    <input type="checkbox" wire:model="catIsPaid" class="rounded border-border text-primary focus:ring-primary" />
                    <span>{{ __('Paid Leave (For Office Staff)') }}</span>
                </label>

                <label class="flex items-center gap-2 cursor-pointer text-xs font-medium text-foreground">
                    <input type="checkbox" wire:model="catIsActive" class="rounded border-border text-primary focus:ring-primary" />
                    <span>{{ __('Active Category in Application Form') }}</span>
                </label>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Description / Rules') }}</label>
                <textarea wire:model="catDescription" rows="2" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg"></textarea>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'category-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-primary text-primary-foreground font-semibold">
                    {{ __('Save Category') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Allocation Modal -->
    <x-ui.modal name="allocation-form-modal" :title="__('Allocate Staff Member Leave Quota')">
        <form wire:submit="saveAllocation" class="space-y-4">
            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Staff Member') }} *</label>
                <select wire:model="allocUserId" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                    <option value="">{{ __('Select Staff Member') }}</option>
                    @foreach($this->staffUsers as $st)
                        <option value="{{ $st->id }}">{{ $st->name }} ({{ $st->employee_id ?? $st->email }})</option>
                    @endforeach
                </select>
                @error('allocUserId') <span class="text-xs text-rose-600 mt-1 block">{{ $message }}</span> @enderror
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Leave Category') }} *</label>
                <select wire:model="allocCategoryId" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg">
                    @foreach($this->categories as $c)
                        <option value="{{ $c->id }}">{{ $c->name }} ({{ $c->code }})</option>
                    @endforeach
                </select>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Total Annual Days') }} *</label>
                    <input type="number" step="0.5" wire:model="allocTotalDays" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Carried Forward Days') }}</label>
                    <input type="number" step="0.5" wire:model="allocCarriedForward" class="w-full px-3 py-2 text-xs font-mono bg-background border border-border rounded-lg" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-foreground mb-1">{{ __('Reason / Allocation Note') }}</label>
                <input type="text" wire:model="allocNotes" placeholder="{{ __('e.g. Special institutional allowance') }}" class="w-full px-3 py-2 text-xs bg-background border border-border rounded-lg" />
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'allocation-form-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="submit" variant="default" size="sm" class="bg-primary text-primary-foreground font-semibold">
                    {{ __('Save Allocation') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    <!-- Delete Category Confirmation Modal -->
    <x-ui.modal name="delete-category-modal" :title="__('Delete Leave Category')">
        <div class="space-y-4">
            <div class="flex items-start gap-3">
                <div class="w-10 h-10 rounded-full bg-rose-500/10 text-rose-600 flex items-center justify-center shrink-0">
                    <x-icon name="trash-2" class="w-5 h-5" />
                </div>
                <div>
                    <h3 class="text-sm font-semibold text-foreground">{{ __('Confirm Deletion') }}</h3>
                    <p class="text-xs text-muted-foreground mt-1">
                        {{ __('Are you sure you want to delete this leave category? This action cannot be undone. Categories with historical applications cannot be deleted.') }}
                    </p>
                </div>
            </div>

            <div class="pt-4 border-t border-border flex justify-end gap-2">
                <x-ui.button type="button" variant="outline" size="sm" x-on:click="$dispatch('modal-close', { name: 'delete-category-modal' })">
                    {{ __('Cancel') }}
                </x-ui.button>
                <x-ui.button type="button" variant="destructive" size="sm" wire:click="deleteCategory" class="bg-rose-600 hover:bg-rose-700 text-white font-semibold">
                    {{ __('Yes, Delete') }}
                </x-ui.button>
            </div>
        </div>
    </x-ui.modal>
</div>
