<?php

use App\Concerns\InteractsWithDataTable;
use App\Imports\TableImport;
use App\Models\Setting;
use App\Services\FileUploadService;
use App\Support\Export\TableExporter;
use App\Support\Toast;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Maatwebsite\Excel\Facades\Excel;
use Maatwebsite\Excel\Validators\ValidationException as ExcelValidationException;

new #[Layout('layouts.app')] #[Title('Settings')] class extends Component {
    use WithPagination;
    use WithFileUploads;
    use InteractsWithDataTable;

    public ?int $editingId = null;

    public array $form = [
        'key' => '',
        'value' => '',
        'group' => 'general',
        'type' => Setting::TYPE_STRING,
        'label' => '',
        'optionsText' => '',
        'status' => true,
    ];

    public $settingFile = null;

    public ?TemporaryUploadedFile $importFile = null;

    /** Columns searched by the table search box. */
    private const SEARCHABLE = ['key', 'label', 'group', 'type'];

    /** Columns the table can be sorted by. */
    private const SORTABLE = ['key', 'group', 'type', 'status', 'updated_at'];

    /** Headings shared by export and the import template. */
    private const EXPORT_HEADINGS = ['Key', 'Value', 'Group', 'Type', 'Label', 'Status', 'Updated'];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function settings()
    {
        return $this->applyDataTable($this->settingsQuery(), self::SEARCHABLE, [
            'group' => 'group',
            'type' => 'type',
            'status' => fn (Builder $query, mixed $value) => $query->where('status', filter_var($value, FILTER_VALIDATE_BOOLEAN)),
        ], self::SORTABLE)->paginate($this->perPage);
    }

    #[Computed]
    public function groups(): array
    {
        return Setting::query()->distinct()->orderBy('group')->pluck('group')->all();
    }

    protected function settingsQuery(): Builder
    {
        return Setting::query()->with('editor:id,name');
    }

    /* ----------------------------------------------------------------- *
     *  CRUD
     * ----------------------------------------------------------------- */

    public function create(): void
    {
        $this->editingId = null;
        $this->settingFile = null;
        $this->resetErrorBag();
        $this->form = [
            'key' => '', 'value' => '', 'group' => 'general', 'type' => Setting::TYPE_STRING,
            'label' => '', 'optionsText' => '', 'status' => true,
        ];

        $this->dispatch('modal-open', name: 'setting-form');
    }

    public function edit(int $id): void
    {
        $setting = Setting::query()->findOrFail($id);

        $this->editingId = $id;
        $this->settingFile = null;
        $this->resetErrorBag();
        $this->form = [
            'key' => $setting->key,
            // Secrets are never pre-filled — leave blank to keep the stored value.
            'value' => $setting->type === Setting::TYPE_SECRET ? '' : (string) ($setting->rawValue() ?? ''),
            'group' => $setting->group,
            'type' => $setting->type,
            'label' => (string) ($setting->label ?? ''),
            'optionsText' => collect($setting->options ?? [])
                ->map(fn (string $label, string $value) => "{$value}:{$label}")
                ->implode("\n"),
            'status' => (bool) $setting->status,
        ];

        $this->dispatch('modal-open', name: 'setting-form');
    }

    public function save(): void
    {
        $this->validate([
            'form.key' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9_]+(\.[a-z0-9_]+)+$/i',
                Rule::unique(Setting::class, 'key')->ignore($this->editingId)],
            'form.group' => ['required', 'string', 'max:255'],
            'form.type' => ['required', Rule::in(array_keys($this->types()))],
            'form.label' => ['nullable', 'string', 'max:255'],
            'form.value' => ['nullable', 'string'],
            'form.optionsText' => ['nullable', 'string', 'max:5000'],
            'settingFile' => array_values(array_filter([
                'nullable',
                $this->form['type'] === Setting::TYPE_IMAGE ? 'image' : null,
                $this->form['type'] === Setting::TYPE_FILE ? 'file' : null,
                in_array($this->form['type'], [Setting::TYPE_IMAGE, Setting::TYPE_FILE], true) ? 'max:10240' : null,
            ])),
        ], [
            'form.key.regex' => __('The key must be dot-separated (e.g. sms.otp_length).'),
            'settingFile.image' => __('The uploaded file must be an image.'),
            'settingFile.max' => __('The uploaded file exceeds the allowed file size.'),
        ]);

        if ($this->form['type'] === Setting::TYPE_SELECT && trim($this->form['optionsText'] ?? '') === '') {
            $this->addError('form.optionsText', __('Select-type settings need at least one option.'));

            return;
        }

        if ($this->settingFile && in_array($this->form['type'], [Setting::TYPE_IMAGE, Setting::TYPE_FILE], true)) {
            $folder = 'settings/'.($this->form['group'] ?: 'general');
            $prefix = \Illuminate\Support\Str::slug(str_replace('.', '_', $this->form['key'] ?: 'setting'), '_');
            $path = FileUploadService::store(
                file: $this->settingFile,
                folder: $folder,
                prefix: $prefix,
                oldPath: $this->editingId ? $this->form['value'] : null
            );
            $this->form['value'] = $path;
        }

        $setting = $this->editingId
            ? Setting::query()->findOrFail($this->editingId)
            : new Setting;

        $setting->fill([
            'key' => $this->form['key'],
            'group' => $this->form['group'],
            'type' => $this->form['type'],
            'label' => $this->form['label'] ?: null,
            'options' => $this->parseOptions(),
            'status' => (bool) $this->form['status'],
        ]);

        // Only overwrite the value when it was actually submitted: secrets
        // left blank keep their stored ciphertext.
        if ($this->editingId === null || trim((string) $this->form['value']) !== '' || $setting->type !== Setting::TYPE_SECRET) {
            $setting->value = $this->form['value'];
        }

        $setting->created_by ??= auth()->id();
        $setting->updated_by = auth()->id();
        $setting->save();

        $this->reset('settingFile');

        Toast::dispatch($this, 'success', $this->editingId ? __('Setting updated.') : __('Setting created.'));
        $this->dispatch('modal-close', name: 'setting-form');
    }

    public ?int $deleteId = null;

    public function selectForDelete(int $id): void
    {
        $this->deleteId = $id;
    }

    public function deleteSelected(): void
    {
        if ($this->deleteId) {
            $setting = Setting::query()->findOrFail($this->deleteId);
            $setting->update(['deleted_by' => auth()->id()]);
            $setting->delete();
            $this->deleteId = null;

            Toast::dispatch($this, 'success', __('Setting deleted.'));
            $this->dispatch('modal-close', name: 'setting-delete');
        }
    }

    public function toggleStatus(int $id): void
    {
        $setting = Setting::query()->findOrFail($id);
        $setting->update(['status' => ! $setting->status, 'updated_by' => auth()->id()]);

        Toast::dispatch($this, 'success', $setting->status ? __('Setting enabled.') : __('Setting disabled.'));
    }

    /* ----------------------------------------------------------------- *
     *  Export / import (reusable TableExporter + TableImport)
     * ----------------------------------------------------------------- */

    /** Export the currently filtered + sorted rows as xlsx, csv or pdf. */
    public function export(string $format)
    {
        $query = $this->applyDataTable($this->settingsQuery(), self::SEARCHABLE, [
            'group' => 'group',
            'type' => 'type',
            'status' => fn (Builder $query, mixed $value) => $query->where('status', filter_var($value, FILTER_VALIDATE_BOOLEAN)),
        ], self::SORTABLE);

        return TableExporter::download(
            $query,
            $format,
            'settings',
            self::EXPORT_HEADINGS,
            fn (Setting $setting) => [
                $setting->key,
                $setting->type === Setting::TYPE_SECRET ? '' : (string) ($setting->rawValue() ?? ''),
                $setting->group,
                $setting->type,
                (string) ($setting->label ?? ''),
                $setting->status ? '1' : '0',
                $setting->updated_at?->format('Y-m-d H:i'),
            ],
            __('Settings'),
        );
    }

    /** A header-only CSV users can fill in and re-upload. */
    public function downloadTemplate()
    {
        return TableExporter::download(
            Setting::query()->whereKey(-1),
            'csv',
            'settings-import-template',
            self::EXPORT_HEADINGS,
            fn (Setting $setting) => [],
            __('Settings import template'),
        );
    }

    public function import(): void
    {
        $this->validate([
            'importFile' => ['required', 'file', 'mimes:xlsx,csv,txt', 'max:5120'],
        ]);

        $userId = auth()->id();

        try {
            Excel::import(new TableImport(
                rules: [
                    'key' => ['required', 'string'],
                    'group' => ['required', 'string'],
                    'type' => ['required', 'string'],
                    'status' => ['nullable'],
                    'value' => ['nullable'],
                    'label' => ['nullable', 'string'],
                ],
                rowHandler: function (array $row) use ($userId): Setting {
                    $setting = Setting::withTrashed()->firstWhere('key', $row['key'])
                        ?? new Setting(['key' => $row['key']]);

                    if ($setting->trashed()) {
                        $setting->restore();
                    }

                    $setting->fill([
                        'group' => $row['group'],
                        'type' => $row['type'],
                        'label' => $row['label'] ?? null,
                        'status' => filter_var($row['status'] ?? true, FILTER_VALIDATE_BOOLEAN),
                    ]);

                    if (array_key_exists('value', $row) && $row['value'] !== null && (string) $row['value'] !== '') {
                        $setting->value = (string) $row['value'];
                    }

                    $setting->created_by ??= $userId;
                    $setting->updated_by = $userId;
                    $setting->save();

                    return $setting;
                },
            ), $this->importFile->getRealPath());
        } catch (ExcelValidationException $e) {
            $messages = collect($e->failures())
                ->map(fn ($failure) => __('Row').' '.$failure->row().': '.implode(', ', $failure->errors()))
                ->take(5)
                ->implode(' | ');

            $this->addError('importFile', $messages);

            return;
        }

        $this->reset('importFile');
        Toast::dispatch($this, 'success', __('Settings imported successfully.'));
        $this->dispatch('modal-close', name: 'setting-import');
    }

    /* ----------------------------------------------------------------- *
     *  Helpers
     * ----------------------------------------------------------------- */

    public function types(): array
    {
        return [
            Setting::TYPE_STRING => __('String (single line)'),
            Setting::TYPE_TEXT => __('Text (multi line)'),
            Setting::TYPE_BOOLEAN => __('Boolean (on/off)'),
            Setting::TYPE_NUMBER => __('Number'),
            Setting::TYPE_SELECT => __('Select (fixed choices)'),
            Setting::TYPE_IMAGE => __('Image path/URL'),
            Setting::TYPE_FILE => __('File path/URL'),
            Setting::TYPE_SECRET => __('Secret (encrypted)'),
            Setting::TYPE_JSON => __('JSON'),
        ];
    }

    /** @return array<string, string>|null */
    protected function parseOptions(): ?array
    {
        $text = trim((string) ($this->form['optionsText'] ?? ''));

        if ($text === '') {
            return null;
        }

        return collect(preg_split('/\r\n|\r|\n/', $text))
            ->map(fn (string $line) => trim($line))
            ->filter()
            ->mapWithKeys(function (string $line): array {
                [$value, $label] = array_pad(explode(':', $line, 2), 2, null);

                return [trim($value) => trim($label ?? $value)];
            })
            ->all();
    }
}; ?>

