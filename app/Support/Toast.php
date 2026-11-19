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

    public static function success(mixed $componentOrMessage, ?string $message = null): void
    {
        if ($componentOrMessage instanceof Component && $message !== null) {
            static::dispatch($componentOrMessage, 'success', $message);
        } else {
            static::add('success', (string) $componentOrMessage);
        }
    }

    public static function error(mixed $componentOrMessage, ?string $message = null): void
    {
        if ($componentOrMessage instanceof Component && $message !== null) {
            static::dispatch($componentOrMessage, 'error', $message);
        } else {
            static::add('error', (string) $componentOrMessage);
        }
    }

    public static function warning(mixed $componentOrMessage, ?string $message = null): void
    {
        if ($componentOrMessage instanceof Component && $message !== null) {
            static::dispatch($componentOrMessage, 'warning', $message);
        } else {
            static::add('warning', (string) $componentOrMessage);
        }
    }

    public static function info(mixed $componentOrMessage, ?string $message = null): void
    {
        if ($componentOrMessage instanceof Component && $message !== null) {
            static::dispatch($componentOrMessage, 'info', $message);
        } else {
            static::add('info', (string) $componentOrMessage);
        }
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
