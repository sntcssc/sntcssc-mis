// Standalone Alpine Component for Online Meetings & Video Conferencing
// Decoupled from Realtime Live Chat & 1-on-1 WebRTC calling
// Optimized for Laravel 13, Livewire 4, and seamless SPA (wire:navigate) lifecycle

function extractEventData(detail) {
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

function createSafeAudioContext() {
    try {
        const AudioCtx = window.AudioContext || window.webkitAudioContext;
        if (!AudioCtx) return null;
        const ctx = new AudioCtx();
        if (ctx.state === 'suspended') {
            const resumeCtx = () => {
                ctx.resume().catch(() => {});
                window.removeEventListener('click', resumeCtx);
                window.removeEventListener('keydown', resumeCtx);
                window.removeEventListener('touchstart', resumeCtx);
            };
            window.addEventListener('click', resumeCtx, { once: true });
            window.addEventListener('keydown', resumeCtx, { once: true });
            window.addEventListener('touchstart', resumeCtx, { once: true });
        }
        return ctx;
    } catch (e) {
        console.debug('Meeting AudioContext suppressed:', e);
        return null;
    }
}

/**
 * Diagnoses media hardware availability, device presence, and permission state for meeting rooms.
 */
async function diagnoseHardwareMediaAvailability(options = {}) {
    const checkAudio = options.audio !== false;
    const checkVideo = options.video !== false;

    const result = {
        supported: typeof navigator !== 'undefined' && !!navigator.mediaDevices && typeof navigator.mediaDevices.getUserMedia === 'function',
        hasAudioInput: false,
        hasVideoInput: false,
        hasAudioOutput: false,
        audioPermission: 'prompt',
        videoPermission: 'prompt',
        devices: [],
        errorType: null,
        errorMessage: null,
    };

    if (!result.supported) {
        result.errorType = 'unsupported';
        result.errorMessage = 'Media capture is not supported in this browser (requires HTTPS or localhost).';
        return result;
    }

    try {
        if (typeof navigator.permissions !== 'undefined' && typeof navigator.permissions.query === 'function') {
            if (checkAudio) {
                try {
                    const status = await navigator.permissions.query({ name: 'microphone' });
                    result.audioPermission = status.state || 'unknown';
                } catch (e) {}
            }
            if (checkVideo) {
                try {
                    const status = await navigator.permissions.query({ name: 'camera' });
                    result.videoPermission = status.state || 'unknown';
                } catch (e) {}
            }
        }
    } catch (e) {}

    try {
        if (typeof navigator.mediaDevices.enumerateDevices === 'function') {
            const devices = await navigator.mediaDevices.enumerateDevices();
            result.devices = devices;
            result.hasAudioInput = devices.some((d) => d.kind === 'audioinput');
            result.hasVideoInput = devices.some((d) => d.kind === 'videoinput');
            result.hasAudioOutput = devices.some((d) => d.kind === 'audiooutput');
        }
    } catch (e) {
        console.debug('Device enumeration check notice:', e);
    }

    return result;
}

/**
 * Acquires local media stream with smart multi-tier fallback:
 * 1. Tries ideal requested constraints.
 * 2. If video fails (e.g. camera is busy in another app like Zoom/Teams or overconstrained),
 *    automatically falls back to audio-only and reports fallback reason.
 * 3. Categorizes error codes (busy, permission_denied, not_found, overconstrained).
 */
async function acquireMediaStreamWithFallback(options = {}) {
    const isAudioOnly = options.video === false;
    const requestedAudio = options.audio !== false;
    const requestedVideo = !isAudioOnly;
    const audioDeviceId = options.audioDeviceId || null;
    const videoDeviceId = options.videoDeviceId || null;

    const audioConstraints = requestedAudio
        ? (audioDeviceId
            ? { deviceId: { exact: audioDeviceId }, echoCancellation: true, noiseSuppression: true, autoGainControl: true }
            : { echoCancellation: true, noiseSuppression: true, autoGainControl: true })
        : false;

    const videoConstraints = requestedVideo
        ? (videoDeviceId
            ? { deviceId: { exact: videoDeviceId }, width: { ideal: 1280 }, height: { ideal: 720 } }
            : { width: { ideal: 1280 }, height: { ideal: 720 } })
        : false;

    let stream = null;
    let fallbackUsed = false;
    let fallbackReason = null;
    let error = null;

    if (typeof navigator === 'undefined' || !navigator.mediaDevices || typeof navigator.mediaDevices.getUserMedia !== 'function') {
        return {
            stream: null,
            fallbackUsed: false,
            fallbackReason: null,
            error: {
                name: 'NotSupportedError',
                type: 'unsupported',
                message: 'Media capture is not supported in this browser or protocol.'
            }
        };
    }

    // Attempt primary acquisition
    try {
        stream = await navigator.mediaDevices.getUserMedia({
            audio: audioConstraints,
            video: videoConstraints,
        });
        return {
            stream,
            fallbackUsed: false,
            fallbackReason: null,
            error: null,
            hasAudio: stream.getAudioTracks().length > 0,
            hasVideo: stream.getVideoTracks().length > 0,
        };
    } catch (primaryErr) {
        console.warn('Primary getUserMedia attempt notice:', primaryErr);

        const errName = primaryErr.name || '';
        const isDeviceBusy = (errName === 'NotReadableError' || errName === 'TrackStartError' || errName === 'AbortError');
        const isPermissionDenied = (errName === 'NotAllowedError' || errName === 'PermissionDeniedError');
        const isNotFound = (errName === 'NotFoundError' || errName === 'DevicesNotFoundError');
        const isOverconstrained = (errName === 'OverconstrainedError' || errName === 'ConstraintNotSatisfiedError');

        // If video was requested and audio was also requested, attempt graceful fallback to audio-only
        if (requestedVideo && requestedAudio) {
            try {
                if (isOverconstrained) {
                    try {
                        stream = await navigator.mediaDevices.getUserMedia({
                            audio: audioConstraints,
                            video: true,
                        });
                        return {
                            stream,
                            fallbackUsed: true,
                            fallbackReason: 'relaxed_video_constraints',
                            error: null,
                            hasAudio: stream.getAudioTracks().length > 0,
                            hasVideo: stream.getVideoTracks().length > 0,
                        };
                    } catch (relaxedErr) {}
                }

                stream = await navigator.mediaDevices.getUserMedia({
                    audio: audioConstraints,
                    video: false,
                });

                fallbackUsed = true;
                fallbackReason = isDeviceBusy
                    ? 'camera_busy'
                    : (isNotFound ? 'camera_not_found' : (isPermissionDenied ? 'camera_permission_denied' : 'camera_unavailable'));

                return {
                    stream,
                    fallbackUsed,
                    fallbackReason,
                    error: null,
                    hasAudio: stream.getAudioTracks().length > 0,
                    hasVideo: false,
                };
            } catch (audioFallbackErr) {
                console.error('Audio fallback also failed:', audioFallbackErr);
                error = audioFallbackErr;
            }
        } else {
            error = primaryErr;
        }

        let errorType = 'unknown';
        let errorMessage = primaryErr.message || 'Could not access media devices.';

        if (errName === 'NotAllowedError' || errName === 'PermissionDeniedError') {
            errorType = 'permission_denied';
            errorMessage = isAudioOnly
                ? 'Microphone permission denied. Please allow microphone access in your browser settings.'
                : 'Camera/Microphone permission denied. Please allow media device access in your browser settings.';
        } else if (errName === 'NotFoundError' || errName === 'DevicesNotFoundError') {
            errorType = 'not_found';
            errorMessage = isAudioOnly
                ? 'No microphone hardware found on this device.'
                : 'No camera or microphone hardware found on this device.';
        } else if (errName === 'NotReadableError' || errName === 'TrackStartError' || errName === 'AbortError') {
            errorType = 'busy';
            errorMessage = isAudioOnly
                ? 'Microphone is currently in use by another application (e.g., Zoom/Teams/Skype). Please close other apps and retry.'
                : 'Camera or microphone is currently in use by another application (e.g., Zoom/Teams/Skype). Please close other apps and retry.';
        } else if (errName === 'OverconstrainedError') {
            errorType = 'overconstrained';
            errorMessage = 'The requested device resolution or hardware constraint is not supported by your device.';
        }

        return {
            stream: null,
            fallbackUsed,
            fallbackReason,
            error: {
                name: errName || 'MediaError',
                type: errorType,
                message: errorMessage,
                raw: primaryErr,
            }
        };
    }
}

function stopMediaTracks(stream) {
    if (!stream) return;
    try {
        if (typeof stream.getTracks === 'function') {
            stream.getTracks().forEach((track) => {
                try {
                    track.enabled = false;
                    track.stop();
                } catch (e) {}
            });
        }
    } catch (e) {
        console.warn('Error stopping meeting media tracks:', e);
    }
}

// Global registry for audio elements awaiting autoplay gesture resolution
window._pendingAudioSinks = window._pendingAudioSinks || new Set();

function resumeAllAudioSinks() {
    if (window._pendingAudioSinks && window._pendingAudioSinks.size > 0) {
        window._pendingAudioSinks.forEach((audioEl) => {
            if (audioEl && typeof audioEl.play === 'function') {
                audioEl.play().then(() => {
                    window._pendingAudioSinks.delete(audioEl);
                }).catch(() => {});
            }
        });
    }
}

// Global interaction listeners to unlock autoplay on any user interaction
if (typeof window !== 'undefined') {
    ['click', 'touchstart', 'keydown', 'pointerdown'].forEach((evt) => {
        window.addEventListener(evt, resumeAllAudioSinks, { passive: true });
    });
}

function ensureMeetingAudioSink(elementId, stream) {
    if (!stream || typeof document === 'undefined') return null;
    let container = document.getElementById('webrtc-meeting-audio-sink');
    if (!container) {
        container = document.createElement('div');
        container.id = 'webrtc-meeting-audio-sink';
        container.style.position = 'fixed';
        container.style.top = '-9999px';
        container.style.left = '-9999px';
        container.style.width = '1px';
        container.style.height = '1px';
        container.style.opacity = '0';
        container.style.pointerEvents = 'none';
        document.body.appendChild(container);
    }
    let audioEl = document.getElementById(elementId);
    if (!audioEl) {
        audioEl = document.createElement('audio');
        audioEl.id = elementId;
        audioEl.autoplay = true;
        audioEl.playsInline = true;
        audioEl.volume = 1.0;
        container.appendChild(audioEl);
    }

    if (typeof stream.getAudioTracks === 'function') {
        stream.getAudioTracks().forEach((track) => {
            track.enabled = true;
        });
    }

    if (audioEl.srcObject !== stream) {
        audioEl.srcObject = stream;
    }

    audioEl.muted = false;
    const playPromise = audioEl.play();
    if (playPromise !== undefined) {
        playPromise.catch((e) => {
            console.debug('Meeting audio playback autoplay awaiting user gesture for ' + elementId + ':', e);
            window._pendingAudioSinks.add(audioEl);
        });
    }
    return audioEl;
}

function removeMeetingAudioSink(elementId) {
    if (typeof document === 'undefined') return;
    const audioEl = document.getElementById(elementId);
    if (audioEl) {
        try {
            window._pendingAudioSinks.delete(audioEl);
            audioEl.srcObject = null;
            audioEl.pause();
            audioEl.remove();
        } catch (e) {}
    }
}

function toSessionDescription(sdpData, defaultType = 'offer') {
    if (!sdpData) return null;
    if (sdpData instanceof RTCSessionDescription) return sdpData;
    if (typeof sdpData === 'string') {
        return new RTCSessionDescription({ type: defaultType, sdp: sdpData });
    }
    if (typeof sdpData === 'object') {
        if (sdpData.sdp && typeof sdpData.sdp === 'object') {
            return toSessionDescription(sdpData.sdp, defaultType);
        }
        const type = sdpData.type || defaultType;
        const sdp = typeof sdpData.sdp === 'string' ? sdpData.sdp : '';
        if (sdp) {
            return new RTCSessionDescription({ type, sdp });
        }
        try {
            return new RTCSessionDescription(sdpData);
        } catch (e) {}
    }
    return null;
}

async function addSafeIceCandidate(pc, candidate) {
    if (!pc || !candidate) return;
    try {
        if (typeof candidate === 'object') {
            await pc.addIceCandidate(new RTCIceCandidate(candidate));
        } else if (typeof candidate === 'string') {
            await pc.addIceCandidate(new RTCIceCandidate({ candidate }));
        }
    } catch (e) {
        try {
            await pc.addIceCandidate(candidate);
        } catch (err) {
            console.debug('Meeting ICE candidate notice:', err);
        }
    }
}

/**
 * Deterministically find transceiver for kind ('audio' or 'video') without guessing.
 */
function getTransceiverByKind(pc, kind) {
    if (!pc || typeof pc.getTransceivers !== 'function') return null;
    const list = pc.getTransceivers();
    return list.find((t, idx) =>
        (t.receiver?.track?.kind === kind) ||
        (t.sender?.track?.kind === kind) ||
        (kind === 'audio' ? (t.mid === '0' || idx === 0) : (t.mid === '1' || idx === 1))
    ) || null;
}

function ensureDeterministicTransceivers(pc, stream = null) {
    if (!pc || typeof pc.getTransceivers !== 'function') return { audio: null, video: null };
    
    let audioTransceiver = getTransceiverByKind(pc, 'audio');
    let videoTransceiver = getTransceiverByKind(pc, 'video');

    const audioTrack = stream ? stream.getAudioTracks()[0] : null;
    const videoTrack = stream ? stream.getVideoTracks()[0] : null;

    if (!audioTransceiver) {
        try {
            const init = { direction: 'sendrecv' };
            if (stream) init.streams = [stream];
            audioTransceiver = audioTrack ? pc.addTransceiver(audioTrack, init) : pc.addTransceiver('audio', init);
        } catch (e) {
            try { audioTransceiver = pc.addTransceiver('audio', { direction: 'sendrecv' }); } catch (err) {}
        }
    } else {
        if (audioTrack && audioTransceiver.sender) {
            try { audioTransceiver.sender.replaceTrack(audioTrack).catch(() => {}); } catch (e) {}
        }
        try { audioTransceiver.direction = 'sendrecv'; } catch (e) {}
    }

    if (!videoTransceiver) {
        try {
            const init = { direction: 'sendrecv' };
            if (stream) init.streams = [stream];
            videoTransceiver = videoTrack ? pc.addTransceiver(videoTrack, init) : pc.addTransceiver('video', init);
        } catch (e) {
            try { videoTransceiver = pc.addTransceiver('video', { direction: 'sendrecv' }); } catch (err) {}
        }
    } else {
        if (videoTrack && videoTransceiver.sender) {
            try { videoTransceiver.sender.replaceTrack(videoTrack).catch(() => {}); } catch (e) {}
        }
        try { videoTransceiver.direction = 'sendrecv'; } catch (e) {}
    }

    return { audio: audioTransceiver, video: videoTransceiver };
}

export function meetingRoomAlpine(config = {}) {
    return {
        inPreJoinLobby: config.inPreJoinLobby !== undefined ? !!config.inPreJoinLobby : true,
        layoutMode: 'grid', // 'grid', 'speaker', 'sidebar'
        pinnedUserId: null,
        spotlightUserId: config.spotlightUserId || null,
        spotlightUserName: config.spotlightUserName || '',
        micMuted: false,
        videoOff: false,
        screenSharing: false,
        showEmojiMenu: false,
        showEndMeetingMenu: false,
        isFullscreen: false,
        soundMuted: false,
        websocketConnected: window.WebSocketState ? window.WebSocketState.isConnected : false,
        websocketStatus: window.WebSocketState ? window.WebSocketState.status : 'uninitialized',
        localStream: null,
        screenStream: null,
        permissionError: null,
        floatingEmojis: [],
        peers: {},
        peerStates: {},
        localAudioLevel: 0,
        isLocalSpeaking: false,
        localAudioCtx: null,
        localAnalyser: null,
        meetingAudioFrame: null,
        audioInputs: [],
        videoInputs: [],
        audioOutputs: [],
        selectedAudioInput: '',
        selectedVideoInput: '',
        selectedAudioOutput: '',
        isMirrored: true,
        isTestingSpeaker: false,
        showDeviceSettingsModal: false,
        hardwareNotice: {
            show: false,
            type: 'warning', // 'warning', 'danger', 'info'
            message: '',
            canRetryCamera: false,
            isRetrying: false,
        },
        currentUserId: config.currentUserId || 0,
        currentUserName: config.currentUserName || '',
        meetingUuid: config.meetingUuid || '',
        signalUrl: config.signalUrl || null,
        syncUrl: config.syncUrl || null,
        iceServers: config.iceServers && config.iceServers.length > 0 ? config.iceServers : [
            { urls: 'stun:stun.l.google.com:19302' },
            { urls: 'stun:stun1.l.google.com:19302' },
            { urls: 'stun:stun2.l.google.com:19302' }
        ],
        _listeners: [],
        _timers: [],
        _signalPollTimer: null,
        _lastSignalTimestamp: 0,
        _processedSignalIds: new Set(),
        _knownPeerIds: new Set(),
        _pendingLobbyPeers: new Set(),

        bindListener(target, event, handler) {
            if (!target || typeof target.addEventListener !== 'function') return;
            target.addEventListener(event, handler);
            this._listeners.push({ target, event, handler });
        },

        clearAllListeners() {
            if (this._listeners && this._listeners.length > 0) {
                this._listeners.forEach(({ target, event, handler }) => {
                    try {
                        target.removeEventListener(event, handler);
                    } catch (e) {}
                });
                this._listeners = [];
            }
        },

        setTimeoutTracked(fn, delay) {
            const id = setTimeout(() => {
                this._timers = this._timers.filter((t) => t !== id);
                fn();
            }, delay);
            this._timers.push(id);
            return id;
        },

        clearAllTimers() {
            if (this._timers && this._timers.length > 0) {
                this._timers.forEach((id) => clearTimeout(id));
                this._timers = [];
            }
        },

        isPeerMuted(userId) {
            const id = Number(userId);
            if (this.peerStates[id] && this.peerStates[id].isMuted !== undefined) {
                return !!this.peerStates[id].isMuted;
            }
            return !!this.peers[id]?.isMuted;
        },

        isPeerVideoOff(userId) {
            const id = Number(userId);
            if (this.peerStates[id] && this.peerStates[id].isVideoOff !== undefined) {
                return !!this.peerStates[id].isVideoOff;
            }
            const peer = this.peers[id];
            if (!peer) return true;
            if (!peer.stream) return true;
            const vTracks = peer.stream.getVideoTracks();
            if (vTracks.length === 0) return true;
            if (peer.isVideoOff !== undefined) {
                return !!peer.isVideoOff;
            }
            return false;
        },

        setPeerState(userId, state = {}) {
            const id = Number(userId);
            const current = this.peerStates[id] || {};
            const updated = { ...current, ...state };
            this.peerStates = { ...this.peerStates, [id]: updated };
            if (this.peers[id]) {
                if (state.isMuted !== undefined) this.peers[id].isMuted = !!state.isMuted;
                if (state.isVideoOff !== undefined) this.peers[id].isVideoOff = !!state.isVideoOff;
            }
            this.$nextTick(() => {
                this.bindRemoteVideo(id);
            });
        },

        isPeerSpeaking(userId) {
            return !!this.peers[Number(userId)]?.isSpeaking;
        },

        getPeerAudioLevel(userId) {
            return this.peers[Number(userId)]?.audioLevel || 0;
        },

        pinUser(userId) {
            const id = Number(userId);
            if (this.pinnedUserId === id) {
                this.pinnedUserId = null;
            } else {
                this.pinnedUserId = id;
            }
            this.$nextTick(() => {
                this.rebindAllRemoteVideos();
                this.rebindLocalVideo();
            });
        },

        unpinUser() {
            this.pinnedUserId = null;
            this.$nextTick(() => {
                this.rebindAllRemoteVideos();
                this.rebindLocalVideo();
            });
        },

        setLayoutMode(mode) {
            this.layoutMode = mode;
            if (mode === 'grid') {
                this.pinnedUserId = null;
            }
            this.$nextTick(() => {
                this.rebindAllRemoteVideos();
                this.rebindLocalVideo();
            });
        },

        effectiveFeaturedUserId() {
            if (this.spotlightUserId) {
                return Number(this.spotlightUserId);
            }
            if (this.pinnedUserId) {
                return Number(this.pinnedUserId);
            }
            if (this.layoutMode === 'speaker') {
                if (this.isLocalSpeaking) return Number(this.currentUserId);
                const speakingPeer = Object.values(this.peers).find((p) => p.isSpeaking);
                if (speakingPeer) return speakingPeer.userId;
            }
            if (this.layoutMode === 'sidebar') {
                const firstPeer = Object.values(this.peers)[0];
                return firstPeer ? firstPeer.userId : Number(this.currentUserId);
            }
            return null;
        },

        bindRemoteVideo(userId) {
            const id = Number(userId);
            const isOff = this.isPeerVideoOff(id);
            const peer = this.peers[id];
            const elements = document.querySelectorAll(`[data-remote-video-user="${id}"], #remote-meeting-video-${id}`);

            elements.forEach((el) => {
                if (isOff) {
                    el.classList.add('hidden');
                    el.classList.remove('block');
                } else if (peer && peer.stream) {
                    if (el.srcObject !== peer.stream) {
                        try {
                            el.srcObject = peer.stream;
                        } catch (e) {}
                    }
                    el.classList.remove('hidden');
                    el.classList.add('block');
                    el.muted = true;
                    el.playsInline = true;
                    el.play().catch(() => {});
                }
            });

            if (peer && peer.stream) {
                ensureMeetingAudioSink('meeting-peer-audio-' + id, peer.stream);
            }

            // Retry binding if DOM elements were not mounted yet (e.g. after view change)
            if (elements.length === 0 && peer && peer.stream) {
                setTimeout(() => {
                    const retryEls = document.querySelectorAll(`[data-remote-video-user="${id}"], #remote-meeting-video-${id}`);
                    if (retryEls.length > 0) {
                        this.bindRemoteVideo(id);
                    }
                }, 250);
                setTimeout(() => {
                    this.bindRemoteVideo(id);
                }, 750);
            }
        },

        rebindAllRemoteVideos() {
            Object.keys(this.peers).forEach((id) => {
                this.bindRemoteVideo(id);
            });
        },

        init() {
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => {
                    this.destroy();
                });
            }

            this.inPreJoinLobby = config.inPreJoinLobby !== undefined ? !!config.inPreJoinLobby : false;
            this.currentUserId = config.currentUserId || (this.$wire && this.$wire.currentUser?.id) || 0;
            this.meetingUuid = config.meetingUuid || '';
            this.spotlightUserId = config.spotlightUserId || null;
            this.spotlightUserName = config.spotlightUserName || '';

            if (config.iceServers && config.iceServers.length > 0) {
                this.iceServers = config.iceServers;
            }

            this.websocketConnected = window.WebSocketState ? window.WebSocketState.isConnected : false;
            this.websocketStatus = window.WebSocketState ? window.WebSocketState.status : 'uninitialized';

            this.bindListener(window, 'websocket-status-changed', (e) => {
                const detail = extractEventData(e.detail);
                this.websocketConnected = !!detail.isConnected;
                this.websocketStatus = detail.status || 'disconnected';

                if (detail.isConnected) {
                    this.stopSignalPolling();
                } else if (!this.inPreJoinLobby) {
                    this.startSignalPolling();
                }
            });

            // Direct Echo subscription for live meeting channel
            this.subscribeMeetingEchoChannel(this.meetingUuid);

            // Start signal polling fallback if WebSocket is not connected
            if (!this.websocketConnected && !this.inPreJoinLobby) {
                this.setTimeoutTracked(() => this.startSignalPolling(), 1500);
            }

            this.startMedia().then(() => {
                if (!this.inPreJoinLobby && this.currentUserId) {
                    this.setTimeoutTracked(() => {
                        this.rebindLocalVideo();
                        this.sendMeetingSignal('peer_join', { userId: this.currentUserId });
                        this.syncActivePeers();
                    }, 400);
                }
            });

            // Periodic peer health synchronization (every 3s)
            const peerCheckInterval = setInterval(() => {
                if (!this.inPreJoinLobby && this.currentUserId) {
                    this.syncActivePeers();
                }
            }, 3000);
            this._timers.push(peerCheckInterval);

            this.bindListener(document, 'livewire:navigating', () => {
                this.destroy();
            });
            this.bindListener(window, 'beforeunload', () => {
                this.destroy();
            });
            this.bindListener(window, 'pagehide', () => {
                this.destroy();
            });

            this.bindListener(window, 'fullscreenchange', () => {
                this.isFullscreen = !!document.fullscreenElement;
            });

            if (navigator.mediaDevices && typeof navigator.mediaDevices.addEventListener === 'function') {
                this.bindListener(navigator.mediaDevices, 'devicechange', () => {
                    this.refreshHardwareDevices();
                });
            }

            this.bindListener(window, 'play-inroom-chime', () => {
                this.playInRoomChime();
            });

            this.bindListener(window, 'apply-prejoin-settings', (e) => {
                const data = extractEventData(e.detail);
                this.inPreJoinLobby = false;
                if (data && data.mic === false) this.muteMicCompletely();
                if (data && data.video === false) this.stopVideoCompletely();

                resumeAllAudioSinks();

                // Start polling if websocket not connected
                if (!this.websocketConnected) {
                    this.startSignalPolling();
                }

                if (typeof this.$nextTick === 'function') {
                    this.$nextTick(() => {
                        this.rebindLocalVideo();
                        if (this.currentUserId) {
                            this.sendMeetingSignal('peer_join', { userId: this.currentUserId });
                        }
                        // Connect to any peers who signaled while in lobby
                        if (this._pendingLobbyPeers.size > 0) {
                            this._pendingLobbyPeers.forEach((pId) => {
                                if (Number(this.currentUserId) < pId) {
                                    this.initiatePeerConnection(pId);
                                } else {
                                    this.sendMeetingSignal('peer_presence', { userId: this.currentUserId }, pId);
                                }
                            });
                            this._pendingLobbyPeers.clear();
                        }
                        this.syncActivePeers();
                    });
                }
            });

            this.bindListener(window, 'trigger-floating-emoji', (e) => {
                const data = extractEventData(e.detail);
                if (data && data.emoji) {
                    this.addFloatingEmoji(data.emoji, data.user);
                }
            });

            this.bindListener(window, 'force-mute-all', () => {
                if (!config.isHostOrCoHost) {
                    this.muteMicCompletely();
                }
            });

            this.bindListener(window, 'force-video-off-all', () => {
                if (!config.isHostOrCoHost) {
                    this.stopVideoCompletely();
                }
            });

            this.bindListener(window, 'beforeunload', () => {
                this.stopAllMedia();
            });

            this.bindListener(window, 'pagehide', () => {
                this.stopAllMedia();
            });

            this.bindListener(document, 'livewire:navigating', () => {
                this.stopAllMedia();
                this.destroy();
            });
        },

        subscribeMeetingEchoChannel(uuid) {
            if (!uuid) return;
            const sub = () => {
                if (window.Echo && typeof window.Echo.private === 'function') {
                    try {
                        window.Echo.private(`meeting.${uuid}`)
                            .listen('.MeetingRealtime', (event) => this.handleMeetingRealtime(event))
                            .listen('MeetingRealtime', (event) => this.handleMeetingRealtime(event));
                    } catch (e) {
                        console.debug('Meeting Echo channel subscription note:', e);
                    }
                }
            };
            sub();
            if (!window.Echo) {
                const timer = setInterval(() => {
                    if (window.Echo && typeof window.Echo.private === 'function') {
                        sub();
                        clearInterval(timer);
                    }
                }, 300);
                setTimeout(() => clearInterval(timer), 6000);
            }
        },

        /**
         * Start polling the sync endpoint for cached signals when WebSocket is unavailable.
         * Activates automatically and stops when WebSocket reconnects.
         */
        startSignalPolling() {
            if (this._signalPollTimer) return;
            if (!this.meetingUuid || this.inPreJoinLobby) return;

            const rawUuid = this.meetingUuid || config.meetingUuid || '';
            const uuid = encodeURIComponent(rawUuid);
            let syncUrl = this.syncUrl || config.syncUrl || null;
            if (!syncUrl && uuid) {
                syncUrl = '/meetings/' + uuid + '/sync';
            }
            if (!syncUrl) return;

            this._lastSignalTimestamp = this._lastSignalTimestamp || (Date.now() / 1000 - 5);

            const poll = async () => {
                if (window.WebSocketState && window.WebSocketState.isConnected) {
                    this.stopSignalPolling();
                    return;
                }
                try {
                    const url = syncUrl + '?include_signals=1&since=' + this._lastSignalTimestamp;
                    const resp = await fetch(url, {
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });
                    if (!resp.ok) return;
                    const data = await resp.json();

                    if (data.timestamp) {
                        this._lastSignalTimestamp = data.timestamp;
                    }

                    if (Array.isArray(data.signals)) {
                        data.signals.forEach((sig) => {
                            const sigId = sig.id || sig.signal_id;
                            if (sigId && this._processedSignalIds.has(sigId)) return;
                            if (sigId) {
                                this._processedSignalIds.add(sigId);
                                if (this._processedSignalIds.size > 250) {
                                    const arr = Array.from(this._processedSignalIds);
                                    this._processedSignalIds = new Set(arr.slice(-150));
                                }
                            }
                            this.handleMeetingRealtime({
                                event_type: 'meeting_signal',
                                eventType: 'meeting_signal',
                                type: 'meeting_signal',
                                payload: sig.payload || sig,
                                sender_user_id: sig.fromUserId,
                                senderUserId: sig.fromUserId,
                            });
                        });
                    }
                } catch (e) {
                    console.debug('Meeting signal poll notice:', e);
                }
            };

            // Fast polling every 1 second when on HTTP fallback for low-latency WebRTC exchange
            poll();
            this._signalPollTimer = setInterval(poll, 1000);
        },

        stopSignalPolling() {
            if (this._signalPollTimer) {
                clearInterval(this._signalPollTimer);
                this._signalPollTimer = null;
            }
        },

        syncActivePeers() {
            if (this.inPreJoinLobby || !this.currentUserId) return;
            const myId = Number(this.currentUserId);
            
            // Collect participant IDs from DOM, known registry, and existing peers
            const domElements = document.querySelectorAll('[data-remote-video-user]');
            const domIds = Array.from(domElements)
                .map((el) => Number(el.getAttribute('data-remote-video-user')))
                .filter((id) => id && id !== myId);

            const allActiveIds = new Set([...domIds, ...this._knownPeerIds, ...Object.keys(this.peers).map(Number)]);
            allActiveIds.delete(myId);

            allActiveIds.forEach((pId) => {
                if (!pId || pId === myId) return;
                const peer = this.peers[pId];
                const isDisconnected = !peer || !peer.pc ||
                    peer.pc.connectionState === 'failed' || peer.pc.connectionState === 'disconnected' ||
                    peer.pc.iceConnectionState === 'failed' || peer.pc.iceConnectionState === 'disconnected';

                if (isDisconnected) {
                    if (peer && peer.pc && (peer.pc.connectionState === 'failed' || peer.pc.iceConnectionState === 'failed')) {
                        if (peer._candidateTimer) clearTimeout(peer._candidateTimer);
                        try { peer.pc.close(); } catch (e) {}
                        delete this.peers[pId];
                    }
                    if (myId < pId) {
                        this.initiatePeerConnection(pId);
                    } else {
                        this.sendMeetingSignal('peer_presence', { userId: myId }, pId);
                    }
                }
            });
        },

        sendMeetingSignal(signalType, payload = {}, targetUserId = null) {
            const token = (typeof document !== 'undefined' ? document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') : '') || '';
            const rawUuid = this.meetingUuid || config.meetingUuid || '';
            const uuid = encodeURIComponent(rawUuid);

            let signalUrl = this.signalUrl || config.signalUrl || null;
            if (!signalUrl && uuid) {
                signalUrl = '/meetings/' + uuid + '/signal';
            }

            const executeLivewireFallback = (reason) => {
                if (this.$wire && typeof this.$wire.sendMeetingSignal === 'function') {
                    try {
                        this.$wire.sendMeetingSignal(signalType, payload, targetUserId);
                    } catch (err) {
                        console.debug('Meeting signal transmission Livewire fallback error:', err);
                    }
                } else {
                    console.debug('Meeting signal HTTP failed and Livewire fallback unavailable:', reason);
                }
            };

            if (!signalUrl) {
                executeLivewireFallback('No signalUrl available');
                return;
            }

            fetch(signalUrl, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    signalType: signalType,
                    signal_type: signalType,
                    payload: payload,
                    targetUserId: targetUserId,
                    target_user_id: targetUserId,
                }),
            }).then((resp) => {
                if (!resp.ok) {
                    executeLivewireFallback(`HTTP ${resp.status}`);
                }
            }).catch((err) => {
                executeLivewireFallback(err);
            });
        },

        getOrCreatePeerConnection(userId) {
            const id = Number(userId);
            if (!this.peers[id]) {
                const pc = new RTCPeerConnection({ iceServers: this.iceServers });

                // Guarantee fixed deterministic transceivers: Audio (m=0), Video (m=1)
                ensureDeterministicTransceivers(pc, this.localStream);

                const peerObj = {
                    userId: id,
                    pc: pc,
                    stream: new MediaStream(),
                    pendingCandidates: [],
                    isMuted: false,
                    isVideoOff: false,
                    audioLevel: 0,
                    isSpeaking: false,
                    audioCtx: null,
                    analyser: null,
                    makingOffer: false,
                    ignoreOffer: false,
                    _offerTimestamp: 0,
                    _candidateQueue: [],
                    _candidateTimer: null,
                };

                this.peers = { ...this.peers, [id]: peerObj };

                if (this.localStream || (this.screenSharing && this.screenStream)) {
                    this.attachTracksToPeerConnection(pc, this.localStream);
                }

                pc.ontrack = (event) => {
                    if (!this.peers[id]) return;
                    if (!this.peers[id].stream) {
                        this.peers[id].stream = new MediaStream();
                    }
                    if (event.streams && event.streams[0]) {
                        event.streams[0].getTracks().forEach((track) => {
                            if (!this.peers[id].stream.getTracks().some((t) => t.id === track.id)) {
                                this.peers[id].stream.addTrack(track);
                            }
                        });
                    }
                    if (event.track && !this.peers[id].stream.getTracks().some((t) => t.id === event.track.id)) {
                        this.peers[id].stream.addTrack(event.track);
                    }

                    const stream = this.peers[id].stream;
                    if (this.peers[id].isVideoOff === undefined) {
                        this.peers[id].isVideoOff = this.peerStates[id]?.isVideoOff !== undefined ? !!this.peerStates[id].isVideoOff : false;
                    }
                    this.peers = { ...this.peers };

                    if (event.track.kind === 'audio') {
                        event.track.enabled = !this.isPeerMuted(id);
                        ensureMeetingAudioSink('meeting-peer-audio-' + id, stream);
                        this.setupRemotePeerAudioMeter(id, stream);
                        event.track.onunmute = () => {
                            if (this.peers[id]) {
                                ensureMeetingAudioSink('meeting-peer-audio-' + id, stream);
                            }
                        };
                    }

                    if (event.track.kind === 'video') {
                        event.track.enabled = true;
                        const isExplicitlyOff = !!this.peerStates[id]?.isVideoOff;
                        if (!isExplicitlyOff) {
                            this.peers[id].isVideoOff = false;
                        }
                        const triggerVideoReady = () => {
                            if (this.peers[id]) {
                                const explicitlyOff = !!this.peerStates[id]?.isVideoOff;
                                if (!explicitlyOff) {
                                    this.peers[id].isVideoOff = false;
                                    this.bindRemoteVideo(id);
                                }
                            }
                        };
                        event.track.onunmute = triggerVideoReady;
                        event.track.onloadedmetadata = triggerVideoReady;
                    }

                    this.bindRemoteVideo(id);
                    if (typeof this.$nextTick === 'function') {
                        this.$nextTick(() => {
                            this.bindRemoteVideo(id);
                        });
                    }
                    setTimeout(() => {
                        this.bindRemoteVideo(id);
                    }, 150);
                    setTimeout(() => {
                        this.bindRemoteVideo(id);
                    }, 500);
                };

                pc.onicecandidate = (event) => {
                    if (event.candidate) {
                        const candObj = event.candidate.toJSON ? event.candidate.toJSON() : {
                            candidate: event.candidate.candidate,
                            sdpMid: event.candidate.sdpMid,
                            sdpMLineIndex: event.candidate.sdpMLineIndex,
                            usernameFragment: event.candidate.usernameFragment,
                        };
                        peerObj._candidateQueue.push(candObj);
                        if (!peerObj._candidateTimer) {
                            peerObj._candidateTimer = setTimeout(() => {
                                const batch = peerObj._candidateQueue ? peerObj._candidateQueue.splice(0, peerObj._candidateQueue.length) : [];
                                peerObj._candidateTimer = null;
                                if (batch.length > 0) {
                                    this.sendMeetingSignal('ice_candidates_batch', { candidates: batch }, id);
                                }
                            }, 35);
                        }
                    }
                };

                pc.oniceconnectionstatechange = () => {
                    const state = pc.iceConnectionState;
                    if (state === 'failed') {
                        try { pc.restartIce(); } catch (e) {}
                    }
                };
            }
            return this.peers[id];
        },

        async attachTracksToPeerConnection(pc, stream) {
            if (!pc) return;
            const activeStream = (this.screenSharing && this.screenStream) ? this.screenStream : stream;
            const { audio: audioTransceiver, video: videoTransceiver } = ensureDeterministicTransceivers(pc, activeStream);

            const audioTracks = stream ? stream.getAudioTracks() : [];
            const videoTracks = activeStream ? activeStream.getVideoTracks() : [];

            // 1. Audio Track (Audio always first)
            const audioTrack = audioTracks.length > 0 ? audioTracks[0] : null;
            if (audioTrack) {
                audioTrack.enabled = !this.micMuted;
            }
            if (audioTransceiver && audioTransceiver.sender) {
                try {
                    await audioTransceiver.sender.replaceTrack(audioTrack || null);
                } catch (e) {}
                try {
                    audioTransceiver.direction = 'sendrecv';
                } catch (e) {}
            }

            // 2. Video Track (Video always second)
            const videoTrack = (this.screenSharing || (!this.videoOff && videoTracks.length > 0)) ? videoTracks[0] : null;
            if (videoTrack) {
                videoTrack.enabled = this.screenSharing ? true : !this.videoOff;
            }
            if (videoTransceiver && videoTransceiver.sender) {
                try {
                    await videoTransceiver.sender.replaceTrack(videoTrack || null);
                } catch (e) {}
                try {
                    videoTransceiver.direction = 'sendrecv';
                } catch (e) {}
            }
        },

        async initiatePeerConnection(targetUserId) {
            const id = Number(targetUserId);
            const peer = this.getOrCreatePeerConnection(id);
            if (!peer || !peer.pc) return;

            // If we already have a pending local offer, re-transmit it to unblock the peer
            if (peer.pc.signalingState === 'have-local-offer') {
                if (peer.pc.localDescription) {
                    this.sendMeetingSignal('offer', { sdp: peer.pc.localDescription }, id);
                }
                const offerAge = Date.now() - (peer._offerTimestamp || 0);
                if (offerAge > 4000) {
                    try {
                        peer.pc.close();
                        delete this.peers[id];
                        this.getOrCreatePeerConnection(id);
                        setTimeout(() => this.initiatePeerConnection(id), 200);
                    } catch (e) {}
                }
                return;
            }

            if (peer.makingOffer) {
                return;
            }

            try {
                peer.makingOffer = true;
                peer._offerTimestamp = Date.now();
                if (this.localStream) {
                    await this.attachTracksToPeerConnection(peer.pc, this.localStream);
                }

                const offer = await peer.pc.createOffer();
                if (peer.pc.signalingState !== 'stable') {
                    return;
                }
                await peer.pc.setLocalDescription(offer);
                this.sendMeetingSignal('offer', { sdp: peer.pc.localDescription || offer }, id);
            } catch (e) {
                console.warn(`Error creating offer for peer ${id}:`, e);
            } finally {
                if (peer) peer.makingOffer = false;
            }
        },

        async handlePeerOffer(fromUserId, sdp) {
            const id = Number(fromUserId);
            const peer = this.getOrCreatePeerConnection(id);
            if (!peer || !peer.pc) return;

            const isPolite = Number(this.currentUserId) > Number(id);
            const offerCollision = (peer.pc.signalingState !== 'stable' || peer.makingOffer);
            peer.ignoreOffer = !isPolite && offerCollision;
            if (peer.ignoreOffer) {
                // Ensure polite peer receives our offer if impolite ignores theirs
                if (peer.pc.localDescription) {
                    this.sendMeetingSignal('offer', { sdp: peer.pc.localDescription }, id);
                }
                return;
            }

            if (offerCollision && isPolite) {
                try {
                    await peer.pc.setLocalDescription({ type: 'rollback' });
                } catch (err) {}
            }

            try {
                const desc = toSessionDescription(sdp, 'offer');
                if (desc) {
                    await peer.pc.setRemoteDescription(desc);
                }
                if (this.localStream) {
                    await this.attachTracksToPeerConnection(peer.pc, this.localStream);
                }

                while (peer.pendingCandidates && peer.pendingCandidates.length > 0) {
                    const cand = peer.pendingCandidates.shift();
                    await addSafeIceCandidate(peer.pc, cand);
                }

                if (peer.pc.signalingState === 'have-remote-offer') {
                    const answer = await peer.pc.createAnswer();
                    await peer.pc.setLocalDescription(answer);
                    this.sendMeetingSignal('answer', { sdp: peer.pc.localDescription || answer }, id);
                }
            } catch (e) {
                console.warn(`Error handling offer from peer ${id}:`, e);
            }
        },

        async handlePeerAnswer(fromUserId, sdp) {
            const id = Number(fromUserId);
            const peer = this.peers[id];
            if (peer && peer.pc) {
                if (peer.pc.signalingState !== 'have-local-offer') {
                    return;
                }
                try {
                    const desc = toSessionDescription(sdp, 'answer');
                    if (desc) {
                        await peer.pc.setRemoteDescription(desc);
                    }
                    while (peer.pendingCandidates && peer.pendingCandidates.length > 0) {
                        const cand = peer.pendingCandidates.shift();
                        await addSafeIceCandidate(peer.pc, cand);
                    }
                } catch (e) {
                    console.warn(`Error setting answer for peer ${id}:`, e);
                }
            }
        },

        async handlePeerIceCandidate(fromUserId, candidate) {
            const peer = this.peers[fromUserId] || this.getOrCreatePeerConnection(fromUserId);
            if (peer && peer.pc && candidate) {
                if (peer.pc.remoteDescription && peer.pc.remoteDescription.type) {
                    await addSafeIceCandidate(peer.pc, candidate);
                } else {
                    peer.pendingCandidates.push(candidate);
                }
            }
        },

        handleMeetingRealtime(event) {
            if (!event) return;
            const type = event.event_type || event.type || event.eventType;
            const payload = event.payload || {};
            const senderUserId = event.sender_user_id || event.senderUserId || payload.fromUserId || payload.from_user_id;
            const myUserId = this.currentUserId || (this.$wire && this.$wire.currentUser?.id);

            if (type === 'participant_joined' || type === 'waiting_joined') {
                this.playInRoomChime();
                const userName = payload.user_name || payload.userName || 'A participant';
                if (window.Alpine?.store('toasts')) {
                    window.Alpine.store('toasts').add('info', `${userName} joined the meeting`);
                }
                if (this.$wire && typeof this.$wire.refreshRoom === 'function') {
                    this.$wire.refreshRoom();
                }
                const newUserId = Number(payload.user_id || payload.userId);
                if (newUserId && myUserId && newUserId !== Number(myUserId)) {
                    this._knownPeerIds.add(newUserId);
                    if (Number(myUserId) < newUserId) {
                        setTimeout(() => {
                            this.initiatePeerConnection(newUserId);
                        }, 300);
                    } else {
                        setTimeout(() => {
                            this.sendMeetingSignal('peer_presence', { userId: myUserId }, newUserId);
                        }, 300);
                    }
                }
                setTimeout(() => this.syncActivePeers(), 600);
            } else if (type === 'participant_left') {
                const userName = payload.user_name || payload.userName || 'A participant';
                if (window.Alpine?.store('toasts')) {
                    window.Alpine.store('toasts').add('info', `${userName} left the meeting`);
                }
                const leftUserId = Number(payload.user_id || payload.userId);
                if (leftUserId) {
                    this._knownPeerIds.delete(leftUserId);
                    this._pendingLobbyPeers.delete(leftUserId);
                    if (this.peers[leftUserId]) {
                        if (this.peers[leftUserId]._candidateTimer) {
                            clearTimeout(this.peers[leftUserId]._candidateTimer);
                        }
                        try {
                            this.peers[leftUserId].pc.close();
                        } catch (e) {}
                        if (this.peers[leftUserId].audioCtx) {
                            try { this.peers[leftUserId].audioCtx.close(); } catch (e) {}
                        }
                        removeMeetingAudioSink('meeting-peer-audio-' + leftUserId);
                        const newPeers = { ...this.peers };
                        delete newPeers[leftUserId];
                        this.peers = newPeers;
                    }
                }
                if (this.$wire && typeof this.$wire.refreshRoom === 'function') {
                    this.$wire.refreshRoom();
                }
            } else if (type === 'in_room_chat') {
                if (payload.log && this.$wire && typeof this.$wire.receiveInRoomMessage === 'function') {
                    this.$wire.receiveInRoomMessage(payload.log);
                }
            } else if (type === 'spotlight_updated') {
                this.spotlightUserId = payload.spotlight_user_id ? Number(payload.spotlight_user_id) : null;
                this.spotlightUserName = payload.spotlight_user_name || '';
                if (this.spotlightUserId) {
                    if (window.Alpine?.store('toasts')) {
                        window.Alpine.store('toasts').add('info', `${this.spotlightUserName || 'A participant'} was spotlighted for everyone.`);
                    }
                } else {
                    if (window.Alpine?.store('toasts')) {
                        window.Alpine.store('toasts').add('info', 'Spotlight was removed.');
                    }
                }
                this.$nextTick(() => {
                    this.rebindAllRemoteVideos();
                    this.rebindLocalVideo();
                });
            } else if (type === 'meeting_signal') {
                const targetId = Number(payload.targetUserId || payload.target_user_id);
                const fromId = Number(payload.fromUserId || payload.from_user_id || senderUserId);
                if (fromId && myUserId && Number(fromId) === Number(myUserId)) {
                    return;
                }
                if (targetId && myUserId && targetId !== Number(myUserId)) {
                    return;
                }
                if (fromId) {
                    this._knownPeerIds.add(fromId);
                }

                // If in lobby, queue signals from peers to process upon room entry
                if (this.inPreJoinLobby) {
                    if (fromId) {
                        this._pendingLobbyPeers.add(fromId);
                    }
                    return;
                }

                const sigType = payload.signalType || payload.signal_type;
                const sigPayload = payload.payload || {};

                if (sigType === 'offer' && fromId) {
                    this.handlePeerOffer(fromId, sigPayload.sdp);
                } else if (sigType === 'answer' && fromId) {
                    this.handlePeerAnswer(fromId, sigPayload.sdp);
                } else if ((sigType === 'ice_candidate' || sigType === 'ice_candidates_batch') && fromId) {
                    const candidates = sigPayload.candidates || (sigPayload.candidate ? [sigPayload.candidate] : []);
                    candidates.forEach((cand) => {
                        this.handlePeerIceCandidate(fromId, cand);
                    });
                } else if (sigType === 'peer_join' && fromId && myUserId && fromId !== Number(myUserId)) {
                    if (Number(myUserId) < fromId) {
                        this.initiatePeerConnection(fromId);
                    } else {
                        this.sendMeetingSignal('peer_presence', { userId: myUserId }, fromId);
                    }
                } else if (sigType === 'peer_presence' && fromId && myUserId && fromId !== Number(myUserId)) {
                    if (Number(myUserId) < fromId) {
                        this.initiatePeerConnection(fromId);
                    }
                } else if (sigType === 'peer_state' && fromId) {
                    const isMuted = !!sigPayload.isMuted;
                    const isVideoOff = !!sigPayload.isVideoOff;
                    const isScreenSharing = !!sigPayload.isScreenSharing;

                    this.setPeerState(fromId, { isMuted, isVideoOff, isScreenSharing });

                    if (this.peers[fromId]) {
                        if (this.peers[fromId].stream) {
                            this.peers[fromId].stream.getAudioTracks().forEach((t) => {
                                t.enabled = !isMuted;
                            });
                        }
                        if (isMuted) {
                            this.peers[fromId].audioLevel = 0;
                            this.peers[fromId].isSpeaking = false;
                        }
                    }
                    this.bindRemoteVideo(fromId);
                } else if (sigType === 'floating_emoji') {
                    if (sigPayload.emoji) {
                        this.addFloatingEmoji(sigPayload.emoji, sigPayload.user || sigPayload.user_name || 'Participant');
                    }
                }
            } else if (type === 'waiting_admitted') {
                if (this.$wire && typeof this.$wire.joinRoomFromLobby === 'function') {
                    this.$wire.joinRoomFromLobby();
                }
                if (this.$wire && typeof this.$wire.refreshRoom === 'function') {
                    this.$wire.refreshRoom();
                }
            } else if (type === 'waiting_denied') {
                if (payload.user_id && this.$wire?.currentUser?.id === payload.user_id) {
                    if (window.Alpine?.store('toasts')) {
                        window.Alpine.store('toasts').add('error', 'Your request to join this meeting was declined by the host.');
                    }
                    setTimeout(() => {
                        window.location.href = '/meetings';
                    }, 1500);
                }
            } else if (type === 'role_updated' || type === 'restrictions_updated') {
                if (this.$wire && typeof this.$wire.refreshRoom === 'function') {
                    this.$wire.refreshRoom();
                }
            } else if (type === 'meeting_ended') {
                this.stopAllMedia();
                if (window.Alpine?.store('toasts')) {
                    window.Alpine.store('toasts').add('info', 'The meeting has been ended by the host.');
                }
                setTimeout(() => {
                    window.location.href = '/meetings';
                }, 1200);
            } else if (type === 'floating_emoji') {
                if (payload.emoji) {
                    this.addFloatingEmoji(payload.emoji, payload.user || 'Participant');
                }
            } else if (type === 'force_mute_all') {
                if (!config.isHostOrCoHost) {
                    this.muteMicCompletely();
                    if (window.Alpine?.store('toasts')) {
                        window.Alpine.store('toasts').add('warning', 'You were muted by the meeting host.');
                    }
                }
            } else if (type === 'force_video_off_all') {
                if (!config.isHostOrCoHost) {
                    this.stopVideoCompletely();
                    if (window.Alpine?.store('toasts')) {
                        window.Alpine.store('toasts').add('warning', 'Your camera was turned off by the meeting host.');
                    }
                }
            }
        },

        setupLocalAudioMeter(stream) {
            try {
                if (!stream || stream.getAudioTracks().length === 0) return;
                this.localAudioCtx = createSafeAudioContext();
                if (!this.localAudioCtx) return;
                const source = this.localAudioCtx.createMediaStreamSource(stream);
                this.localAnalyser = this.localAudioCtx.createAnalyser();
                this.localAnalyser.fftSize = 32;
                source.connect(this.localAnalyser);
                this.startMeetingAudioMeterLoop();
            } catch (e) {
                console.debug('Meeting local audio meter setup error:', e);
            }
        },

        setupRemotePeerAudioMeter(userId, stream) {
            try {
                if (!stream || stream.getAudioTracks().length === 0) return;
                const peer = this.peers[userId];
                if (!peer) return;
                peer.audioCtx = createSafeAudioContext();
                if (!peer.audioCtx) return;
                const source = peer.audioCtx.createMediaStreamSource(stream);
                peer.analyser = peer.audioCtx.createAnalyser();
                peer.analyser.fftSize = 32;
                source.connect(peer.analyser);
                this.startMeetingAudioMeterLoop();
            } catch (e) {
                console.debug('Meeting peer audio meter setup error:', e);
            }
        },

        startMeetingAudioMeterLoop() {
            if (this.meetingAudioFrame) return;
            const dataArray = new Uint8Array(16);

            const updateMeter = () => {
                if (!this.localStream && Object.keys(this.peers).length === 0) {
                    this.localAudioLevel = 0;
                    this.isLocalSpeaking = false;
                    this.meetingAudioFrame = null;
                    return;
                }

                // Local mic volume
                if (this.localAnalyser && !this.micMuted) {
                    try {
                        this.localAnalyser.getByteFrequencyData(dataArray);
                        let sum = 0;
                        for (let i = 0; i < 16; i++) sum += dataArray[i];
                        this.localAudioLevel = Math.min(100, Math.round((sum / (16 * 128)) * 100));
                        this.isLocalSpeaking = this.localAudioLevel > 6;
                    } catch (e) {
                        this.localAudioLevel = 0;
                        this.isLocalSpeaking = false;
                    }
                } else {
                    this.localAudioLevel = 0;
                    this.isLocalSpeaking = false;
                }

                // Remote peers volume & video element health check
                Object.values(this.peers).forEach((peer) => {
                    if (peer.stream) {
                        const isOff = this.isPeerVideoOff(peer.userId);
                        const videoEls = document.querySelectorAll(`[data-remote-video-user="${peer.userId}"], #remote-meeting-video-${peer.userId}`);
                        videoEls.forEach((videoEl) => {
                            if (!isOff && videoEl.srcObject !== peer.stream) {
                                try {
                                    videoEl.srcObject = peer.stream;
                                    videoEl.play().catch(() => {});
                                } catch (e) {}
                            }
                        });
                    }

                    if (peer.analyser && !peer.isMuted) {
                        try {
                            peer.analyser.getByteFrequencyData(dataArray);
                            let sum = 0;
                            for (let i = 0; i < 16; i++) sum += dataArray[i];
                            peer.audioLevel = Math.min(100, Math.round((sum / (16 * 128)) * 100));
                            peer.isSpeaking = peer.audioLevel > 6;
                        } catch (e) {
                            peer.audioLevel = 0;
                            peer.isSpeaking = false;
                        }
                    } else {
                        peer.audioLevel = 0;
                        peer.isSpeaking = false;
                    }
                });

                this.meetingAudioFrame = requestAnimationFrame(updateMeter);
            };

            updateMeter();
        },

        destroy() {
            this.stopAllMedia();
            this.stopSignalPolling();
            this.clearAllTimers();
            this.clearAllListeners();
            if (this.meetingUuid && window.Echo && typeof window.Echo.leave === 'function') {
                try {
                    window.Echo.leave(`meeting.${this.meetingUuid}`);
                } catch (e) {}
            }
        },

        toggleFullscreen() {
            const root = document.getElementById('meeting-root-container');
            if (!document.fullscreenElement) {
                root?.requestFullscreen?.().then(() => {
                    this.isFullscreen = true;
                }).catch(() => {
                    this.isFullscreen = !this.isFullscreen;
                });
            } else {
                document.exitFullscreen?.().then(() => {
                    this.isFullscreen = false;
                }).catch(() => {
                    this.isFullscreen = false;
                });
            }
        },

        playInRoomChime() {
            if (this.soundMuted) return;
            try {
                const ctx = createSafeAudioContext();
                if (!ctx) return;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(659.25, ctx.currentTime);
                osc.frequency.exponentialRampToValueAtTime(987.77, ctx.currentTime + 0.15);
                gain.gain.setValueAtTime(0.1, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.25);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.25);
            } catch (e) {
                console.debug('Meeting audio suppressed', e);
            }
        },

        rebindLocalVideo() {
            if (this.localStream && !this.videoOff) {
                const localEls = document.querySelectorAll('[data-local-video="true"]');
                localEls.forEach((el) => {
                    if (el.srcObject !== this.localStream) {
                        try {
                            el.srcObject = this.localStream;
                            el.play().catch(() => {});
                        } catch (e) {}
                    }
                });
                if (this.$refs && this.$refs.localVideo && this.$refs.localVideo.srcObject !== this.localStream) {
                    try {
                        this.$refs.localVideo.srcObject = this.localStream;
                        this.$refs.localVideo.play().catch(() => {});
                    } catch (e) {}
                }
            }
        },

        stopAllMedia() {
            if (this.meetingAudioFrame) {
                cancelAnimationFrame(this.meetingAudioFrame);
                this.meetingAudioFrame = null;
            }
            if (this.localAudioCtx) {
                try { this.localAudioCtx.close(); } catch (e) {}
                this.localAudioCtx = null;
            }
            this.localAudioLevel = 0;
            this.isLocalSpeaking = false;

            Object.values(this.peers).forEach((peer) => {
                if (peer.userId) {
                    removeMeetingAudioSink('meeting-peer-audio-' + peer.userId);
                }
                if (peer._candidateTimer) {
                    clearTimeout(peer._candidateTimer);
                }
                try {
                    if (peer.pc) peer.pc.close();
                } catch (e) {}
                if (peer.stream) {
                    stopMediaTracks(peer.stream);
                }
                if (peer.audioCtx) {
                    try { peer.audioCtx.close(); } catch (e) {}
                }
            });
            this.peers = {};

            if (this.localStream) {
                stopMediaTracks(this.localStream);
                this.localStream = null;
            }
            if (this.screenStream) {
                stopMediaTracks(this.screenStream);
                this.screenStream = null;
            }
            if (this.$refs && this.$refs.localVideo) {
                try {
                    this.$refs.localVideo.srcObject = null;
                } catch (e) {}
            }
            this.micMuted = true;
            this.videoOff = true;
            this.screenSharing = false;
        },

        async refreshHardwareDevices() {
            if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') return;
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                this.audioInputs = devices
                    .filter((d) => d.kind === 'audioinput')
                    .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Microphone ${i + 1}` }));
                this.videoInputs = devices
                    .filter((d) => d.kind === 'videoinput')
                    .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Camera ${i + 1}` }));
                this.audioOutputs = devices
                    .filter((d) => d.kind === 'audiooutput')
                    .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Speaker ${i + 1}` }));

                if (!this.selectedAudioInput && this.audioInputs.length > 0) {
                    this.selectedAudioInput = this.audioInputs[0].deviceId;
                }
                if (!this.selectedVideoInput && this.videoInputs.length > 0) {
                    this.selectedVideoInput = this.videoInputs[0].deviceId;
                }
                if (!this.selectedAudioOutput && this.audioOutputs.length > 0) {
                    this.selectedAudioOutput = this.audioOutputs[0].deviceId;
                }
            } catch (e) {
                console.debug('Meeting hardware device enumeration error:', e);
            }
        },

        toggleMirror() {
            this.isMirrored = !this.isMirrored;
        },

        testSpeakerSound() {
            this.isTestingSpeaker = true;
            try {
                const ctx = createSafeAudioContext();
                if (!ctx) {
                    this.isTestingSpeaker = false;
                    return;
                }
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(523.25, ctx.currentTime);
                osc.frequency.setValueAtTime(659.25, ctx.currentTime + 0.15);
                osc.frequency.setValueAtTime(783.99, ctx.currentTime + 0.3);
                gain.gain.setValueAtTime(0.12, ctx.currentTime);
                gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.6);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start();
                osc.stop(ctx.currentTime + 0.6);
                setTimeout(() => {
                    this.isTestingSpeaker = false;
                }, 650);
            } catch (e) {
                this.isTestingSpeaker = false;
            }
        },

        async switchCameraDevice(deviceId) {
            this.selectedVideoInput = deviceId;
            if (!this.localStream || this.videoOff) return;
            try {
                const newStream = await navigator.mediaDevices.getUserMedia({
                    video: deviceId ? { deviceId: { exact: deviceId }, width: { ideal: 1280 }, height: { ideal: 720 } } : { width: { ideal: 1280 }, height: { ideal: 720 } }
                });
                const newVideoTrack = newStream.getVideoTracks()[0];
                if (newVideoTrack) {
                    const oldTracks = this.localStream.getVideoTracks();
                    oldTracks.forEach((t) => {
                        t.stop();
                        this.localStream.removeTrack(t);
                    });
                    this.localStream.addTrack(newVideoTrack);

                    this.rebindLocalVideo();

                    Object.values(this.peers).forEach((peer) => {
                        if (peer.pc) {
                            const vidTrans = getTransceiverByKind(peer.pc, 'video');
                            if (vidTrans?.sender) {
                                vidTrans.sender.replaceTrack(newVideoTrack).catch(() => {});
                            }
                        }
                    });
                }
            } catch (e) {
                console.warn('Switch camera error:', e);
            }
        },

        async switchMicrophoneDevice(deviceId) {
            this.selectedAudioInput = deviceId;
            if (!this.localStream) return;
            try {
                const newStream = await navigator.mediaDevices.getUserMedia({
                    audio: deviceId ? { deviceId: { exact: deviceId }, echoCancellation: true, noiseSuppression: true, autoGainControl: true } : { echoCancellation: true, noiseSuppression: true, autoGainControl: true }
                });
                const newAudioTrack = newStream.getAudioTracks()[0];
                if (newAudioTrack) {
                    if (this.micMuted) {
                        newAudioTrack.enabled = false;
                    }
                    const oldTracks = this.localStream.getAudioTracks();
                    oldTracks.forEach((t) => {
                        t.stop();
                        this.localStream.removeTrack(t);
                    });
                    this.localStream.addTrack(newAudioTrack);

                    this.setupLocalAudioMeter(this.localStream);

                    Object.values(this.peers).forEach((peer) => {
                        if (peer.pc) {
                            const audTrans = getTransceiverByKind(peer.pc, 'audio');
                            if (audTrans?.sender) {
                                audTrans.sender.replaceTrack(newAudioTrack).catch(() => {});
                            }
                        }
                    });
                }
            } catch (e) {
                console.warn('Switch microphone error:', e);
            }
        },

        async switchAudioOutputDevice(deviceId) {
            this.selectedAudioOutput = deviceId;
            if (!navigator.mediaDevices || typeof HTMLMediaElement.prototype.setSinkId === 'undefined') return;
            try {
                Object.keys(this.peers).forEach((userId) => {
                    const audioEl = document.getElementById('meeting-peer-audio-' + userId);
                    if (audioEl && typeof audioEl.setSinkId === 'function') {
                        audioEl.setSinkId(deviceId).catch(() => {});
                    }
                });
            } catch (e) {
                console.debug('Audio output routing error:', e);
            }
        },

        async startMedia() {
            this.permissionError = null;

            if (this.localStream) {
                stopMediaTracks(this.localStream);
                this.localStream = null;
            }

            const mediaResult = await acquireMediaStreamWithFallback({
                audio: !this.micMuted,
                video: !this.videoOff && !!config.isVideo,
                audioDeviceId: this.selectedAudioInput || null,
                videoDeviceId: this.selectedVideoInput || null,
            });

            if (mediaResult.stream) {
                this.localStream = mediaResult.stream;
                this.micMuted = false;

                if (mediaResult.fallbackUsed) {
                    this.videoOff = true;
                    const isBusy = mediaResult.fallbackReason === 'camera_busy';
                    this.hardwareNotice = {
                        show: true,
                        type: 'warning',
                        message: isBusy
                            ? 'Camera is in use by another app (e.g. Zoom/Teams). Joined meeting with microphone only.'
                            : 'Camera is currently unavailable. Joined with microphone only.',
                        canRetryCamera: true,
                        isRetrying: false,
                    };
                } else {
                    this.videoOff = !mediaResult.hasVideo;
                    this.hardwareNotice = { show: false, type: 'info', message: '', canRetryCamera: false, isRetrying: false };
                }

                if (this.$refs && this.$refs.localVideo) {
                    try {
                        this.$refs.localVideo.srcObject = this.localStream;
                        this.$refs.localVideo.play().catch((e) => console.warn('Video play error:', e));
                    } catch (e) {}
                }
                this.setupLocalAudioMeter(this.localStream);
                await this.refreshHardwareDevices();

                await Promise.all(Object.values(this.peers).map((peer) => {
                    return peer.pc ? this.attachTracksToPeerConnection(peer.pc, this.localStream) : Promise.resolve();
                }));

                if (!this.inPreJoinLobby && this.currentUserId) {
                    this.sendMeetingSignal('peer_join', { userId: this.currentUserId });
                }
            } else if (mediaResult.error) {
                this.hardwareNotice = {
                    show: true,
                    type: 'danger',
                    message: mediaResult.error.message,
                    canRetryCamera: mediaResult.error.type === 'busy',
                    isRetrying: false,
                };
                this.permissionError = mediaResult.error.message;
            }
        },

        async retryAcquireCamera() {
            if (this.hardwareNotice.isRetrying) return;
            this.hardwareNotice.isRetrying = true;

            try {
                const videoConstraints = this.selectedVideoInput
                    ? { deviceId: { exact: this.selectedVideoInput }, width: { ideal: 1280 }, height: { ideal: 720 } }
                    : { width: { ideal: 1280 }, height: { ideal: 720 } };

                const vidStream = await navigator.mediaDevices.getUserMedia({ video: videoConstraints });
                const newVidTrack = vidStream.getVideoTracks()[0];

                if (newVidTrack) {
                    if (!this.localStream) {
                        this.localStream = new MediaStream();
                    }
                    this.localStream.getVideoTracks().forEach((t) => {
                        try { t.stop(); this.localStream.removeTrack(t); } catch (e) {}
                    });
                    this.localStream.addTrack(newVidTrack);

                    this.videoOff = false;
                    this.hardwareNotice.show = false;

                    this.rebindLocalVideo();

                    Object.values(this.peers).forEach(async (peer) => {
                        if (peer.pc) {
                            const vidTrans = getTransceiverByKind(peer.pc, 'video');
                            if (vidTrans?.sender) {
                                await vidTrans.sender.replaceTrack(newVidTrack).catch(() => {});
                            } else {
                                peer.pc.addTrack(newVidTrack, this.localStream);
                            }
                        }
                    });

                    this.sendMeetingSignal('peer_state', { isMuted: this.micMuted, isVideoOff: false });

                    if (window.Alpine?.store('toasts')) {
                        window.Alpine.store('toasts').add('success', 'Camera reconnected to meeting.');
                    }
                }
            } catch (err) {
                console.warn('Meeting retry camera failed:', err);
                const isBusy = (err.name === 'NotReadableError' || err.name === 'TrackStartError');
                this.hardwareNotice.message = isBusy
                    ? 'Camera is still in use by another application. Please close it and retry.'
                    : 'Camera access notice: ' + (err.message || err.name);
            } finally {
                this.hardwareNotice.isRetrying = false;
            }
        },

        toggleMic() {
            resumeAllAudioSinks();
            if (this.micMuted) {
                this.unmuteMic();
            } else {
                this.muteMicCompletely();
            }
        },

        muteMicCompletely() {
            this.micMuted = true;
            this.localAudioLevel = 0;
            this.isLocalSpeaking = false;
            if (this.localStream) {
                this.localStream.getAudioTracks().forEach((t) => {
                    t.enabled = false;
                });
            }
            Object.values(this.peers).forEach((peer) => {
                if (peer.pc) {
                    const audTrans = getTransceiverByKind(peer.pc, 'audio');
                    if (audTrans?.sender?.track) {
                        audTrans.sender.track.enabled = false;
                    }
                }
            });
            this.sendMeetingSignal('peer_state', { isMuted: true, isVideoOff: this.videoOff, isScreenSharing: this.screenSharing });
        },

        async unmuteMic() {
            this.micMuted = false;
            resumeAllAudioSinks();
            let audioTrack = this.localStream ? this.localStream.getAudioTracks()[0] : null;
            if (audioTrack && audioTrack.readyState === 'live') {
                audioTrack.enabled = true;
            } else if (navigator.mediaDevices) {
                try {
                    const audioConstraints = this.selectedAudioInput
                        ? { deviceId: { exact: this.selectedAudioInput } }
                        : true;
                    const audioStream = await navigator.mediaDevices.getUserMedia({ audio: audioConstraints });
                    audioTrack = audioStream.getAudioTracks()[0];
                    if (audioTrack) {
                        if (!this.localStream) {
                            this.localStream = new MediaStream();
                        }
                        this.localStream.getAudioTracks().forEach((t) => {
                            try { t.stop(); this.localStream.removeTrack(t); } catch (e) {}
                        });
                        this.localStream.addTrack(audioTrack);
                        this.setupLocalAudioMeter(this.localStream);
                    }
                } catch (e) {
                    console.warn('Audio resume error:', e);
                }
            }

            if (audioTrack) {
                audioTrack.enabled = true;
                Object.values(this.peers).forEach(async (peer) => {
                    if (peer.pc) {
                        const audTrans = getTransceiverByKind(peer.pc, 'audio');
                        if (audTrans?.sender) {
                            if (audTrans.sender.track) audTrans.sender.track.enabled = true;
                            await audTrans.sender.replaceTrack(audioTrack).catch(() => {});
                        }
                    }
                });
            }

            this.sendMeetingSignal('peer_state', { isMuted: false, isVideoOff: this.videoOff, isScreenSharing: this.screenSharing });
        },

        toggleVideo() {
            resumeAllAudioSinks();
            if (this.videoOff) {
                this.startVideoCompletely();
            } else {
                this.stopVideoCompletely();
            }
        },

        stopVideoCompletely() {
            this.videoOff = true;
            if (this.localStream) {
                this.localStream.getVideoTracks().forEach((t) => {
                    t.enabled = false;
                });
            }
            if (this.$refs && this.$refs.localVideo) {
                try {
                    this.$refs.localVideo.srcObject = null;
                } catch (e) {}
            }
            Object.values(this.peers).forEach((peer) => {
                if (peer.pc) {
                    const vidTrans = getTransceiverByKind(peer.pc, 'video');
                    if (vidTrans?.sender?.track) {
                        vidTrans.sender.track.enabled = false;
                    }
                }
            });
            this.sendMeetingSignal('peer_state', { isMuted: this.micMuted, isVideoOff: true, isScreenSharing: this.screenSharing });
        },

        async startVideoCompletely() {
            this.videoOff = false;
            if (!navigator.mediaDevices) return;
            try {
                let existingTrack = this.localStream ? this.localStream.getVideoTracks()[0] : null;
                if (existingTrack && existingTrack.readyState === 'live') {
                    existingTrack.enabled = true;
                    if (this.$refs && this.$refs.localVideo) {
                        this.$refs.localVideo.srcObject = this.localStream;
                        this.$refs.localVideo.play().catch(() => {});
                    }
                    Object.values(this.peers).forEach(async (peer) => {
                        if (peer.pc) {
                            const vidTrans = getTransceiverByKind(peer.pc, 'video');
                            if (vidTrans?.sender) {
                                if (vidTrans.sender.track) vidTrans.sender.track.enabled = true;
                                await vidTrans.sender.replaceTrack(existingTrack).catch(() => {});
                            }
                        }
                    });
                } else {
                    const videoConstraints = this.selectedVideoInput
                        ? { deviceId: { exact: this.selectedVideoInput }, width: { ideal: 1280 }, height: { ideal: 720 } }
                        : { width: { ideal: 1280 }, height: { ideal: 720 } };
                    const vidStream = await navigator.mediaDevices.getUserMedia({ video: videoConstraints });
                    const newVidTrack = vidStream.getVideoTracks()[0];
                    if (newVidTrack) {
                        if (!this.localStream) {
                            this.localStream = new MediaStream();
                        }
                        this.localStream.getVideoTracks().forEach((t) => {
                            try { t.stop(); this.localStream.removeTrack(t); } catch (e) {}
                        });
                        this.localStream.addTrack(newVidTrack);

                        if (this.$refs && this.$refs.localVideo) {
                            this.$refs.localVideo.srcObject = this.localStream;
                            this.$refs.localVideo.play().catch(() => {});
                        }

                        Object.values(this.peers).forEach(async (peer) => {
                            if (peer.pc) {
                                const vidTrans = getTransceiverByKind(peer.pc, 'video');
                                if (vidTrans?.sender) {
                                    await vidTrans.sender.replaceTrack(newVidTrack).catch(() => {});
                                } else {
                                    peer.pc.addTrack(newVidTrack, this.localStream);
                                }
                            }
                        });
                    }
                }
                this.hardwareNotice.show = false;
            } catch (err) {
                console.warn('Cannot re-acquire video track in meeting:', err);
                this.videoOff = true;
                const isBusy = (err.name === 'NotReadableError' || err.name === 'TrackStartError');
                this.hardwareNotice.message = isBusy ? 'Camera is in use by another app.' : 'Could not access camera.';
                canRetryCamera: true,
                isRetrying = false;
            }
            this.sendMeetingSignal('peer_state', { isMuted: this.micMuted, isVideoOff: this.videoOff, isScreenSharing: this.screenSharing });
        },

        async toggleScreenShare() {
            resumeAllAudioSinks();
            if (!this.screenSharing) {
                if (!config.isScreenShareAllowed && !config.isHostOrCoHost) {
                    if (window.Alpine?.store('toasts')) {
                        window.Alpine.store('toasts').add('warning', 'Screen sharing is disabled by host.');
                    }
                    return;
                }
                try {
                    if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
                        if (window.Alpine?.store('toasts')) {
                            window.Alpine.store('toasts').add('warning', 'Screen sharing is not supported on this browser.');
                        }
                        return;
                    }
                    this.screenStream = await navigator.mediaDevices.getDisplayMedia({ video: true, audio: false });
                    if (this.$refs && this.$refs.localVideo) {
                        this.$refs.localVideo.srcObject = this.screenStream;
                        this.$refs.localVideo.play().catch(() => {});
                    }
                    this.screenSharing = true;
                    const screenTrack = this.screenStream.getVideoTracks()[0];

                    // Replace track on all active peer connections using transceiver
                    Object.values(this.peers).forEach(async (peer) => {
                        if (peer.pc) {
                            const vidTrans = getTransceiverByKind(peer.pc, 'video');
                            if (vidTrans?.sender) {
                                await vidTrans.sender.replaceTrack(screenTrack).catch(() => {});
                            }
                        }
                    });

                    this.sendMeetingSignal('peer_state', { isMuted: this.micMuted, isVideoOff: false, isScreenSharing: true });

                    screenTrack.onended = () => {
                        this.stopScreenShare();
                    };
                } catch (e) {
                    console.warn('Screen share canceled:', e);
                }
            } else {
                this.stopScreenShare();
            }
        },

        stopScreenShare() {
            this.screenSharing = false;
            if (this.screenStream) {
                stopMediaTracks(this.screenStream);
                this.screenStream = null;
            }

            const camTrack = (this.localStream && !this.videoOff) ? this.localStream.getVideoTracks()[0] : null;

            // Revert track on all active peer connections
            Object.values(this.peers).forEach(async (peer) => {
                if (peer.pc) {
                    const vidTrans = getTransceiverByKind(peer.pc, 'video');
                    if (vidTrans?.sender) {
                        await vidTrans.sender.replaceTrack(camTrack || null).catch(() => {});
                    }
                }
            });

            if (this.$refs && this.$refs.localVideo) {
                this.$refs.localVideo.srcObject = (!this.videoOff && this.localStream) ? this.localStream : null;
                if (!this.videoOff && this.localStream) {
                    this.$refs.localVideo.play().catch(() => {});
                }
            }

            this.sendMeetingSignal('peer_state', { isMuted: this.micMuted, isVideoOff: this.videoOff, isScreenSharing: false });
        },

        addFloatingEmoji(emoji, user) {
            const id = Date.now() + Math.random();
            const left = Math.floor(Math.random() * 75) + 12;
            const newEmoji = { id, emoji, user: user || 'Participant', left };
            const currentList = Array.isArray(this.floatingEmojis) ? this.floatingEmojis : [];
            const trimmedList = currentList.length >= 25 ? currentList.slice(-24) : currentList;
            this.floatingEmojis = [...trimmedList, newEmoji];
            this.setTimeoutTracked(() => {
                this.floatingEmojis = (this.floatingEmojis || []).filter((e) => e.id !== id);
            }, 3500);
        },

        sendReaction(emoji) {
            resumeAllAudioSinks();
            const user = this.currentUserName || (this.$wire && this.$wire.currentUser?.name) || 'You';
            // 1. Instantly display on sender screen
            this.addFloatingEmoji(emoji, user);

            // 2. Fast signal to all active peers
            this.sendMeetingSignal('floating_emoji', { emoji: emoji, user: user });

            // 3. Trigger Livewire / server sync
            if (this.$wire && typeof this.$wire.sendReaction === 'function') {
                try {
                    this.$wire.sendReaction(emoji);
                } catch (e) {}
            }
        }
    };
}

// -------------------------------------------------------------
// Universal Alpine and Global Window Registry for Meeting Room
// -------------------------------------------------------------
window.meetingRoomAlpine = meetingRoomAlpine;

export function registerMeetingComponents() {
    window.meetingRoomAlpine = meetingRoomAlpine;

    const alpineInstance = window.Alpine || (window.Livewire && window.Livewire.Alpine);

    if (alpineInstance && typeof alpineInstance.data === 'function') {
        try {
            alpineInstance.data('meetingRoomAlpine', meetingRoomAlpine);
        } catch (e) {
            console.debug('Alpine.data meetingRoomAlpine registration note:', e);
        }
    }
}

// Auto-register immediately upon script execution
registerMeetingComponents();

// Listen across all Alpine and Livewire lifecycle events
['alpine:init', 'alpine:initialized', 'livewire:init', 'livewire:initialized', 'livewire:navigated'].forEach((evt) => {
    document.addEventListener(evt, () => {
        registerMeetingComponents();
    });
});
