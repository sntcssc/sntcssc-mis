<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<x-ui.modal name="confirm-user-deletion" max-width="max-w-lg" :title="__('Are you sure you want to delete your account?')">
    <form wire:submit="deleteUser" class="space-y-5">
        <p class="text-sm text-muted-foreground">
            {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
        </p>

        <x-ui.password wire:model="password" :label="__('Password') .' *'" required :error="$errors->first('password')"/>

        <div class="flex justify-end gap-2">
            <x-ui.button variant="outline" type="button" x-data x-on:click="$store.modals.close('confirm-user-deletion')">
                {{ __('Cancel') }}
            </x-ui.button>

            <x-ui.button variant="destructive" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </x-ui.button>
        </div>
    </form>
</x-ui.modal>
