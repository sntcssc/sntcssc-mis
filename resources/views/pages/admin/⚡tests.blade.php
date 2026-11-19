<?php

use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('Redirecting...')] class extends Component {
    public function mount()
    {
        return $this->redirectRoute('admin.tests.index');
    }
}; ?>

<div class="p-8 text-center text-muted-foreground">
    <p>{{ __('Redirecting to Examinations Management...') }}</p>
</div>
