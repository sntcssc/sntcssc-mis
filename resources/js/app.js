import './theme.js';
import './echo.js';
import { registerChatAndMediaComponents } from './chat-and-media.js';
import { registerMeetingComponents } from './meeting.js';

function extractEventPayload(detail) {
    if (detail === null || detail === undefined) return {};
    if (Array.isArray(detail)) {
        return detail[0] || {};
    }
    if (typeof detail === 'object') {
        if (detail[0] && typeof detail[0] === 'object') {
            return detail[0];
        }
        return detail;
    }
    return { value: detail };
}

let modalBridgeBound = false;
function bindModalBridges() {
    if (modalBridgeBound) return;
    modalBridgeBound = true;

    // Bridge Livewire dispatches to the Alpine modal store:
    // $this->dispatch('modal-open', name: 'create-team')
    window.addEventListener('modal-open', (event) => {
        const payload = extractEventPayload(event.detail);
        const name = payload.name || (typeof event.detail === 'string' ? event.detail : null);
        if (name) {
            const alpine = window.Alpine || (window.Livewire && window.Livewire.Alpine);
            try {
                alpine?.store('modals')?.open(name);
            } catch (e) {}
        }
    });

    window.addEventListener('modal-close', (event) => {
        const payload = extractEventPayload(event.detail);
        const name = payload.name || (typeof event.detail === 'string' ? event.detail : null);
        if (name) {
            const alpine = window.Alpine || (window.Livewire && window.Livewire.Alpine);
            try {
                alpine?.store('modals')?.close(name);
            } catch (e) {}
        }
    });
}

function initAlpineStores() {
    const alpine = window.Alpine || (window.Livewire && window.Livewire.Alpine);
    if (!alpine || typeof alpine.store !== 'function') return;

    try {
        if (!alpine.store('theme')) {
            alpine.store('theme', {
                current: window.SntcsscTheme ? window.SntcsscTheme.get() : 'system',

                init() {
                    window.addEventListener('theme-changed', (event) => {
                        const payload = extractEventPayload(event.detail);
                        this.current = payload.theme || event.detail?.theme || payload.value || 'system';
                    });
                },

                set(theme) {
                    if (window.SntcsscTheme) {
                        window.SntcsscTheme.set(theme);
                    }
                },

                toggle() {
                    if (window.SntcsscTheme) {
                        window.SntcsscTheme.toggle();
                    }
                },
            });
        }
    } catch (e) {}

    try {
        if (!alpine.store('modals')) {
            alpine.store('modals', {
                items: {},

                open(name) {
                    if (!name) return;
                    this.items[name] = true;
                    this.syncScrollLock();
                },

                close(name) {
                    if (!name) return;
                    this.items[name] = false;
                    this.syncScrollLock();
                },

                toggle(name) {
                    if (!name) return;
                    this.items[name] = !this.items[name];
                    this.syncScrollLock();
                },

                isOpen(name) {
                    return !!this.items[name];
                },

                anyOpen() {
                    return Object.values(this.items).some(Boolean);
                },

                syncScrollLock() {
                    document.body.classList.toggle('overflow-hidden', this.anyOpen());
                },
            });
        }
    } catch (e) {}

    try {
        if (!alpine.store('toasts')) {
            alpine.store('toasts', {
                items: [],
                seq: 0,

                init() {
                    window.addEventListener('toast', (event) => {
                        const payload = extractEventPayload(event.detail);
                        this.add(payload.type || event.detail?.type || 'success', payload.message || event.detail?.message || '');
                    });
                },

                bootstrap(toasts) {
                    if (Array.isArray(toasts)) {
                        toasts.forEach(([type, message]) => this.add(type, message));
                    }
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
        }
    } catch (e) {}

    try {
        if (!alpine.store('websocket')) {
            alpine.store('websocket', {
                isConfigured: window.WebSocketState ? window.WebSocketState.isConfigured : false,
                connected: window.WebSocketState ? window.WebSocketState.isConnected : false,
                status: window.WebSocketState ? window.WebSocketState.status : 'uninitialized',
                error: window.WebSocketState ? window.WebSocketState.error : null,

                init() {
                    window.addEventListener('websocket-status-changed', (event) => {
                        const payload = extractEventPayload(event.detail);
                        this.isConfigured = !!payload.isConfigured;
                        this.connected = !!payload.isConnected;
                        this.status = payload.status || 'disconnected';
                        this.error = payload.error || null;
                    });
                },

                reconnect() {
                    window.reconnectWebSocket?.();
                },
            });
        }
    } catch (e) {}

    bindModalBridges();
}

// Pre-register components immediately
registerChatAndMediaComponents();
registerMeetingComponents();
initAlpineStores();

// Lifecycle bindings for both standalone Alpine and Livewire-bundled Alpine
['alpine:init', 'alpine:initialized', 'livewire:init', 'livewire:initialized'].forEach((evt) => {
    document.addEventListener(evt, () => {
        registerChatAndMediaComponents();
        registerMeetingComponents();
        initAlpineStores();
    });
});

document.addEventListener('livewire:navigated', () => {
    registerChatAndMediaComponents();
    registerMeetingComponents();
    initAlpineStores();
});

/**
 * Echo exposes an expressive API for subscribing to channels and listening
 * for events that are broadcast by Laravel. Echo and event broadcasting
 * allow your team to quickly build robust real-time web applications.
 */
