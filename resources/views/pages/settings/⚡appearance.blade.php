<?php

use App\Services\GlobalSearchService;
use App\Support\Toast;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component {
    public string $viewportMode = 'inherit';

    public function mount(): void
    {
        $user = Auth::user();
        $this->viewportMode = $user?->getPreference('default_fullscreen') ?? 'inherit';
    }

    public function setViewportMode(string $mode): void
    {
        $this->viewportMode = $mode;
        $user = Auth::user();
        if ($user) {
            $val = $mode === 'inherit' ? null : $mode;
            $user->setPreference('default_fullscreen', $val);
            Toast::dispatch($this, 'success', __('Viewport display preference saved.'));
        }
    }
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Appearance settings') }}</h2>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the theme and display appearance settings for your account')">
        <div class="space-y-6">
            {{-- Theme Selection Card --}}
            <div class="rounded-xl border border-border bg-card p-5" x-data>
                <h3 class="text-sm font-semibold mb-3">{{ __('Theme Mode') }}</h3>
                <div class="grid grid-cols-3 gap-1 rounded-lg border border-border p-1">
                    <button
                        type="button"
                        x-on:click="$store.theme.set('light')"
                        class="flex flex-col items-center gap-1.5 rounded-md px-2 py-3 text-sm transition-colors cursor-pointer"
                        :class="$store.theme.current === 'light' ? 'bg-secondary text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
                    >
                        <x-icon name="sun" class="h-5 w-5"/>
                        {{ __('Light') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="$store.theme.set('dark')"
                        class="flex flex-col items-center gap-1.5 rounded-md px-2 py-3 text-sm transition-colors cursor-pointer"
                        :class="$store.theme.current === 'dark' ? 'bg-secondary text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
                    >
                        <x-icon name="moon" class="h-5 w-5"/>
                        {{ __('Dark') }}
                    </button>
                    <button
                        type="button"
                        x-on:click="$store.theme.set('system')"
                        class="flex flex-col items-center gap-1.5 rounded-md px-2 py-3 text-sm transition-colors cursor-pointer"
                        :class="$store.theme.current === 'system' ? 'bg-secondary text-foreground font-medium' : 'text-muted-foreground hover:text-foreground'"
                    >
                        <x-icon name="monitor" class="h-5 w-5"/>
                        {{ __('System') }}
                    </button>
                </div>

                <div class="mt-4 rounded-lg bg-secondary/50 px-4 py-3">
                    <p class="text-xs text-muted-foreground">
                        {{ __('Select "System" to follow your operating system preference. Your choice is remembered on this device.') }}
                    </p>
                </div>
            </div>

            {{-- Fullscreen Viewport Mode Card --}}
            <div class="rounded-xl border border-border bg-card p-5" x-data="{
                setLocal(mode) {
                    if (mode === 'inherit') {
                        localStorage.removeItem('admin_default_fullscreen');
                    } else {
                        localStorage.setItem('admin_default_fullscreen', mode);
                    }
                }
            }">
                <div class="flex items-center gap-2 mb-1">
                    <x-icon name="maximize-2" class="h-4 w-4 text-primary"/>
                    <h3 class="text-sm font-semibold">{{ __('Default Admin Viewport Mode') }}</h3>
                </div>
                <p class="text-xs text-muted-foreground mb-4">
                    {{ __('Choose how the admin dashboard opens by default in your browser window.') }}
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                    <button
                        type="button"
                        wire:click="setViewportMode('inherit')"
                        x-on:click="setLocal('inherit')"
                        class="flex flex-col items-start p-3.5 rounded-xl border text-left transition-all cursor-pointer {{ $viewportMode === 'inherit' ? 'border-primary bg-primary/5 ring-1 ring-primary/30 text-foreground' : 'border-border bg-card hover:bg-secondary/40 text-muted-foreground' }}"
                    >
                        <span class="text-xs font-semibold text-foreground">{{ __('System Default') }}</span>
                        <span class="text-[11px] text-muted-foreground mt-0.5">{{ __('Follow institution settings') }}</span>
                    </button>

                    <button
                        type="button"
                        wire:click="setViewportMode('normal')"
                        x-on:click="setLocal('normal')"
                        class="flex flex-col items-start p-3.5 rounded-xl border text-left transition-all cursor-pointer {{ $viewportMode === 'normal' ? 'border-primary bg-primary/5 ring-1 ring-primary/30 text-foreground' : 'border-border bg-card hover:bg-secondary/40 text-muted-foreground' }}"
                    >
                        <span class="text-xs font-semibold text-foreground">{{ __('Normal Window') }}</span>
                        <span class="text-[11px] text-muted-foreground mt-0.5">{{ __('Standard browser window') }}</span>
                    </button>

                    <button
                        type="button"
                        wire:click="setViewportMode('fullscreen')"
                        x-on:click="setLocal('fullscreen')"
                        class="flex flex-col items-start p-3.5 rounded-xl border text-left transition-all cursor-pointer {{ $viewportMode === 'fullscreen' ? 'border-primary bg-primary/5 ring-1 ring-primary/30 text-foreground' : 'border-border bg-card hover:bg-secondary/40 text-muted-foreground' }}"
                    >
                        <span class="text-xs font-semibold text-foreground">{{ __('Full Screen Mode') }}</span>
                        <span class="text-[11px] text-muted-foreground mt-0.5">{{ __('Auto-expand on launch') }}</span>
                    </button>
                </div>
            </div>
        </div>
    </x-pages::settings.layout>
</section>
