<?php

use App\Models\EmailTemplate;
use App\Services\AuditLogService;
use App\Services\EmailService;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.app')] #[Title('Email Templates Management')] class extends Component {
    use WithPagination;

    #[Url(as: 'search')]
    public string $search = '';

    #[Url(as: 'category')]
    public string $selectedCategory = 'all';

    public bool $showTrashed = false;

    // Modal state
    public bool $modalOpen = false;
    public bool $previewModalOpen = false;
    public bool $testModalOpen = false;
    public ?int $editingTemplateId = null;

    // Form fields
    public array $form = [
        'code' => '',
        'name' => '',
        'category' => EmailTemplate::CATEGORY_NOTIFICATION,
        'subject' => '',
        'body' => '',
        'variables_text' => 'name, otp, expiry, app_name',
        'status' => true,
    ];

    // Preview state
    public string $previewSubject = '';
    public string $previewBody = '';

    // Test send state
    public ?int $testTemplateId = null;
    public string $testEmail = '';
    public array $testVariables = [];

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedCategory(): void
    {
        $this->resetPage();
    }

    public function openCreateModal(): void
    {
        $this->resetValidation();
        $this->editingTemplateId = null;
        $this->form = [
            'code' => '',
            'name' => '',
            'category' => EmailTemplate::CATEGORY_NOTIFICATION,
            'subject' => '',
            'body' => '',
            'variables_text' => 'name, app_name, email',
            'status' => true,
        ];
        $this->modalOpen = true;
    }

    public function editTemplate(int $id): void
    {
        $this->resetValidation();
        $template = EmailTemplate::withTrashed()->findOrFail($id);

        $this->editingTemplateId = $template->id;
        $this->form = [
            'code' => $template->code,
            'name' => $template->name,
            'category' => $template->category,
            'subject' => $template->subject,
            'body' => $template->body,
            'variables_text' => implode(', ', $template->variables ?? []),
            'status' => (bool) $template->status,
        ];

        $this->modalOpen = true;
    }

    public function previewTemplate(int $id): void
    {
        $template = EmailTemplate::withTrashed()->findOrFail($id);
        $sampleVars = [
            'name' => 'Jane Doe',
            'otp' => '654321',
            'expiry' => '5',
            'app_name' => \App\Models\Setting::appName(),
            'email' => 'jane@example.com',
            'course' => 'Civil Services Foundation Batch',
            'application_no' => 'APP-2026-1044',
            'date' => now()->format('d M Y'),
            'year' => date('Y'),
            'notice_title' => 'Sample Academic Notice',
            'notice_content' => 'Classes will commence from next Monday at 9:00 AM in Hall B.',
        ];

        $rendered = $template->render($sampleVars);
        $this->previewSubject = $rendered['subject'];
        $this->previewBody = $rendered['body'];
        $this->previewModalOpen = true;
    }

    public function save(): void
    {
        $rules = [
            'form.code' => ['required', 'string', 'max:50', 'alpha_dash', 'unique:email_templates,code,'.($this->editingTemplateId ?? 'NULL').',id'],
            'form.name' => ['required', 'string', 'max:100'],
            'form.category' => ['required', 'string', 'in:otp,notification,notice,communication,promotional'],
            'form.subject' => ['required', 'string', 'max:255'],
            'form.body' => ['required', 'string'],
            'form.status' => ['boolean'],
        ];

        $this->validate($rules);

        try {
            DB::transaction(function () {
                $variables = array_values(array_filter(array_map('trim', explode(',', $this->form['variables_text']))));
                $userId = auth()->id();

                $payload = [
                    'code' => $this->form['code'],
                    'name' => $this->form['name'],
                    'category' => $this->form['category'],
                    'subject' => $this->form['subject'],
                    'body' => $this->form['body'],
                    'variables' => $variables,
                    'status' => (bool) $this->form['status'],
                    'updated_by' => $userId,
                ];

                if ($this->editingTemplateId) {
                    $template = EmailTemplate::withTrashed()->findOrFail($this->editingTemplateId);
                    $template->update($payload);

                    AuditLogService::log(
                        event: 'email_template_updated',
                        description: "Updated Email template {$template->name} [{$template->code}]",
                        newValues: $payload,
                        userId: $userId
                    );

                    Toast::dispatch($this, 'success', __('Email template updated successfully.'));
                } else {
                    $payload['created_by'] = $userId;
                    $template = EmailTemplate::create($payload);

                    AuditLogService::log(
                        event: 'email_template_created',
                        description: "Created new Email template {$template->name} [{$template->code}]",
                        newValues: $payload,
                        userId: $userId
                    );

                    Toast::dispatch($this, 'success', __('Email template created successfully.'));
                }

                $this->modalOpen = false;
            });
        } catch (\Throwable $e) {
            Log::error('Failed to save Email template: '.$e->getMessage(), ['exception' => $e]);
            Toast::dispatch($this, 'error', __('Failed to save Email template.'));
        }
    }

    public function toggleStatus(int $id): void
    {
        try {
            $template = EmailTemplate::findOrFail($id);
            $template->status = ! $template->status;
            $template->updated_by = auth()->id();
            $template->save();

            Toast::dispatch($this, 'success', __('Template status updated.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Could not update status.'));
        }
    }

    public function deleteTemplate(int $id): void
    {
        try {
            $template = EmailTemplate::findOrFail($id);
            $template->deleted_by = auth()->id();
            $template->save();
            $template->delete();

            AuditLogService::log(
                event: 'email_template_deleted',
                description: "Soft deleted Email template {$template->name} [{$template->code}]",
                userId: auth()->id()
            );

            Toast::dispatch($this, 'success', __('Email template moved to trash.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to delete template.'));
        }
    }

    public function restoreTemplate(int $id): void
    {
        try {
            $template = EmailTemplate::onlyTrashed()->findOrFail($id);
            $template->restore();

            Toast::dispatch($this, 'success', __('Email template restored successfully.'));
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Failed to restore template.'));
        }
    }

    public function openTestModal(int $id): void
    {
        $template = EmailTemplate::withTrashed()->findOrFail($id);
        $this->testTemplateId = $template->id;
        $this->testEmail = '';
        $this->testVariables = [];

        foreach ($template->variables ?? [] as $var) {
            $this->testVariables[$var] = match ($var) {
                'otp' => '987123',
                'expiry' => '5',
                'name' => 'Alex Johnson',
                'course' => 'IAS Prelims Mentorship',
                'application_no' => 'APP-2026-5521',
                'notice_title' => 'Special Lecture on Public Administration',
                'notice_content' => 'Guest speaker session scheduled for Friday at 3:00 PM.',
                'date' => now()->format('d M Y'),
                'year' => date('Y'),
                'app_name' => \App\Models\Setting::appName(),
                'email' => 'alex@example.com',
                default => 'sample_'.$var,
            };
        }

        $this->testModalOpen = true;
    }

    public function sendTestEmail(EmailService $emailService): void
    {
        $this->validate([
            'testEmail' => ['required', 'email'],
        ]);

        if (! EmailService::isEnabled()) {
            Toast::dispatch($this, 'warning', __('Email Service is currently disabled in settings.'));
        }

        try {
            $template = EmailTemplate::withTrashed()->findOrFail($this->testTemplateId);
            $rendered = $template->render($this->testVariables);
            $dispatched = $emailService->send($this->testEmail, '[TEST] '.$rendered['subject'], $rendered['body']);

            if ($dispatched) {
                Toast::dispatch($this, 'success', __('Test email dispatched to :email successfully.', ['email' => $this->testEmail]));
                $this->testModalOpen = false;
            } else {
                Toast::dispatch($this, 'error', __('Email delivery failed. Check mail settings and logs.'));
            }
        } catch (\Throwable $e) {
            Toast::dispatch($this, 'error', __('Test email failed: :msg', ['msg' => $e->getMessage()]));
        }
    }

    public function with(): array
    {
        $query = EmailTemplate::query()
            ->when($this->showTrashed, fn (Builder $q) => $q->onlyTrashed())
            ->when($this->selectedCategory !== 'all', fn (Builder $q) => $q->where('category', $this->selectedCategory))
            ->when($this->search !== '', function (Builder $q) {
                $term = '%'.$this->search.'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('name', 'like', $term)
                        ->orWhere('code', 'like', $term)
                        ->orWhere('subject', 'like', $term);
                });
            })
            ->latest('id');

        return [
            'templates' => $query->paginate(10),
            'categories' => EmailTemplate::CATEGORIES,
            'emailEnabled' => EmailService::isEnabled(),
        ];
    }
}; ?>

