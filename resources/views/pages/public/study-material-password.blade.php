@extends('layouts.public')

@section('title', 'Password Protected Material')

@section('content')
<div class="max-w-md mx-auto px-4 py-16">
    <div class="bg-card text-card-foreground rounded-2xl border border-border shadow-lg p-6 sm:p-8 text-center">
        <div class="w-14 h-14 rounded-2xl bg-primary/10 text-primary mx-auto flex items-center justify-center mb-4">
            <x-icon name="lock" class="w-7 h-7" />
        </div>

        <h1 class="text-xl font-bold text-foreground mb-2">
            {{ __('Password Protected Resource') }}
        </h1>
        <p class="text-sm text-muted-foreground mb-6">
            {{ __('This study material is protected by an access password set by the instructor. Please enter the password to view and download.') }}
        </p>

        @if($error)
            <div class="mb-4 p-3 rounded-xl bg-destructive/10 text-destructive text-sm font-medium border border-destructive/20 text-left">
                {{ $error }}
            </div>
        @endif

        <form method="POST" action="{{ route('study-materials.public-share', ['token' => $material->share_token]) }}" class="space-y-4">
            @csrf
            <div>
                <input type="password" name="password" required autofocus placeholder="{{ __('Enter access password...') }}"
                       class="w-full px-4 py-2.5 rounded-xl border border-border bg-background text-foreground text-sm focus:ring-2 focus:ring-primary focus:outline-none" />
            </div>

            <button type="submit" class="w-full py-2.5 rounded-xl bg-primary text-primary-foreground font-semibold text-sm hover:bg-primary/90 shadow transition-all">
                {{ __('Unlock & View Material') }}
            </button>
        </form>
    </div>
</div>
@endsection
