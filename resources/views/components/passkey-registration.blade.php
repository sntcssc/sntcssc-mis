@assets
@vite('resources/js/passkeys.js')
@endassets

<div
    x-data="{
        supported: false,
        showForm: false,
        name: '',
        loading: false,
        error: null,
        updateSupport() {
            this.supported = Boolean(window.Passkeys?.isSupported());
        },
        getDefaultPasskeyName() {
            const ua = navigator.userAgent;

            const browser = [
                { pattern: /Edg|Edge/, name: 'Edge' },
                { pattern: /OPR|Opera|OPiOS/, name: 'Opera' },
                { pattern: /Firefox|FxiOS/, name: 'Firefox' },
                { pattern: /Chrome|CriOS/, name: 'Chrome' },
                { pattern: /Safari/, name: 'Safari' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            const os = [
                { pattern: /iPhone/, name: 'iPhone' },
                { pattern: /iPad|Macintosh(?=.*Mobile)/, name: 'iPad' },
                { pattern: /Android/, name: 'Android' },
                { pattern: /Mac/, name: 'Mac' },
                { pattern: /Windows/, name: 'Windows' },
            ].find(({ pattern }) => pattern.test(ua))?.name;

            return [browser, os].filter(Boolean).join(' on ') || '';
        },
        init() {
            this.name = this.getDefaultPasskeyName();
            this.updateSupport();

            window.addEventListener('passkeys:ready', () => this.updateSupport(), { once: true });
        },
        async register() {
            if (!this.name.trim()) return;

            this.loading = true;
            this.error = null;

            try {
                await window.Passkeys.register({ name: this.name });
                this.name = '';
                this.showForm = false;
                await $wire.loadPasskeys();
            } catch (e) {
                if (e.constructor?.name !== 'UserCancelledError') {
                    this.error = e.message;
                }
            } finally {
                this.loading = false;
            }
        },
        cancel() {
            this.showForm = false;
            this.name = '';
            this.error = null;
        },
    }"
>
    <template x-if="!supported">
        <p class="text-sm text-muted-foreground">{{ __('Passkeys are not supported in this browser.') }}</p>
    </template>

    <template x-if="supported && !showForm">
        <div>
            <button
                type="button"
                class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-xs hover:bg-primary/90 cursor-pointer transition-colors"
                x-on:click="showForm = true"
            >
                <x-icon name="plus" class="h-4 w-4"/>
                {{ __('Add passkey') }}
            </button>
        </div>
    </template>

    <template x-if="supported && showForm">
        <div class="space-y-4 rounded-lg border border-border bg-secondary/30 p-4">
            <div class="space-y-2">
                <label class="text-[11px] font-semibold uppercase tracking-wider text-muted-foreground">{{ __('Passkey name') }}</label>
                <input
                    type="text"
                    x-model="name"
                    placeholder="{{ __('e.g., MacBook Pro, iPhone') }}"
                    x-on:keydown.enter.prevent="register()"
                    x-ref="passkeyNameInput"
                    x-init="$nextTick(() => $refs.passkeyNameInput?.focus())"
                    class="flex h-9 w-full rounded-md border border-input bg-transparent px-3 shadow-xs outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
            </div>
            <p class="text-sm text-muted-foreground">{{ __('Give this passkey a name to help you identify it later.') }}</p>

            <p x-show="error" x-text="error" x-cloak class="text-sm text-destructive"></p>

            <div class="flex gap-2">
                <button
                    type="button"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow-xs hover:bg-primary/90 cursor-pointer transition-colors disabled:pointer-events-none disabled:opacity-50"
                    x-on:click="register()"
                    x-bind:disabled="loading || !name.trim()"
                >
                    <span x-show="!loading">{{ __('Register passkey') }}</span>
                    <span x-show="loading" x-cloak>{{ __('Registering...') }}</span>
                </button>
                <button
                    type="button"
                    class="inline-flex h-9 items-center justify-center gap-2 rounded-md px-4 text-sm font-medium hover:bg-accent cursor-pointer transition-colors"
                    x-on:click="cancel()"
                >
                    {{ __('Cancel') }}
                </button>
            </div>
        </div>
    </template>
</div>