<div class="space-y-6 max-w-7xl">
    {{-- Header --}}
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <div class="flex items-center gap-2.5">
                <div class="flex h-10 w-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                    <x-icon name="mail" class="h-5 w-5"/>
                </div>
                <div>
                    <h1 class="text-2xl font-bold tracking-tight">{{ __('Email Templates Management') }}</h1>
                    <p class="text-xs text-muted-foreground mt-0.5">{{ __('Create and customize HTML transactional emails, security verification OTPs, notices and broadcasts.') }}</p>
                </div>
            </div>
        </div>

        <div class="flex items-center gap-2">
            @if (! $emailEnabled)
                <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md text-xs font-medium bg-amber-500/10 text-amber-600 border border-amber-500/20">
                    <x-icon name="alert-triangle" class="h-3.5 w-3.5"/>
                    {{ __('Email Service Disabled') }}
                </span>
            @endif

            <x-ui.button wire:click="openCreateModal">
                <x-icon name="plus" class="h-4 w-4 mr-1.5"/>
                {{ __('Create Template') }}
            </x-ui.button>
        </div>
    </div>

    {{-- Filter bar --}}
    <div class="flex flex-col lg:flex-row items-stretch lg:items-center justify-between gap-3.5 rounded-xl border border-border bg-card p-4 shadow-xs">
        {{-- Category Pills --}}
        <div class="flex items-center gap-1.5 overflow-x-auto pb-1 lg:pb-0 scrollbar-thin scrollbar-thumb-border scrollbar-track-transparent">
            <button
                type="button"
                wire:click="$set('selectedCategory', 'all')"
                class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer shrink-0 {{ $selectedCategory === 'all' ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-secondary/70 hover:text-foreground' }}"
            >
                {{ __('All Categories') }}
            </button>
            @foreach ($categories as $catKey => $catLabel)
                <button
                    type="button"
                    wire:click="$set('selectedCategory', '{{ $catKey }}')"
                    class="px-3 py-1.5 rounded-lg text-xs font-medium transition-colors cursor-pointer shrink-0 whitespace-nowrap {{ $selectedCategory === $catKey ? 'bg-primary text-primary-foreground shadow-xs font-semibold' : 'text-muted-foreground hover:bg-secondary/70 hover:text-foreground' }}"
                >
                    {{ __($catLabel) }}
                </button>
            @endforeach
        </div>

        <div class="flex items-center gap-2.5 w-full lg:w-auto">
            <div class="relative flex-1 sm:w-64">
                <x-icon name="search" class="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search templates...') }}"
                    class="h-9 w-full rounded-lg border border-input bg-transparent pl-9 pr-3 text-xs outline-none focus:border-ring"
                />
            </div>

            <button
                type="button"
                wire:click="$toggle('showTrashed')"
                class="h-9 px-3 rounded-lg border border-border text-xs font-medium flex items-center gap-1.5 cursor-pointer transition-colors shrink-0 {{ $showTrashed ? 'bg-amber-500/10 text-amber-600 border-amber-500/20' : 'text-muted-foreground hover:bg-secondary/50' }}"
            >
                <x-icon name="trash-2" class="h-3.5 w-3.5"/>
                <span>{{ $showTrashed ? __('Viewing Trash') : __('Trash') }}</span>
            </button>
        </div>
    </div>

    {{-- Templates List --}}
    <div class="rounded-xl border border-border bg-card overflow-hidden shadow-xs">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-xs">
                <thead class="border-b border-border bg-secondary/30 text-muted-foreground uppercase text-[10px] font-semibold tracking-wider">
                    <tr>
                        <th class="px-4 py-3">{{ __('Template') }}</th>
                        <th class="px-4 py-3">{{ __('Category') }}</th>
                        <th class="px-4 py-3">{{ __('Subject') }}</th>
                        <th class="px-4 py-3 text-center">{{ __('Status') }}</th>
                        <th class="px-4 py-3 text-right">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    @forelse ($templates as $template)
                        <tr class="hover:bg-secondary/15 transition-colors">
                            <td class="px-4 py-3.5">
                                <div class="font-semibold text-foreground text-sm">{{ $template->name }}</div>
                                <div class="font-mono text-[11px] text-muted-foreground">{{ $template->code }}</div>
                            </td>
                            <td class="px-4 py-3.5">
                                @php
                                    $catColor = match ($template->category) {
                                        'otp' => 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20',
                                        'notification' => 'bg-blue-500/10 text-blue-600 border-blue-500/20',
                                        'notice' => 'bg-amber-500/10 text-amber-600 border-amber-500/20',
                                        'promotional' => 'bg-purple-500/10 text-purple-600 border-purple-500/20',
                                        default => 'bg-secondary text-muted-foreground border-border',
                                    };
                                @endphp
                                <span class="inline-flex items-center px-2 py-0.5 rounded-md text-[10px] font-semibold border {{ $catColor }}">
                                    {{ $categories[$template->category] ?? ucfirst($template->category) }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5 max-w-md">
                                <p class="font-medium text-foreground truncate">{{ $template->subject }}</p>
                                @if (! empty($template->variables))
                                    <div class="flex flex-wrap gap-1 mt-1.5">
                                        @foreach ($template->variables as $var)
                                            <span class="inline-flex items-center px-1.5 py-0.2 rounded bg-secondary text-[10px] font-mono text-muted-foreground">
                                                {{ '{'.$var.'}' }}
                                            </span>
                                        @endforeach
                                    </div>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-center">
                                @if ($template->trashed())
                                    <span class="text-[10px] text-rose-500 font-semibold">{{ __('Deleted') }}</span>
                                @else
                                    <button
                                        type="button"
                                        wire:click="toggleStatus({{ $template->id }})"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-[11px] font-medium transition-colors cursor-pointer {{ $template->status ? 'bg-emerald-500/10 text-emerald-600 hover:bg-emerald-500/20' : 'bg-secondary text-muted-foreground hover:bg-secondary/80' }}"
                                    >
                                        <span class="h-1.5 w-1.5 rounded-full {{ $template->status ? 'bg-emerald-500' : 'bg-muted-foreground' }}"></span>
                                        {{ $template->status ? __('Active') : __('Inactive') }}
                                    </button>
                                @endif
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <div class="flex items-center justify-end gap-1.5">
                                    @if ($template->trashed())
                                        <x-ui.button size="sm" variant="outline" wire:click="restoreTemplate({{ $template->id }})">
                                            <x-icon name="rotate-ccw" class="h-3.5 w-3.5 mr-1"/>
                                            {{ __('Restore') }}
                                        </x-ui.button>
                                    @else
                                        <button
                                            type="button"
                                            wire:click="previewTemplate({{ $template->id }})"
                                            class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                                            title="{{ __('Preview Email HTML') }}"
                                        >
                                            <x-icon name="eye" class="h-4 w-4"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="openTestModal({{ $template->id }})"
                                            class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                                            title="{{ __('Send Test Email') }}"
                                        >
                                            <x-icon name="send" class="h-4 w-4"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="editTemplate({{ $template->id }})"
                                            class="p-1.5 rounded-md hover:bg-secondary text-muted-foreground hover:text-foreground transition-colors cursor-pointer"
                                            title="{{ __('Edit Template') }}"
                                        >
                                            <x-icon name="edit-3" class="h-4 w-4"/>
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="deleteTemplate({{ $template->id }})"
                                            wire:confirm="{{ __('Are you sure you want to move this template to trash?') }}"
                                            class="p-1.5 rounded-md hover:bg-rose-500/10 text-muted-foreground hover:text-rose-600 transition-colors cursor-pointer"
                                            title="{{ __('Delete') }}"
                                        >
                                            <x-icon name="trash-2" class="h-4 w-4"/>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="text-center py-10 text-muted-foreground">
                                <x-icon name="mail" class="h-8 w-8 mx-auto text-muted-foreground/40 mb-2"/>
                                <p class="text-sm font-medium">{{ __('No email templates found.') }}</p>
                                <p class="text-xs text-muted-foreground/80 mt-1">{{ __('Try adjusting search or create a new template.') }}</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($templates->hasPages())
            <div class="p-4 border-t border-border">
                {{ $templates->links() }}
            </div>
        @endif
    </div>

    {{-- Create / Edit Modal --}}
    @if ($modalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-3xl bg-card border border-border rounded-2xl shadow-xl overflow-hidden animate-in fade-in zoom-in-95 max-h-[90vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border shrink-0">
                    <h2 class="text-base font-semibold">
                        {{ $editingTemplateId ? __('Edit Email Template') : __('Create Email Template') }}
                    </h2>
                    <button type="button" wire:click="$set('modalOpen', false)" class="text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-5 w-5"/>
                    </button>
                </div>

                <form wire:submit="save" class="p-6 space-y-4 overflow-y-auto flex-1">
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <x-ui.input
                            wire:model="form.name"
                            :label="__('Template Name') . ' *'"
                            placeholder="Admissions Verification Notice"
                            required
                        />

                        <x-ui.input
                            wire:model="form.code"
                            :label="__('Unique System Code') . ' *'"
                            placeholder="admissions_verification_notice"
                            hint="{{ __('Used programmatically in codebase.') }}"
                            required
                        />

                        <div class="sm:col-span-2">
                            <x-ui.select
                                wire:model="form.category"
                                :label="__('Category') . ' *'"
                                :options="$categories"
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <x-ui.input
                                wire:model="form.subject"
                                :label="__('Email Subject Line') . ' *'"
                                placeholder="Your OTP code for {app_name} is {otp}"
                                required
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <label class="block text-xs font-semibold text-muted-foreground uppercase tracking-wider mb-1.5">
                                {{ __('Email Body (HTML / Responsive Markup)') }} *
                            </label>
                            <textarea
                                wire:model="form.body"
                                rows="8"
                                placeholder="<p>Hello {name}, your code is {otp}</p>"
                                class="w-full font-mono text-xs rounded-lg border border-input bg-transparent p-3 outline-none focus:border-ring"
                                required
                            ></textarea>
                        </div>

                        <div class="sm:col-span-2">
                            <x-ui.input
                                wire:model="form.variables_text"
                                :label="__('Placeholder Variables (Comma-separated)')"
                                placeholder="name, otp, expiry, app_name, email"
                                hint="{{ __('Use these inside curly braces e.g. {otp} or {name}') }}"
                            />
                        </div>

                        <div class="sm:col-span-2">
                            <x-ui.switch
                                wire:model="form.status"
                                :label="__('Active Status')"
                                :description="__('Inactive email templates will be suppressed by the mail service.')"
                                :checked="(bool) $form['status']"
                            />
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <x-ui.button type="button" variant="outline" wire:click="$set('modalOpen', false)">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button type="submit" wire:loading.attr="disabled">
                            <x-icon name="check" class="h-4 w-4 mr-1.5"/>
                            {{ __('Save Template') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    {{-- HTML Preview Modal --}}
    @if ($previewModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-2xl bg-card border border-border rounded-2xl shadow-xl overflow-hidden max-h-[85vh] flex flex-col">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border shrink-0">
                    <div>
                        <h2 class="text-base font-semibold">{{ __('Email Preview') }}</h2>
                        <p class="text-xs text-muted-foreground mt-0.5">Subject: <span class="text-foreground font-medium">{{ $previewSubject }}</span></p>
                    </div>
                    <button type="button" wire:click="$set('previewModalOpen', false)" class="text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-5 w-5"/>
                    </button>
                </div>

                <div class="p-6 overflow-y-auto flex-1 bg-secondary/10">
                    <div class="rounded-xl border border-border bg-white text-black p-4 shadow-xs">
                        {!! $previewBody !!}
                    </div>
                </div>

                <div class="flex items-center justify-end p-4 border-t border-border shrink-0">
                    <x-ui.button variant="outline" wire:click="$set('previewModalOpen', false)">
                        {{ __('Close Preview') }}
                    </x-ui.button>
                </div>
            </div>
        </div>
    @endif

    {{-- Test Send Modal --}}
    @if ($testModalOpen)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60 backdrop-blur-xs">
            <div class="w-full max-w-lg bg-card border border-border rounded-2xl shadow-xl overflow-hidden">
                <div class="flex items-center justify-between px-6 py-4 border-b border-border">
                    <h2 class="text-base font-semibold">{{ __('Send Test Email') }}</h2>
                    <button type="button" wire:click="$set('testModalOpen', false)" class="text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-5 w-5"/>
                    </button>
                </div>

                <form wire:submit="sendTestEmail" class="p-6 space-y-4">
                    <x-ui.input
                        wire:model="testEmail"
                        :label="__('Recipient Email Address') . ' *'"
                        type="email"
                        placeholder="you@example.com"
                        icon="mail"
                        required
                    />

                    @if (! empty($testVariables))
                        <div class="space-y-2 pt-2 border-t border-border">
                            <h3 class="text-xs font-semibold text-muted-foreground uppercase">{{ __('Test Variables') }}</h3>
                            <div class="grid grid-cols-2 gap-2">
                                @foreach ($testVariables as $key => $val)
                                    <div>
                                        <label class="text-[11px] font-mono text-muted-foreground">{ {{ $key }} }</label>
                                        <input
                                            type="text"
                                            wire:model="testVariables.{{ $key }}"
                                            class="h-8 w-full rounded border border-input bg-transparent px-2 text-xs outline-none"
                                        />
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="flex items-center justify-end gap-3 pt-4 border-t border-border">
                        <x-ui.button type="button" variant="outline" wire:click="$set('testModalOpen', false)">
                            {{ __('Cancel') }}
                        </x-ui.button>
                        <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="sendTestEmail">
                            <x-icon name="send" class="h-4 w-4 mr-1.5" wire:loading.remove wire:target="sendTestEmail"/>
                            <x-icon name="refresh-cw" class="h-4 w-4 mr-1.5 animate-spin" wire:loading wire:target="sendTestEmail"/>
                            {{ __('Send Test Email') }}
                        </x-ui.button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
