@props(['name'])

@php
    $icon = \App\Support\LucideIcons::get($name);
    $strokeWidth = $attributes->get('stroke-width', 2);
@endphp

@if ($icon)
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="{{ $strokeWidth }}"
        stroke-linecap="round"
        stroke-linejoin="round"
        aria-hidden="true"
        {{ $attributes->except('stroke-width') }}
    >{!! $icon !!}</svg>
@else
    <span class="inline-block size-4 rounded-sm bg-secondary" aria-hidden="true" {{ $attributes }}></span>
@endif
