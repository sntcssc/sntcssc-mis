@php
    try {
        $activeLanguages = \App\Models\Language::activeCached();
        $locales = $activeLanguages->mapWithKeys(fn ($lang) => [
            $lang->code => [
                'native' => $lang->native_name,
                'label' => $lang->name,
                'flag' => $lang->flag,
            ]
        ])->all();
    } catch (\Throwable) {
        $locales = [];
    }

    if (empty($locales)) {
        $locales = [
            'en' => ['native' => 'English', 'label' => 'English', 'flag' => '🇮🇳'],
            'hi' => ['native' => 'हिन्दी', 'label' => 'Hindi', 'flag' => '🇮🇳'],
            'bn' => ['native' => 'বাংলা', 'label' => 'Bengali', 'flag' => '🇮🇳'],
        ];
    }

    $current = app()->getLocale();
@endphp

<x-ui.dropdown width="w-56" align="end" offset="mt-1">
    <x-slot:trigger>
        <button
            type="button"
            class="flex h-9 items-center gap-1.5 rounded-md border border-border bg-background shadow-xs hover:bg-accent dark:bg-input/30 dark:hover:bg-input/50 px-2.5 text-xs font-medium cursor-pointer transition-colors"
            aria-label="{{ __('Change language') }}"
            title="{{ __('Change language') }}"
        >
            <x-icon name="globe" class="h-3.5 w-3.5 text-muted-foreground"/>
            <span class="uppercase hidden xs:inline">{{ $current }}</span>
            <x-icon name="chevron-down" class="h-3 w-3 text-muted-foreground"/>
        </button>
    </x-slot:trigger>

    <x-ui.dropdown.label>{{ __('Language') }}</x-ui.dropdown.label>
    <x-ui.dropdown.separator/>

    @foreach ($locales as $code => $locale)
        <form method="POST" action="{{ route('locale.switch') }}">
            @csrf
            <input type="hidden" name="locale" value="{{ $code }}"/>

            <button
                type="submit"
                class="flex w-full items-center justify-between gap-2 rounded-md px-2 py-1.5 text-sm text-popover-foreground hover:bg-secondary transition-colors cursor-pointer"
            >
                <span class="flex flex-col text-start">
                    <span class="text-sm font-medium flex items-center gap-1.5">
                        @if (!empty($locale['flag']))
                            <span class="text-base leading-none">{{ $locale['flag'] }}</span>
                        @endif
                        <span>{{ $locale['native'] }}</span>
                    </span>
                    <span class="text-xs text-muted-foreground">{{ $locale['label'] }} · {{ $code }}</span>
                </span>

                @if ($current === $code)
                    <span class="flex h-4 w-4 shrink-0 items-center justify-center rounded-full bg-primary/15">
                        <x-icon name="check" class="h-2.5 w-2.5 text-primary" stroke-width="3"/>
                    </span>
                @endif
            </button>
        </form>
    @endforeach
</x-ui.dropdown>
