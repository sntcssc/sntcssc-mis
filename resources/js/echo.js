import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

/**
 * Global WebSocket Connection State
 */
window.WebSocketState = {
    isConfigured: false,
    isConnected: false,
    status: 'uninitialized', // 'connected' | 'connecting' | 'disconnected' | 'unavailable' | 'failed' | 'unconfigured'
    driver: null,
    cluster: null,
    error: null,
    host: null,
    port: null,
    scheme: 'http',
};

/**
 * Dispatch WebSocket status updates to window and sync with Alpine store if present.
 */
function updateWebSocketState(updates = {}) {
    Object.assign(window.WebSocketState, updates);

    // Sync with Alpine store if Alpine is initialized
    if (window.Alpine && window.Alpine.store && window.Alpine.store('websocket')) {
        const store = window.Alpine.store('websocket');
        store.isConfigured = window.WebSocketState.isConfigured;
        store.connected = window.WebSocketState.isConnected;
        store.status = window.WebSocketState.status;
        store.error = window.WebSocketState.error;
    }

    try {
        window.dispatchEvent(new CustomEvent('websocket-status-changed', {
            detail: { ...window.WebSocketState },
        }));
    } catch (e) {
        console.debug('WebSocket status dispatch error:', e);
    }
}

/**
 * Null/Mock Echo Channel for environments without active WebSockets.
 * Allows Livewire Echo listeners to register cleanly without throwing console errors.
 */
class NullEchoChannel {
    constructor(name) {
        this.name = name;
        this.listeners = {};
    }

    listen(event, callback) {
        return this;
    }

    stopListening(event, callback) {
        return this;
    }

    listenForWhisper(event, callback) {
        return this;
    }

    stopListeningForWhisper(event, callback) {
        return this;
    }

    whisper(eventName, data) {
        return this;
    }

    notification(callback) {
        return this;
    }

    stopListeningForNotification(callback) {
        return this;
    }

    here(callback) {
        return this;
    }

    joining(callback) {
        return this;
    }

    leaving(callback) {
        return this;
    }

    error(callback) {
        return this;
    }

    subscribed(callback) {
        return this;
    }
}

/**
 * Null/Mock Echo Client providing an identical API surface to Laravel Echo.
 */
class NullEcho {
    constructor() {
        this.options = {};
        this.channels = {};
        this.connector = {
            channels: {},
            options: {},
            socketId: () => '',
            pusher: {
                connection: {
                    state: 'unavailable',
                    bind: () => {},
                    unbind: () => {},
                    connect: () => {},
                    disconnect: () => {},
                },
            },
        };
    }

    channel(name) {
        if (!this.channels[name]) {
            this.channels[name] = new NullEchoChannel(name);
        }
        return this.channels[name];
    }

    private(name) {
        const privateName = name.startsWith('private-') ? name : `private-${name}`;
        return this.channel(privateName);
    }

    encryptedPrivate(name) {
        const encName = name.startsWith('private-encrypted-') ? name : `private-encrypted-${name}`;
        return this.channel(encName);
    }

    join(name) {
        const presenceName = name.startsWith('presence-') ? name : `presence-${name}`;
        return this.channel(presenceName);
    }

    leave(name) {
        delete this.channels[name];
        delete this.channels[`private-${name}`];
        delete this.channels[`presence-${name}`];
        return this;
    }

    leaveChannel(name) {
        return this.leave(name);
    }

    leaveAllChannels() {
        this.channels = {};
        return this;
    }

    listen(channel, event, callback) {
        return this.channel(channel).listen(event, callback);
    }

    disconnect() {
        updateWebSocketState({ isConnected: false, status: 'disconnected' });
        return this;
    }

    connect() {
        return this;
    }

    socketId() {
        return '';
    }

    registerVuexStore() {
        return this;
    }

    registerAxiosRequestInterceptor() {
        return this;
    }
}

// Global Reconnect helper
window.reconnectWebSocket = function () {
    if (window.Echo && window.Echo.connector && window.Echo.connector.pusher) {
        try {
            updateWebSocketState({ status: 'connecting' });
            window.Echo.connector.pusher.connect();
        } catch (e) {
            console.warn('Manual WebSocket reconnect error:', e);
        }
    }
};

// Broadcaster resolution (Reverb vs Pusher)
// Checks DOM meta tags injected by server first, then falls back to Vite env variables
const getMetaContent = (name) => {
    if (typeof document === 'undefined') return '';
    return document.querySelector(`meta[name="${name}"]`)?.getAttribute('content')?.trim() || '';
};

const serverDriver = getMetaContent('broadcast-driver');
const metaReverbKey = getMetaContent('reverb-key');
const metaPusherKey = getMetaContent('pusher-key');

const viteDriver = import.meta.env.VITE_BROADCASTER;
const viteReverbKey = import.meta.env.VITE_REVERB_APP_KEY;
const vitePusherKey = import.meta.env.VITE_PUSHER_APP_KEY;

// Determine driver: 'pusher' or 'reverb'
let activeDriver = (serverDriver || viteDriver || '').toLowerCase();

if (!activeDriver || activeDriver === 'null' || activeDriver === 'log') {
    if (metaPusherKey || (vitePusherKey && !viteReverbKey)) {
        activeDriver = 'pusher';
    } else if (metaReverbKey || viteReverbKey) {
        activeDriver = 'reverb';
    }
}

const reverbKey = metaReverbKey || viteReverbKey || '';
const pusherKey = metaPusherKey || vitePusherKey || '';

