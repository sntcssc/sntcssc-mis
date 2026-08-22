<?php

namespace App\Support;

use Livewire\Component;

class Toast
{
    /**
     * Flash a toast to the session — rendered by <x-ui.toasts> on the next
     * page render. Use after redirects.
     */
    public static function add(string $type, string $message): void
    {
        session()->push('toasts', [$type, $message]);
    }

    public static function success(string $message): void
    {
        static::add('success', $message);
    }

    public static function error(string $message): void
    {
        static::add('error', $message);
    }

    public static function warning(string $message): void
    {
        static::add('warning', $message);
    }

    public static function info(string $message): void
    {
        static::add('info', $message);
    }

    /**
     * Show a toast immediately from a Livewire component without a reload:
     *   Toast::dispatch($this, 'success', __('Saved!'));
     */
    public static function dispatch(Component $component, string $type, string $message): void
    {
        $component->dispatch('toast', type: $type, message: $message);
    }
}
