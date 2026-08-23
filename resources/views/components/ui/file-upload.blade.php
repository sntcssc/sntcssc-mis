@props([
    'label' => null,
    'type' => 'image',
    'accept' => null,
    'maxSize' => '5MB',
    'value' => null,
    'file' => null,
    'error' => null,
    'hint' => null,
    'folder' => null,
    'allowRemove' => true,
    'removeAction' => null,
])

@php
    $accept = $accept ?? ($type === 'image' ? 'image/png,image/jpeg,image/webp,image/svg+xml,image/gif' : '*');
    $isImage = $type === 'image';
    $fileUrl = null;

    if ($value) {
        $fileUrl = str_starts_with($value, 'http://') || str_starts_with($value, 'https://') || str_starts_with($value, '//')
            ? $value
            : \Illuminate\Support\Facades\Storage::disk('public')->url($value);
    }
@endphp

<div {{ $attributes->only(['class', 'data-test'])->merge(['class' => 'space-y-2']) }}>
    @if ($label)
        <div class="flex items-center justify-between">
            <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">
                {{ $label }}@if ($attributes->get('required')) <span class="text-destructive">*</span>@endif
            </label>
            <span class="text-[10px] text-muted-foreground">Max {{ $maxSize }}</span>
        </div>
    @endif

    {{-- Dropzone / File Input --}}
    <div
        x-data="{ isDragging: false }"
        x-on:dragover.prevent="isDragging = true"
        x-on:dragleave.prevent="isDragging = false"
        x-on:drop="isDragging = false"
        class="relative flex flex-col items-center justify-center rounded-lg border-2 border-dashed transition-all p-4 text-center"
        :class="isDragging ? 'border-primary bg-primary/5' : 'border-border hover:border-muted-foreground/50 bg-card/50'"
    >
        <input
            type="file"
            accept="{{ $accept }}"
            {{ $attributes->except(['class', 'data-test', 'label', 'type', 'accept', 'maxSize', 'value', 'file', 'error', 'hint', 'folder', 'allowRemove', 'removeAction']) }}
            class="absolute inset-0 z-10 h-full w-full opacity-0 cursor-pointer"
        />

        <div class="flex flex-col items-center gap-1.5 pointer-events-none">
            <div class="flex h-9 w-9 items-center justify-center rounded-full bg-secondary text-muted-foreground">
                @if ($isImage)
                    <x-icon name="images" class="h-4 w-4"/>
                @else
                    <x-icon name="upload" class="h-4 w-4"/>
                @endif
            </div>
            <div class="text-xs font-medium">
                <span class="text-primary hover:underline cursor-pointer">{{ __('Click to upload') }}</span>
                <span class="text-muted-foreground">{{ __(' or drag & drop') }}</span>
            </div>
            <p class="text-[10px] text-muted-foreground">
                {{ $isImage ? __('PNG, JPG, WEBP, SVG up to ') . $maxSize : __('Any file up to ') . $maxSize }}
                @if ($folder)
                    <span class="text-muted-foreground/70">({{ $folder }})</span>
                @endif
            </p>
        </div>

        {{-- Uploading spinner indicator --}}
        @if ($attributes->wire('model')->value())
            <div wire:loading wire:target="{{ $attributes->wire('model')->value() }}" class="absolute inset-0 z-20 flex items-center justify-center rounded-lg bg-background/80 backdrop-blur-xs">
                <div class="flex items-center gap-2 text-xs font-medium text-primary">
                    <x-icon name="refresh-cw" class="h-4 w-4 animate-spin"/>
                    <span>{{ __('Uploading…') }}</span>
                </div>
            </div>
        @endif
    </div>

    {{-- Live Preview for New Upload --}}
    @if ($file)
        <div class="flex items-center justify-between gap-3 rounded-lg border border-primary/20 bg-primary/5 p-3">
            <div class="flex items-center gap-3 min-w-0">
                @if ($isImage && method_exists($file, 'temporaryUrl'))
                    <img
                        src="{{ $file->temporaryUrl() }}"
                        alt="Preview"
                        class="h-12 w-12 rounded-md object-cover border border-emerald-500/30 shrink-0 bg-background"
                    />
                @else
                    <div class="flex h-10 w-10 items-center justify-center rounded-md bg-primary/10 text-primary shrink-0">
                        <x-icon name="file-text" class="h-5 w-5"/>
                    </div>
                @endif

                <div class="min-w-0 flex-1 text-left">
                    <div class="flex items-center gap-2">
                        <p class="text-xs font-semibold text-foreground truncate">{{ method_exists($file, 'getClientOriginalName') ? $file->getClientOriginalName() : 'Uploaded file' }}</p>
                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[9px] font-semibold bg-primary/15 text-primary">{{ __('Ready to save') }}</span>
                    </div>
                    <p class="text-[10px] text-muted-foreground">
                        {{ method_exists($file, 'getSize') ? \App\Services\FileUploadService::humanSize($file->getSize()) : '' }}
                    </p>
                </div>
            </div>

            @if ($allowRemove)
                <button
                    type="button"
                    @if ($removeAction)
                        wire:click="{{ $removeAction }}"
                    @elseif ($attributes->wire('model')->value())
                        wire:click="$set('{{ $attributes->wire('model')->value() }}', null)"
                    @endif
                    class="rounded-md p-1 text-muted-foreground hover:bg-destructive/10 hover:text-destructive transition-colors cursor-pointer"
                    title="{{ __('Remove selected file') }}"
                >
                    <x-icon name="trash-2" class="h-4 w-4"/>
                </button>
            @endif
        </div>

    {{-- Current Stored File Preview --}}
    @elseif ($value)
        <div class="flex items-center justify-between gap-3 rounded-lg border border-border bg-secondary/30 p-2.5">
            <div class="flex items-center gap-3 min-w-0">
                @if ($isImage)
                    <img
                        src="{{ $fileUrl }}"
                        alt="Current"
                        class="h-10 w-10 rounded-md object-contain border border-border shrink-0 bg-background/50 p-0.5"
                        onerror="this.style.display='none'"
                    />
                @else
                    <div class="flex h-9 w-9 items-center justify-center rounded-md bg-secondary text-muted-foreground shrink-0">
                        <x-icon name="file-text" class="h-4 w-4"/>
                    </div>
                @endif

                <div class="min-w-0 flex-1 text-left">
                    <div class="flex items-center gap-1.5">
                        <span class="text-[10px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Current') }}</span>
                    </div>
                    <a
                        href="{{ $fileUrl }}"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-xs text-primary hover:underline truncate block font-mono"
                        title="{{ $value }}"
                    >
                        {{ basename($value) }}
                    </a>
                </div>
            </div>

            <a
                href="{{ $fileUrl }}"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-1 rounded-md border border-border px-2 py-1 text-[11px] text-muted-foreground hover:text-foreground hover:bg-secondary transition-colors"
            >
                <x-icon name="external-link" class="h-3 w-3"/>
                <span>{{ __('View') }}</span>
            </a>
        </div>
    @endif

    {{-- Error message --}}
    @if ($error)
        <p class="text-xs text-destructive flex items-center gap-1">
            <x-icon name="alert-circle" class="h-3.5 w-3.5 shrink-0"/>
            <span>{{ $error }}</span>
        </p>
    @elseif ($hint)
        <p class="text-xs text-muted-foreground">{{ $hint }}</p>
    @endif
</div>
