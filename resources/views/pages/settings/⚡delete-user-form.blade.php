<?php

use App\Models\Setting;
use Livewire\Attributes\Computed;
use Livewire\Component;

new class extends Component {
    #[Computed]
    public function canDeleteAccount(): bool
    {
        return (bool) Setting::get('system.allow_account_deletion', false);
    }
}; ?>

<div>
    @if ($this->canDeleteAccount)
        <section class="mt-8">
            <div class="rounded-xl border border-rose-500/20 bg-rose-500/5 p-5">
                <h3 class="text-sm font-semibold">{{ __('Delete account') }}</h3>
                <p class="text-xs text-muted-foreground mt-0.5">{{ __('Delete your account and all of its resources') }}</p>

                <div class="mt-4">
                    <x-ui.button variant="destructive" x-data x-on:click="$store.modals.open('confirm-user-deletion')" data-test="delete-user-button">
                        {{ __('Delete account') }}
                    </x-ui.button>

                    <livewire:pages::settings.delete-user-modal />
                </div>
            </div>
        </section>
    @endif
</div>

