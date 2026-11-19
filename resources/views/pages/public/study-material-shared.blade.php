@extends('layouts.public')

@section('title', $material->title . ' - Study Material')

@section('content')
<div class="max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 py-10">
    <!-- Back / Navigation Breadcrumb -->
    <div class="mb-6 flex items-center justify-between">
        <div class="flex items-center gap-2 text-xs sm:text-sm text-muted-foreground">
            <a href="{{ route('home') }}" class="hover:text-foreground transition-colors flex items-center gap-1">
                <x-icon name="home" class="w-3.5 h-3.5" />
                <span>{{ __('Home') }}</span>
            </a>
            <span>/</span>
            <span class="font-medium text-foreground">{{ __('Shared Study Material') }}</span>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center px-2.5 py-1 rounded-full text-xs font-semibold bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 mr-1.5 animate-pulse"></span>
                {{ __('Verified Academic Resource') }}
            </span>
        </div>
    </div>

    <!-- Main Header Card -->
    <div class="bg-card text-card-foreground rounded-2xl border border-border shadow-sm p-6 sm:p-8 mb-8 relative overflow-hidden">
        <div class="absolute top-0 right-0 w-64 h-64 bg-primary/5 rounded-full blur-3xl pointer-events-none -mr-20 -mt-20"></div>

        <div class="relative z-10">
            <div class="flex flex-wrap items-center gap-2 mb-3">
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-lg text-xs font-semibold bg-primary/10 text-primary border border-primary/20">
                    <x-icon name="{{ $material->material_type_icon }}" class="w-3.5 h-3.5" />
                    {{ $material->material_type_label }}
                </span>
                @if($material->subject)
                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-muted text-muted-foreground border border-border">
                        <x-icon name="book-open" class="w-3 h-3 mr-1" />
                        {{ $material->subject }}
                    </span>
                @endif
                @if($material->topic)
                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-muted text-muted-foreground border border-border">
                        <x-icon name="tag" class="w-3 h-3 mr-1" />
                        {{ $material->topic }}
                    </span>
                @endif
            </div>

            <h1 class="text-2xl sm:text-3xl font-bold tracking-tight text-foreground mb-4">
                {{ $material->title }}
            </h1>

            <!-- Metadata Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 border-t border-border/60 text-xs sm:text-sm">
                <div>
                    <span class="block text-muted-foreground text-xs">{{ __('Course') }}</span>
                    <span class="font-semibold text-foreground">{{ $material->course?->name }}</span>
                </div>
                <div>
                    <span class="block text-muted-foreground text-xs">{{ __('Batches') }}</span>
                    <span class="font-semibold text-foreground">{{ $material->batches_summary }}</span>
                </div>
                <div>
                    <span class="block text-muted-foreground text-xs">{{ __('Files Attached') }}</span>
                    <span class="font-semibold text-foreground">{{ $material->files->count() }} ({{ $material->formatted_total_size }})</span>
                </div>
                <div>
                    <span class="block text-muted-foreground text-xs">{{ __('Total Downloads') }}</span>
                    <span class="font-semibold text-foreground">{{ number_format($material->download_count) }}</span>
                </div>
            </div>
        </div>
    </div>

    <!-- Description / Overview Section -->
    @if($material->description)
        <div class="bg-card rounded-2xl border border-border shadow-sm p-6 sm:p-8 mb-8">
            <h2 class="text-lg font-semibold text-foreground mb-3 flex items-center gap-2">
                <x-icon name="info" class="w-5 h-5 text-primary" />
                {{ __('Overview & Instructions') }}
            </h2>
            <div class="prose dark:prose-invert max-w-none text-muted-foreground text-sm leading-relaxed whitespace-pre-line">
                {{ $material->description }}
            </div>
        </div>
    @endif

    <!-- Downloadable Attachments Section -->
    @if($material->files->isNotEmpty())
        <div class="bg-card rounded-2xl border border-border shadow-sm p-6 sm:p-8 mb-8">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-semibold text-foreground flex items-center gap-2">
                    <x-icon name="paperclip" class="w-5 h-5 text-primary" />
                    {{ __('Attached Study Files & Notes') }}
                </h2>
                <span class="text-xs font-medium text-muted-foreground">
                    {{ $material->files->count() }} {{ __('file(s)') }}
                </span>
            </div>

            <div class="space-y-3">
                @foreach($material->files as $file)
                    <div class="flex flex-col sm:flex-row sm:items-center justify-between p-4 rounded-xl border border-border bg-muted/30 hover:bg-muted/50 transition-colors gap-3">
                        <div class="flex items-center gap-3 min-w-0">
                            <div class="w-10 h-10 rounded-lg bg-primary/10 text-primary flex items-center justify-center shrink-0">
                                <x-icon name="{{ $file->file_icon }}" class="w-5 h-5" />
                            </div>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-foreground truncate" title="{{ $file->file_name }}">
                                    {{ $file->file_name }}
                                </p>
                                <div class="flex items-center gap-2 text-xs text-muted-foreground mt-0.5">
                                    <span class="uppercase font-semibold tracking-wider px-1.5 py-0.5 rounded bg-muted text-foreground/70 text-[10px]">
                                        {{ $file->file_type ?: 'FILE' }}
                                    </span>
                                    <span>&bull;</span>
                                    <span>{{ $file->formatted_size }}</span>
                                    <span>&bull;</span>
                                    <span>{{ $file->download_count }} {{ __('downloads') }}</span>
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 shrink-0">
                            <a href="{{ route('study-materials.public-download', ['token' => $material->share_token, 'fileIndex' => $file->id]) }}"
                               class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-primary text-primary-foreground hover:bg-primary/90 text-xs font-semibold shadow-sm transition-all">
                                <x-icon name="download" class="w-4 h-4" />
                                <span>{{ __('Download File') }}</span>
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <!-- External Resource Link -->
    @if($material->external_url)
        <div class="bg-card rounded-2xl border border-border shadow-sm p-6 sm:p-8 mb-8">
            <h2 class="text-lg font-semibold text-foreground mb-3 flex items-center gap-2">
                <x-icon name="external-link" class="w-5 h-5 text-primary" />
                {{ __('Online Class / External Resource') }}
            </h2>
            <p class="text-sm text-muted-foreground mb-4">
                {{ __('This study material includes an external video recording, online class link, or collaborative document.') }}
            </p>
            <a href="{{ $material->external_url }}" target="_blank" rel="noopener noreferrer"
               class="inline-flex items-center gap-2 px-5 py-2.5 rounded-xl bg-blue-600 hover:bg-blue-700 text-white text-sm font-semibold shadow transition-all">
                <x-icon name="external-link" class="w-4 h-4" />
                <span>{{ __('Open Resource Link') }}</span>
            </a>
        </div>
    @endif

    <!-- Embedded Rich Content / Notes -->
    @if($material->content)
        <div class="bg-card rounded-2xl border border-border shadow-sm p-6 sm:p-8 mb-8">
            <h2 class="text-lg font-semibold text-foreground mb-4 flex items-center gap-2">
                <x-icon name="file-text" class="w-5 h-5 text-primary" />
                {{ __('Lecture Notes & Text Content') }}
            </h2>
            <div class="prose dark:prose-invert max-w-none text-foreground text-sm leading-relaxed p-4 rounded-xl bg-muted/20 border border-border/40 whitespace-pre-line font-mono text-xs sm:text-sm">
                {{ $material->content }}
            </div>
        </div>
    @endif

    <!-- Institution Footer Callout -->
    <div class="text-center py-6 text-xs text-muted-foreground border-t border-border">
        <p class="font-medium text-foreground">Satyendranath Tagore Civil Services Study Centre (SNTCSSC)</p>
        <p class="mt-1">Government of West Bengal &bull; Official Academic Portal</p>
    </div>
</div>
@endsection
