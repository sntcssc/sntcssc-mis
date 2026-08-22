import './theme.js';

document.addEventListener('alpine:init', () => {
    Alpine.store('theme', {
        current: window.SntcsscTheme.get(),

        init() {
            window.addEventListener('theme-changed', (event) => {
                this.current = event.detail.theme;
            });
        },

        set(theme) {
            window.SntcsscTheme.set(theme);
        },

        toggle() {
            window.SntcsscTheme.toggle();
        },
    });

    Alpine.store('modals', {
        items: {},

        open(name) {
            this.items[name] = true;
            this.syncScrollLock();
        },

        close(name) {
            this.items[name] = false;
            this.syncScrollLock();
        },

        toggle(name) {
            this.items[name] = ! this.items[name];
            this.syncScrollLock();
        },

        isOpen(name) {
            return !! this.items[name];
        },

        anyOpen() {
            return Object.values(this.items).some(Boolean);
        },

        syncScrollLock() {
            document.body.classList.toggle('overflow-hidden', this.anyOpen());
        },
    });

    Alpine.store('toasts', {
        items: [],
        seq: 0,

        init() {
            window.addEventListener('toast', (event) => {
                this.add(event.detail.type ?? 'success', event.detail.message ?? '');
            });
        },

        bootstrap(toasts) {
            toasts.forEach(([type, message]) => this.add(type, message));
        },

        add(type, message) {
            const id = ++this.seq;
            this.items.push({ id, type, message });
            setTimeout(() => this.dismiss(id), 4200);
        },

        dismiss(id) {
            this.items = this.items.filter((toast) => toast.id !== id);
        },
    });

    // Bridge Livewire dispatches to the Alpine modal store:
    // $this->dispatch('modal-open', name: 'create-team')
    window.addEventListener('modal-open', (event) => {
        Alpine.store('modals').open(event.detail?.name);
    });

    window.addEventListener('modal-close', (event) => {
        Alpine.store('modals').close(event.detail?.name);
    });
});
