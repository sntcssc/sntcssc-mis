<?php

use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Appearance settings')] class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <h2 class="sr-only">{{ __('Appearance settings') }}</h2>

    <x-pages::settings.layout :heading="__('Appearance')" :subheading="__('Update the appearance settings for your account')">
        <div class="rounded-xl border border-border bg-card p-5" x-data>
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
    </x-pages::settings.layout>
</section>