const isPusher = activeDriver === 'pusher' && pusherKey !== '';
const isReverb = (activeDriver === 'reverb' || (!isPusher && reverbKey !== '')) && reverbKey !== '';
const csrfToken = (typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') : '') || '';

if (isPusher) {
    try {
        const pusherCluster = getMetaContent('pusher-cluster') || import.meta.env.VITE_PUSHER_APP_CLUSTER || 'mt1';
        const pusherScheme = getMetaContent('pusher-scheme') || import.meta.env.VITE_PUSHER_SCHEME || (typeof window !== 'undefined' && window.location?.protocol === 'https:' ? 'https' : 'http');
        const isSecure = pusherScheme === 'https';

        window.Echo = new Echo({
            broadcaster: 'pusher',
            key: pusherKey.trim(),
            cluster: pusherCluster.trim(),
            forceTLS: isSecure,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            },
        });

        updateWebSocketState({
            isConfigured: true,
            status: 'connecting',
            driver: 'pusher',
            cluster: pusherCluster.trim(),
            host: `ws-${pusherCluster}.pusher.com`,
            port: isSecure ? 443 : 80,
            scheme: isSecure ? 'https' : 'http',
        });

        setupPusherConnectionListeners();
    } catch (e) {
        console.debug('Pusher Echo initialization fallback to NullEcho:', e);
        fallbackToNullEcho(e.message);
    }
} else if (isReverb) {
    try {
        const isSecure = (getMetaContent('reverb-scheme') || import.meta.env.VITE_REVERB_SCHEME || (typeof window !== 'undefined' && window.location?.protocol === 'https:' ? 'https' : 'http')) === 'https';
        let host = getMetaContent('reverb-host') || import.meta.env.VITE_REVERB_HOST || (typeof window !== 'undefined' ? window.location?.hostname : 'localhost');
        if (typeof window !== 'undefined' && window.location?.hostname) {
            const browserHost = window.location.hostname;
            const isLocalClient = browserHost === 'localhost' || browserHost === '127.0.0.1' || browserHost === '::1';
            if (!isLocalClient && (host === 'localhost' || host === '127.0.0.1')) {
                host = browserHost;
            }
        }
        const portRaw = getMetaContent('reverb-port') || import.meta.env.VITE_REVERB_PORT;
        const port = portRaw ? parseInt(portRaw, 10) : (isSecure ? 443 : 80);

        window.Echo = new Echo({
            broadcaster: 'reverb',
            key: reverbKey.trim(),
            wsHost: host,
            wsPort: port,
            wssPort: port,
            forceTLS: isSecure,
            enabledTransports: ['ws', 'wss'],
            disableStats: true,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': csrfToken,
                    'Accept': 'application/json',
                },
            },
        });

        updateWebSocketState({
            isConfigured: true,
            status: 'connecting',
            driver: 'reverb',
            host: host,
            port: port,
            scheme: isSecure ? 'https' : 'http',
        });

        setupPusherConnectionListeners();
    } catch (e) {
        console.debug('Laravel Reverb initialization fallback to NullEcho:', e);
        fallbackToNullEcho(e.message);
    }
} else {
    // Provide safe NullEcho when running in pure polling mode or without WebSocket keys
    fallbackToNullEcho('WebSocket keys not configured in environment or driver set to null.');
}

function fallbackToNullEcho(errorReason) {
    window.Echo = new NullEcho();
    updateWebSocketState({
        isConfigured: false,
        isConnected: false,
        status: 'unconfigured',
        error: errorReason,
    });
}

function setupPusherConnectionListeners() {
    // Monitor connection state transitions for both Pusher and Reverb (which uses pusher-js)
    if (window.Echo?.connector?.pusher?.connection) {
        const conn = window.Echo.connector.pusher.connection;

        conn.bind('state_change', (states) => {
            const current = states.current; // 'connected' | 'connecting' | 'unavailable' | 'failed' | 'disconnected'
            const isConnected = current === 'connected';

            updateWebSocketState({
                isConnected: isConnected,
                status: current,
                error: isConnected ? null : window.WebSocketState.error,
            });
        });

        conn.bind('connected', () => {
            updateWebSocketState({
                isConnected: true,
                status: 'connected',
                error: null,
            });
        });

        conn.bind('disconnected', () => {
            updateWebSocketState({
                isConnected: false,
                status: 'disconnected',
            });
        });

        conn.bind('unavailable', () => {
            updateWebSocketState({
                isConnected: false,
                status: 'unavailable',
                error: 'WebSocket server unavailable or unreachable.',
            });
        });

        conn.bind('error', (err) => {
            updateWebSocketState({
                isConnected: false,
                status: 'failed',
                error: err?.error?.data?.message || err?.message || 'WebSocket connection error',
            });
        });
    }
}

// Initialize Alpine store on alpine:init or DOMContentLoaded
function setupAlpineWebSocketStore() {
    if (window.Alpine && typeof window.Alpine.store === 'function') {
        if (!window.Alpine.store('websocket')) {
            window.Alpine.store('websocket', {
                isConfigured: window.WebSocketState.isConfigured,
                connected: window.WebSocketState.isConnected,
                status: window.WebSocketState.status,
                error: window.WebSocketState.error,
                reconnect() {
                    window.reconnectWebSocket();
                },
            });
        }
    }
}

document.addEventListener('alpine:init', setupAlpineWebSocketStore);
if (document.readyState === 'complete' || document.readyState === 'interactive') {
    setupAlpineWebSocketStore();
} else {
    document.addEventListener('DOMContentLoaded', setupAlpineWebSocketStore);
}

export default window.Echo;
