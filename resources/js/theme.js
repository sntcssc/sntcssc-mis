/**
 * Theme manager — light / dark / system with localStorage persistence.
 * Replaces Flux's appearance system ($flux.appearance / @fluxAppearance).
 *
 * Exposes an Alpine store registered from app.js:
 *   Alpine.store('theme', { current, set(theme), toggle() })
 * Emits window events 'theme-changed' so segmented controls stay in sync.
 */
(function () {
    const STORAGE_KEY = 'theme';

    function storedTheme() {
        try {
            return localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return null;
        }
    }

    function systemPrefersDark() {
        return window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function resolvedTheme() {
        const theme = storedTheme();

        if (theme === 'dark' || theme === 'light') {
            return theme;
        }

        return systemPrefersDark() ? 'dark' : 'light';
    }

    function applyTheme() {
        const isDark = resolvedTheme() === 'dark';

        document.documentElement.classList.toggle('dark', isDark);
        document.documentElement.style.colorScheme = isDark ? 'dark' : 'light';
    }

    window.SntcsscTheme = {
        get() {
            return storedTheme() || 'system';
        },
        set(theme) {
            try {
                if (theme === 'system') {
                    localStorage.removeItem(STORAGE_KEY);
                } else {
                    localStorage.setItem(STORAGE_KEY, theme);
                }
            } catch (e) {
                /* private mode — class toggle still works for this page */
            }

            // Mirror the choice into a cookie so the server can pre-render the
            // dark class — wire:navigate then swaps to a page that already
            // matches the selected theme (no flash).
            document.cookie = 'theme=' + encodeURIComponent(theme)
                + ';max-age=31536000;path=/;samesite=lax';

            applyTheme();
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme: this.get() } }));
        },
        toggle() {
            this.set(resolvedTheme() === 'dark' ? 'light' : 'dark');
        },
        resolved: resolvedTheme,
        apply: applyTheme,
    };

    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if ((storedTheme() || 'system') === 'system') {
            applyTheme();
            window.dispatchEvent(new CustomEvent('theme-changed', { detail: { theme: 'system' } }));
        }
    });

    // wire:navigate swaps the <html> attributes with the server-rendered ones,
    // which don't know the stored theme — so the .dark class would be wiped on
    // every SPA navigation. Re-apply the theme once navigation completes.
    ['alpine:navigated', 'livewire:navigated'].forEach((eventName) => {
        document.addEventListener(eventName, () => applyTheme());
    });
})();