<div class="space-y-4 sm:space-y-6">
    <x-settings-nav active="all"/>

    {{-- Page header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex items-center gap-3">
            <h1 class="text-2xl font-bold tracking-tight">{{ __('Settings') }}</h1>
            <span class="text-sm text-muted-foreground">({{ $this->settings->total() }})</span>
        </div>

        <div class="flex flex-wrap items-center gap-2.5">
            <x-ui.button size="sm" class="h-9" x-data x-on:click="$store.modals.open('setting-form'); $wire.create()">
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('New setting') }}
            </x-ui.button>

            <div class="relative">
                <x-icon name="search" class="absolute left-2.5 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground pointer-events-none"/>
                <input
                    type="text"
                    wire:model.live.debounce.300ms="search"
                    placeholder="{{ __('Search settings…') }}"
                    class="h-9 w-full sm:w-64 rounded-md border border-input bg-transparent pl-8 pr-8 text-sm shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                @if ($search)
                    <button type="button" wire:click="$set('search', '')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-muted-foreground hover:text-foreground cursor-pointer">
                        <x-icon name="x" class="h-3.5 w-3.5"/>
                    </button>
                @endif
            </div>

            <select
                wire:model.live="tableFilters.group"
                class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All groups') }}</option>
                @foreach ($this->groups as $group)
                    <option value="{{ $group }}">{{ ucfirst($group) }}</option>
                @endforeach
            </select>

            <select
                wire:model.live="tableFilters.type"
                class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All types') }}</option>
                @foreach ($this->types() as $value => $label)
                    <option value="{{ $value }}">{{ $value }}</option>
                @endforeach
            </select>

            <select
                wire:model.live="tableFilters.status"
                class="h-9 rounded-md border border-input bg-transparent px-3 pr-8 text-sm shadow-xs outline-none cursor-pointer focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 [&>option]:bg-popover [&>option]:text-popover-foreground"
            >
                <option value="">{{ __('All statuses') }}</option>
                <option value="1">{{ __('Enabled') }}</option>
                <option value="0">{{ __('Disabled') }}</option>
            </select>

            <x-ui.button size="sm" variant="outline" class="h-9" wire:click="resetFilters">
                {{ __('Reset') }}
            </x-ui.button>

            {{-- Import --}}
            <x-ui.button size="sm" variant="outline" class="h-9" x-data x-on:click="$store.modals.open('setting-import')">
                <x-icon name="upload" class="h-4 w-4"/>
                {{ __('Import') }}
            </x-ui.button>

            {{-- Export --}}
            <x-ui.dropdown width="w-44" align="end">
                <x-slot:trigger>
                    <x-ui.button size="sm" variant="outline" class="h-9 gap-1.5">
                        <x-icon name="download" class="h-4 w-4"/>
                        {{ __('Export') }}
                        <x-icon name="chevron-down" class="h-3.5 w-3.5 text-muted-foreground"/>
                    </x-ui.button>
                </x-slot:trigger>
                <x-ui.dropdown.item icon="file-spreadsheet" wire:click="export('xlsx')">{{ __('Excel (.xlsx)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-text" wire:click="export('csv')">{{ __('CSV (.csv)') }}</x-ui.dropdown.item>
                <x-ui.dropdown.item icon="file-down" wire:click="export('pdf')">{{ __('PDF (.pdf)') }}</x-ui.dropdown.item>
            </x-ui.dropdown>
        </div>
    </div>

    {{-- Table (desktop) --}}
    <div class="hidden md:block rounded-lg border border-border bg-card">
        <div class="overflow-x-auto">
            <table class="w-full min-w-[900px]">
                <thead>
                    <tr class="border-b border-border">
                        <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground w-16">{{ __('Sl No') }}</th>
                        @php($columns = [['key', __('Key'), true], ['group', __('Group'), true], ['type', __('Type'), true], ['label', __('Label'), true], ['value', __('Value'), false], ['status', __('Status'), true], ['updated_at', __('Updated'), true]])
                        @foreach ($columns as [$field, $heading, $sortable])
                            <th class="px-4 py-3 text-left text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">
                                @if ($sortable)
                                    <button type="button" wire:click="sortBy('{{ $field }}')" class="inline-flex items-center gap-1 hover:text-foreground cursor-pointer">
                                        {{ $heading }}
                                        @if ($sortField === $field)
                                            <x-icon name="chevron-down" class="h-3.5 w-3.5 {{ $sortDirection === 'desc' ? 'rotate-90' : '-rotate-90' }}"/>
                                        @else
                                            <x-icon name="chevrons-up-down" class="h-3 w-3 opacity-40"/>
                                        @endif
                                    </button>
                                @else
                                    {{ $heading }}
                                @endif
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right text-[10px] font-semibold uppercase tracking-widest text-muted-foreground">{{ __('Actions') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($this->settings as $setting)
                        <tr class="border-b border-border last:border-b-0 hover:bg-secondary/30 transition-colors" wire:key="setting-{{ $setting->id }}">
                            <td class="px-4 py-3.5 text-sm text-muted-foreground font-mono">{{ (($this->settings->currentPage() - 1) * $this->settings->perPage()) + $loop->iteration }}</td>
                            <td class="px-4 py-3.5 text-sm font-mono">{{ $setting->key }}</td>
                            <td class="px-4 py-3.5">
                                <x-ui.badge color="secondary">{{ ucfirst($setting->group) }}</x-ui.badge>
                            </td>
                            <td class="px-4 py-3.5 text-xs text-muted-foreground">{{ $setting->type }}</td>
                            <td class="px-4 py-3.5 text-sm">{{ $setting->label ?? '—' }}</td>
                            <td class="px-4 py-3.5 text-sm text-muted-foreground max-w-64">
                                @if ($setting->type === \App\Models\Setting::TYPE_SECRET)
                                    <span class="font-mono">••••••••</span>
                                @elseif ($setting->type === \App\Models\Setting::TYPE_BOOLEAN)
                                    {{ $setting->typed() ? __('On') : __('Off') }}
                                @elseif ($setting->type === \App\Models\Setting::TYPE_IMAGE && $setting->value)
                                    <div class="flex items-center gap-2">
                                        <img
                                            src="{{ str_starts_with($setting->value, 'http') || str_starts_with($setting->value, '/') ? $setting->value : \Illuminate\Support\Facades\Storage::disk('public')->url($setting->value) }}"
                                            alt="{{ $setting->key }}"
                                            class="h-7 w-7 rounded object-cover border border-border shrink-0 bg-secondary/50"
                                            onerror="this.style.display='none'"
                                        />
                                        <span class="block truncate text-xs" title="{{ $setting->value }}">{{ $setting->value }}</span>
                                    </div>
                                @elseif ($setting->type === \App\Models\Setting::TYPE_FILE && $setting->value)
                                    <div class="flex items-center gap-1.5 text-xs">
                                        <x-icon name="file" class="h-3.5 w-3.5 text-muted-foreground shrink-0"/>
                                        <a
                                            href="{{ str_starts_with($setting->value, 'http') || str_starts_with($setting->value, '/') ? $setting->value : \Illuminate\Support\Facades\Storage::disk('public')->url($setting->value) }}"
                                            target="_blank"
                                            class="truncate hover:underline text-primary"
                                            title="{{ $setting->value }}"
                                        >
                                            {{ basename($setting->value) }}
                                        </a>
                                    </div>
                                @else
                                    <span class="block truncate">{{ \Illuminate\Support\Str::limit((string) ($setting->rawValue() ?? ''), 60) }}</span>
                                @endif
                            </td>
                            <td class="px-4 py-3.5">
                                <button type="button" wire:click="toggleStatus({{ $setting->id }})" wire:loading.attr="disabled" class="cursor-pointer" title="{{ $setting->status ? __('Disable') : __('Enable') }}">
                                    <x-ui.badge :color="$setting->status ? 'success' : 'secondary'">
                                        {{ $setting->status ? __('Enabled') : __('Disabled') }}
                                    </x-ui.badge>
                                </button>
                            </td>
                            <td class="px-4 py-3.5 text-xs text-muted-foreground">{{ $setting->updated_at?->format('d M Y') }}</td>
                            <td class="px-4 py-3.5 text-right">
                                <x-ui.dropdown width="w-40" align="end">
                                    <x-slot:trigger>
                                        <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-secondary transition-colors cursor-pointer" aria-label="{{ __('Actions') }}">
                                            <x-icon name="more-vertical" class="h-4 w-4 text-muted-foreground"/>
                                        </button>
                                    </x-slot:trigger>
                                    <x-ui.dropdown.item icon="pencil" wire:click="edit({{ $setting->id }})">{{ __('Edit') }}</x-ui.dropdown.item>
                                    <x-ui.dropdown.separator/>
                                    <x-ui.dropdown.item icon="trash-2" danger x-data x-on:click="$store.modals.open('setting-delete'); $wire.selectForDelete({{ $setting->id }})">
                                        {{ __('Delete') }}
                                    </x-ui.dropdown.item>
                                </x-ui.dropdown>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="px-4 py-12 text-center">
                                <div class="flex flex-col items-center gap-2 text-muted-foreground">
                                    <x-icon name="search-x" class="h-8 w-8"/>
                                    <p class="text-sm font-medium">{{ __('No settings found') }}</p>
                                    <p class="text-xs">{{ __('Try adjusting your search or filters.') }}</p>
                                </div>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{ $this->settings->links('partials.pagination') }}
    </div>

    {{-- Cards (mobile) --}}
    <div class="md:hidden space-y-3">
        @forelse ($this->settings as $setting)
            <div class="rounded-lg border border-border bg-card p-4" wire:key="setting-m-{{ $setting->id }}">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 flex items-start gap-2">
                        <span class="text-xs font-semibold text-muted-foreground px-1.5 py-0.5 rounded bg-secondary/50">#{{ (($this->settings->currentPage() - 1) * $this->settings->perPage()) + $loop->iteration }}</span>
                        <div class="min-w-0">
                            <p class="text-sm font-mono truncate">{{ $setting->key }}</p>
                            <p class="text-xs text-muted-foreground truncate">{{ $setting->label ?? '—' }}</p>
                        </div>
                    </div>
                    <x-ui.badge :color="$setting->status ? 'success' : 'secondary'">{{ $setting->status ? __('Enabled') : __('Disabled') }}</x-ui.badge>
                </div>
                @if ($setting->type === \App\Models\Setting::TYPE_IMAGE && $setting->value)
                    <div class="mt-2 flex items-center gap-2">
                        <img
                            src="{{ str_starts_with($setting->value, 'http') || str_starts_with($setting->value, '/') ? $setting->value : \Illuminate\Support\Facades\Storage::disk('public')->url($setting->value) }}"
                            alt="{{ $setting->key }}"
                            class="h-10 w-10 rounded object-cover border border-border shrink-0 bg-secondary/50"
                        />
                        <span class="text-xs text-muted-foreground truncate">{{ $setting->value }}</span>
                    </div>
                @elseif ($setting->type === \App\Models\Setting::TYPE_FILE && $setting->value)
                    <div class="mt-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                        <x-icon name="file" class="h-3.5 w-3.5 shrink-0"/>
                        <span class="truncate">{{ basename($setting->value) }}</span>
                    </div>
                @endif
                <div class="mt-3 flex items-center gap-2">
                    <x-ui.button size="sm" variant="outline" class="flex-1" wire:click="edit({{ $setting->id }})">{{ __('Edit') }}</x-ui.button>
                    <x-ui.button size="sm" variant="outline" class="text-destructive" x-data x-on:click="$store.modals.open('setting-delete'); $wire.selectForDelete({{ $setting->id }})">
                        {{ __('Delete') }}
                    </x-ui.button>
                </div>
            </div>
        @empty
            <div class="rounded-lg border border-border bg-card p-8 text-center text-muted-foreground">
                <p class="text-sm font-medium">{{ __('No settings found') }}</p>
            </div>
        @endforelse

        {{ $this->settings->links('partials.pagination') }}
    </div>

    {{-- Create / Edit modal --}}
    <x-ui.modal name="setting-form" :maxWidth="'max-w-lg'" :title="$editingId ? __('Edit setting') : __('New setting')" :description="__('Application setting stored as a typed key/value pair.')">
        <form wire:submit="save" class="space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="sm:col-span-2">
                    <x-ui.input wire:model="form.key" :label="__('Key') .' *'" placeholder="sms.otp_length" required/>
                    <p class="mt-1.5 text-xs text-muted-foreground">{{ __('Dot-separated, unique, e.g. general.site_name') }}</p>
                </div>
                <x-ui.input wire:model="form.group" :label="__('Group') .' *'" placeholder="general" required/>
                <div>
                    <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Type') }} *</label>
                    <x-ui.select wire:model.live="form.type" class="mt-2" :options="$this->types()" size="default"/>
                </div>
                <x-ui.input wire:model="form.label" :label="__('Label')" placeholder="OTP length"/>

                @if ($form['type'] === \App\Models\Setting::TYPE_SELECT)
                    <div class="sm:col-span-2">
                        <x-ui.textarea wire:model="form.optionsText" :label="__('Choices (one per line, value:Label)')" rows="3" placeholder="en:English{{ "\n" }}hi:Hindi"/>
                        @error('form.optionsText') <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p> @enderror
                    </div>
                @endif

                <div class="sm:col-span-2">
                    @if ($form['type'] === \App\Models\Setting::TYPE_TEXT || $form['type'] === \App\Models\Setting::TYPE_JSON)
                        <x-ui.textarea wire:model="form.value" :label="__('Value')" rows="3"/>
                    @elseif ($form['type'] === \App\Models\Setting::TYPE_BOOLEAN)
                        <x-ui.switch wire:model="form.value" :label="__('Enabled (value = on)')" :checked="(bool) $form['value']"/>
                    @elseif ($form['type'] === \App\Models\Setting::TYPE_IMAGE)
                        <div class="space-y-3">
                            <div>
                                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Image file') }}</label>
                                <input
                                    type="file"
                                    wire:model="settingFile"
                                    accept="image/*"
                                    class="mt-1.5 w-full text-sm border border-input rounded-md px-3 py-2 cursor-pointer file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:cursor-pointer"
                                />
                                @error('settingFile') <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p> @enderror
                                <div wire:loading wire:target="settingFile" class="mt-1.5 text-xs text-muted-foreground">{{ __('Uploading image…') }}</div>
                            </div>

                            @if ($settingFile)
                                <div class="flex items-center gap-3 p-2.5 rounded-md border border-border bg-secondary/20">
                                    <img src="{{ $settingFile->temporaryUrl() }}" alt="Preview" class="h-16 w-16 object-cover rounded-md border border-border shrink-0"/>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium truncate">{{ $settingFile->getClientOriginalName() }}</p>
                                        <p class="text-[10px] text-muted-foreground">{{ number_format($settingFile->getSize() / 1024, 1) }} KB</p>
                                        <span class="inline-block mt-1 text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">{{ __('New upload ready') }}</span>
                                    </div>
                                    <button type="button" wire:click="$set('settingFile', null)" class="text-xs text-destructive hover:underline cursor-pointer">
                                        {{ __('Remove') }}
                                    </button>
                                </div>
                            @elseif (!empty($form['value']))
                                <div class="flex items-center gap-3 p-2.5 rounded-md border border-border bg-secondary/20">
                                    <img
                                        src="{{ str_starts_with($form['value'], 'http') || str_starts_with($form['value'], '/') ? $form['value'] : \Illuminate\Support\Facades\Storage::disk('public')->url($form['value']) }}"
                                        alt="Current"
                                        class="h-16 w-16 object-cover rounded-md border border-border shrink-0 bg-secondary/50"
                                        onerror="this.style.display='none'"
                                    />
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium">{{ __('Current image') }}</p>
                                        <p class="text-[10px] text-muted-foreground truncate">{{ $form['value'] }}</p>
                                    </div>
                                </div>
                            @endif

                            <x-ui.input wire:model="form.value" :label="__('Or image path / URL')" placeholder="settings/logo.png or https://..."/>
                        </div>
                    @elseif ($form['type'] === \App\Models\Setting::TYPE_FILE)
                        <div class="space-y-3">
                            <div>
                                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('File') }}</label>
                                <input
                                    type="file"
                                    wire:model="settingFile"
                                    class="mt-1.5 w-full text-sm border border-input rounded-md px-3 py-2 cursor-pointer file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:cursor-pointer"
                                />
                                @error('settingFile') <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p> @enderror
                                <div wire:loading wire:target="settingFile" class="mt-1.5 text-xs text-muted-foreground">{{ __('Uploading file…') }}</div>
                            </div>

                            @if ($settingFile)
                                <div class="flex items-center gap-3 p-2.5 rounded-md border border-border bg-secondary/20">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-md bg-secondary text-foreground shrink-0">
                                        <x-icon name="file-text" class="h-5 w-5"/>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium truncate">{{ $settingFile->getClientOriginalName() }}</p>
                                        <p class="text-[10px] text-muted-foreground">{{ number_format($settingFile->getSize() / 1024, 1) }} KB</p>
                                        <span class="inline-block mt-1 text-[10px] text-emerald-600 dark:text-emerald-400 font-medium">{{ __('New upload ready') }}</span>
                                    </div>
                                    <button type="button" wire:click="$set('settingFile', null)" class="text-xs text-destructive hover:underline cursor-pointer">
                                        {{ __('Remove') }}
                                    </button>
                                </div>
                            @elseif (!empty($form['value']))
                                <div class="flex items-center gap-3 p-2.5 rounded-md border border-border bg-secondary/20">
                                    <div class="flex h-10 w-10 items-center justify-center rounded-md bg-secondary text-foreground shrink-0">
                                        <x-icon name="file" class="h-5 w-5"/>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-xs font-medium">{{ __('Current file') }}</p>
                                        <a
                                            href="{{ str_starts_with($form['value'], 'http') || str_starts_with($form['value'], '/') ? $form['value'] : \Illuminate\Support\Facades\Storage::disk('public')->url($form['value']) }}"
                                            target="_blank"
                                            class="text-[10px] text-primary hover:underline truncate block"
                                        >
                                            {{ $form['value'] }}
                                        </a>
                                    </div>
                                </div>
                            @endif

                            <x-ui.input wire:model="form.value" :label="__('Or file path / URL')" placeholder="settings/document.pdf or https://..."/>
                        </div>
                    @else
                        <x-ui.input
                            wire:model="form.value"
                            :label="__('Value')"
                            :type="$form['type'] === \App\Models\Setting::TYPE_SECRET ? 'password' : ($form['type'] === \App\Models\Setting::TYPE_NUMBER ? 'number' : 'text')"
                            :placeholder="$form['type'] === \App\Models\Setting::TYPE_SECRET && $editingId ? __('Leave blank to keep current value') : ''"
                            autocomplete="new-password"
                        />
                    @endif
                </div>

                <div class="sm:col-span-2">
                    <x-ui.switch wire:model="form.status" :label="__('Status')" :description="__('Disabled settings are ignored by the application.')" :checked="(bool) $form['status']"/>
                </div>
            </div>

            @if ($errors->any())
                <p class="text-xs text-destructive">{{ $errors->first() }}</p>
            @endif

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('setting-form')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit">{{ __('Save setting') }}</x-ui.button>
            </div>
        </form>
    </x-ui.modal>

    {{-- Delete confirmation --}}
    <x-ui.modal name="setting-delete" max-width="max-w-sm" :title="__('Delete setting')" :description="__('This action cannot be undone.')">
        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('setting-delete')">{{ __('Cancel') }}</x-ui.button>
            <x-ui.button variant="destructive" wire:click="deleteSelected">{{ __('Delete') }}</x-ui.button>
        </div>
    </x-ui.modal>

    {{-- Import modal --}}
    <x-ui.modal name="setting-import" :maxWidth="'max-w-md'" :title="__('Import settings')" :description="__('Upload an Excel (.xlsx) or CSV file with columns: Key, Value, Group, Type, Label, Status.')">
        <form wire:submit="import" class="space-y-4" enctype="multipart/form-data">
            <div>
                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('File') }} *</label>
                <input
                    type="file"
                    wire:model="importFile"
                    accept=".xlsx,.csv"
                    class="mt-2 w-full text-sm border border-input rounded-md px-3 py-2 cursor-pointer file:mr-3 file:rounded-md file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:cursor-pointer"
                />
                @error('importFile') <p class="mt-1.5 text-xs text-destructive">{{ $message }}</p> @enderror
                <div wire:loading wire:target="importFile" class="mt-2 text-xs text-muted-foreground">{{ __('Uploading…') }}</div>
            </div>

            <p class="text-xs text-muted-foreground">
                {{ __('Existing keys are updated, new keys are created. Secret values are never exported, so they stay untouched on import.') }}
                <button type="button" wire:click="downloadTemplate" class="underline hover:text-foreground cursor-pointer">{{ __('Download template') }}</button>
            </p>

            <div class="flex justify-end gap-2 pt-2">
                <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('setting-import')">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" wire:loading.attr="disabled" wire:target="import">
                    <x-icon name="upload" class="h-4 w-4" wire:loading.remove/>
                    {{ __('Import settings') }}
                </x-ui.button>
            </div>
        </form>
    </x-ui.modal>
</div>
