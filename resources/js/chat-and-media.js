// Global Alpine components for Chat, Live Chat Pre-Call Preview, Calls, Online Meetings, and Image Editor
// Optimized for Laravel 13, Livewire 4, and seamless SPA (wire:navigate) lifecycle transitions

/**
 * Normalizes Livewire / CustomEvent detail payloads whether they are passed as arrays or objects.
 */
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

/**
 * Safe AudioContext factory with graceful fallback when audio policy or browser limits apply.
 */
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
        console.debug('AudioContext initialization suppressed', e);
        return null;
    }
}

/**
 * Safely stops all tracks on a MediaStream to release camera/mic hardware instantly.
 */
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
        console.warn('Error stopping media tracks:', e);
    }
}

/**
 * Ensures an active, permanent HTML5 Audio sink outside of conditional Blade/Alpine DOM trees.
 * This guarantees browser audio output is never halted when video is toggled or layouts change.
 */
// Global registry for audio elements awaiting autoplay gesture resolution
window._pendingChatAudioSinks = window._pendingChatAudioSinks || new Set();

function resumeAllChatAudioSinks() {
    if (window._pendingChatAudioSinks && window._pendingChatAudioSinks.size > 0) {
        window._pendingChatAudioSinks.forEach((audioEl) => {
            if (audioEl && typeof audioEl.play === 'function') {
                audioEl.play().then(() => {
                    window._pendingChatAudioSinks.delete(audioEl);
                }).catch(() => {});
            }
        });
    }
}

if (typeof window !== 'undefined') {
    ['click', 'touchstart', 'keydown', 'pointerdown'].forEach((evt) => {
        window.addEventListener(evt, resumeAllChatAudioSinks, { passive: true });
    });
}

function ensureAudioSink(elementId, stream) {
    if (!stream || typeof document === 'undefined') return null;
    let container = document.getElementById('webrtc-global-audio-sink');
    if (!container) {
        container = document.createElement('div');
        container.id = 'webrtc-global-audio-sink';
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

    // Ensure all audio tracks on the incoming stream are enabled
    if (typeof stream.getAudioTracks === 'function') {
        stream.getAudioTracks().forEach((track) => {
            track.enabled = true;
            track.onunmute = () => {
                if (audioEl) {
                    audioEl.play().catch((e) => {
                        window._pendingChatAudioSinks.add(audioEl);
                    });
                }
            };
        });
    }

    if (audioEl.srcObject !== stream) {
        audioEl.srcObject = stream;
    }

    audioEl.muted = false;
    const playPromise = audioEl.play();
    if (playPromise !== undefined) {
        playPromise.then(() => {
            window._pendingChatAudioSinks.delete(audioEl);
        }).catch((e) => {
            console.debug(`Audio playback autoplay policy handled for ${elementId}:`, e);
            window._pendingChatAudioSinks.add(audioEl);
        });
    }
    return audioEl;
}

/**
 * Safely stops and removes an audio sink when a peer disconnects or call ends.
 */
function removeAudioSink(elementId) {
    if (typeof document === 'undefined') return;
    const audioEl = document.getElementById(elementId);
    if (audioEl) {
        try {
            audioEl.srcObject = null;
            audioEl.pause();
            audioEl.remove();
        } catch (e) {}
    }
}

/**
 * Checks if a value is null, undefined, empty string, false, or 0.
 */
function emptyOrFalse(val) {
    if (val === null || val === undefined || val === false || val === '' || val === 0 || val === '0') return true;
    return false;
}

/**
 * Safely parses or converts any SDP data representation into a valid RTCSessionDescription.
 */
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

/**
 * Safely adds an ICE candidate to an RTCPeerConnection handling all format variations.
 */
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
            console.debug('ICE candidate addition notice:', err);
        }
    }
}

/**
 * Ensures deterministic RTCPeerConnection transceivers (Audio = Index 0, Video = Index 1).
 * This permanently eliminates RFC 8829 m-line ordering mismatch errors (InvalidAccessError)
 * and guarantees proper media stream association.
 */
function ensureDeterministicTransceivers(pc, stream = null) {
    if (!pc || typeof pc.getTransceivers !== 'function') return { audio: null, video: null };
    let transceivers = pc.getTransceivers();
    let audioTransceiver = transceivers.find((t, idx) => (t.receiver && t.receiver.track && t.receiver.track.kind === 'audio') || (t.sender && t.sender.track && t.sender.track.kind === 'audio') || t.mid === '0' || idx === 0);
    let videoTransceiver = transceivers.find((t, idx) => (t.receiver && t.receiver.track && t.receiver.track.kind === 'video') || (t.sender && t.sender.track && t.sender.track.kind === 'video') || t.mid === '1' || idx === 1);

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
            try { audioTransceiver.sender.replaceTrack(audioTrack); } catch (e) {}
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
            try { videoTransceiver.sender.replaceTrack(videoTrack); } catch (e) {}
        }
        try { videoTransceiver.direction = 'sendrecv'; } catch (e) {}
    }

    return { audio: audioTransceiver, video: videoTransceiver };
}

/**
 * Diagnoses media hardware availability, device presence, and permission state.
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

// -------------------------------------------------------------
// 1. Live Chat Pre-Call Camera & Microphone Preview Component
// -------------------------------------------------------------
export function chatPreCallPreviewAlpine(config = {}) {
    return {
        isOpen: false,
        callType: 'video', // 'video' or 'audio'
        isGroup: false,
        peerName: '',
        peerAvatar: null,
        targetUserId: null,
        conversationId: null,
        micMuted: false,
        videoOff: false,
        isMirrored: true,
        localStream: null,
        permissionError: null,
        audioLevel: 0,
        audioContext: null,
        analyser: null,
        animFrameId: null,
        isLoadingMedia: false,
        audioInputs: [],
        videoInputs: [],
        audioOutputs: [],
        selectedAudioInput: '',
        selectedVideoInput: '',
        selectedAudioOutput: '',
        isTestingSpeaker: false,
        _listeners: [],
        _timers: [],

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

        init() {
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => {
                    this.destroy();
                });
            }

            this.bindListener(window, 'open-pre-call-preview', (e) => {
                const data = extractEventData(e.detail);
                this.openPreview(data);
            });

            this.bindListener(window, 'beforeunload', () => {
                this.stopPreviewMedia();
            });

            this.bindListener(window, 'pagehide', () => {
                this.stopPreviewMedia();
            });

            this.bindListener(document, 'livewire:navigating', () => {
                this.stopPreviewMedia();
                this.isOpen = false;
            });

            if (navigator.mediaDevices && typeof navigator.mediaDevices.addEventListener === 'function') {
                this.bindListener(navigator.mediaDevices, 'devicechange', () => {
                    this.enumerateDevices();
                });
            }
        },

        destroy() {
            this.stopPreviewMedia();
            this.clearAllTimers();
            this.clearAllListeners();
            this.isOpen = false;
        },

        async openPreview(raw) {
            const data = extractEventData(raw);
            const rawType = (data.callType || data.type || (typeof raw === 'string' ? raw : '')).toLowerCase();
            this.callType = (rawType === 'audio' || rawType === 'voice') ? 'audio' : 'video';
            this.isGroup = !!data.isGroup;
            this.peerName = data.peerName || '';
            this.peerAvatar = data.peerAvatar || null;
            this.targetUserId = data.targetUserId || null;
            this.conversationId = data.conversationId || null;
            this.micMuted = false;
            this.videoOff = this.callType === 'audio';
            this.isMirrored = true;
            this.permissionError = null;
            this.isOpen = true;

            await this.startPreviewMedia();
            await this.enumerateDevices();
        },

        async enumerateDevices() {
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
                console.debug('Device enumeration notice:', e);
            }
        },

        async startPreviewMedia() {
            this.stopPreviewMedia();
            this.isLoadingMedia = true;
            this.permissionError = null;

            const isAudioOnly = this.callType === 'audio';
            const result = await acquireMediaStreamWithFallback({
                audio: true,
                video: !isAudioOnly && !this.videoOff,
                audioDeviceId: this.selectedAudioInput || null,
                videoDeviceId: this.selectedVideoInput || null,
            });

            this.isLoadingMedia = false;

            if (result.stream) {
                this.localStream = result.stream;

                if (result.fallbackUsed) {
                    this.videoOff = true;
                    if (result.fallbackReason === 'camera_busy') {
                        this.permissionError = 'Camera is in use by another application. Previewing with microphone audio.';
                    } else {
                        this.permissionError = 'Camera not available. Previewing with microphone audio.';
                    }
                }

                if (typeof this.$nextTick === 'function') {
                    this.$nextTick(() => {
                        if (this.$refs && this.$refs.preCallVideo && !isAudioOnly && !this.videoOff && this.localStream) {
                            try {
                                this.$refs.preCallVideo.srcObject = this.localStream;
                                this.$refs.preCallVideo.play().catch(() => {});
                            } catch (e) {}
                        }
                    });
                }

                this.setupAudioMeter(this.localStream);
                await this.enumerateDevices();
            } else if (result.error) {
                this.permissionError = result.error.message;
            }
        },

        async retryHardwareAcquisition() {
            await this.startPreviewMedia();
        },

        setupAudioMeter(stream) {
            try {
                if (!stream) return;
                const audioTracks = stream.getAudioTracks();
                if (!audioTracks || audioTracks.length === 0) return;

                this.audioContext = createSafeAudioContext();
                if (!this.audioContext) return;

                const source = this.audioContext.createMediaStreamSource(stream);
                this.analyser = this.audioContext.createAnalyser();
                this.analyser.fftSize = 32;
                source.connect(this.analyser);

                const bufferLength = this.analyser.frequencyBinCount;
                const dataArray = new Uint8Array(bufferLength);

                const updateVolume = () => {
                    if (!this.isOpen || !this.analyser) return;
                    try {
                        this.analyser.getByteFrequencyData(dataArray);
                        let sum = 0;
                        for (let i = 0; i < bufferLength; i++) {
                            sum += dataArray[i];
                        }
                        const avg = sum / bufferLength;
                        this.audioLevel = this.micMuted ? 0 : Math.min(100, Math.round((avg / 128) * 100));
                        this.animFrameId = requestAnimationFrame(updateVolume);
                    } catch (e) {
                        this.audioLevel = 0;
                    }
                };

                updateVolume();
            } catch (e) {
                console.debug('Audio meter setup notice:', e);
            }
        },

        toggleMic() {
            this.micMuted = !this.micMuted;
            if (this.localStream) {
                this.localStream.getAudioTracks().forEach((t) => {
                    t.enabled = !this.micMuted;
                });
            }
            if (this.micMuted) {
                this.audioLevel = 0;
            }
        },

        async toggleVideo() {
            if (this.callType !== 'video') return;

            this.videoOff = !this.videoOff;
            if (this.videoOff) {
                if (this.localStream) {
                    this.localStream.getVideoTracks().forEach((t) => {
                        t.enabled = false;
                        try { t.stop(); } catch (e) {}
                    });
                }
                if (this.$refs && this.$refs.preCallVideo) {
                    this.$refs.preCallVideo.srcObject = null;
                }
            } else {
                try {
                    const videoConstraints = this.selectedVideoInput
                        ? { deviceId: { exact: this.selectedVideoInput }, width: { ideal: 1280 }, height: { ideal: 720 } }
                        : { width: { ideal: 1280 }, height: { ideal: 720 } };

                    const vidStream = await navigator.mediaDevices.getUserMedia({ video: videoConstraints });
                    const newVidTrack = vidStream.getVideoTracks()[0];
                    if (this.localStream && newVidTrack) {
                        this.localStream.getVideoTracks().forEach((t) => {
                            try { this.localStream.removeTrack(t); } catch (e) {}
                        });
                        this.localStream.addTrack(newVidTrack);
                        if (this.$refs && this.$refs.preCallVideo) {
                            this.$refs.preCallVideo.srcObject = this.localStream;
                            this.$refs.preCallVideo.play().catch(() => {});
                        }
                    }
                } catch (err) {
                    console.warn('Cannot acquire camera track:', err);
                    this.videoOff = true;
                    this.permissionError = 'Could not access camera. Check device permissions.';
                }
            }
        },

        toggleMirror() {
            this.isMirrored = !this.isMirrored;
        },

        async onAudioInputChange() {
            await this.startPreviewMedia();
        },

        async onVideoInputChange() {
            if (this.callType === 'video' && !this.videoOff) {
                await this.startPreviewMedia();
            }
        },

        onAudioOutputChange() {
            if (this.selectedAudioOutput && this.$refs && this.$refs.preCallVideo && typeof this.$refs.preCallVideo.setSinkId === 'function') {
                this.$refs.preCallVideo.setSinkId(this.selectedAudioOutput).catch(() => {});
            }
        },

        testSpeakerSound() {
            if (this.isTestingSpeaker) return;
            this.isTestingSpeaker = true;
            try {
                const ctx = createSafeAudioContext();
                if (!ctx) {
                    this.isTestingSpeaker = false;
                    return;
                }
                const now = ctx.currentTime;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                osc.type = 'sine';
                osc.frequency.setValueAtTime(523.25, now); // C5
                osc.frequency.exponentialRampToValueAtTime(659.25, now + 0.15); // E5
                osc.frequency.exponentialRampToValueAtTime(783.99, now + 0.3); // G5
                gain.gain.setValueAtTime(0.08, now);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.5);
                osc.connect(gain);
                gain.connect(ctx.destination);
                osc.start(now);
                osc.stop(now + 0.5);
                setTimeout(() => {
                    try { ctx.close(); } catch (e) {}
                    this.isTestingSpeaker = false;
                }, 600);
            } catch (e) {
                this.isTestingSpeaker = false;
            }
        },

        async retryPermissions() {
            await this.startPreviewMedia();
        },

        startCallNow() {
            if (window.WebSocketState && window.WebSocketState.isConfigured && !window.WebSocketState.isConnected) {
                if (window.Alpine && window.Alpine.store && window.Alpine.store('toasts')) {
                    window.Alpine.store('toasts').add('warning', 'Live WebSockets connection required for calling. Connecting to WebSocket server...');
                }
                window.reconnectWebSocket?.();
                return;
            }

            const isAudioOnly = this.callType === 'audio';
            const callData = {
                receiverId: this.targetUserId,
                type: isAudioOnly ? 'audio' : 'video',
                conversationId: this.conversationId,
                isGroup: this.isGroup,
                startMuted: this.micMuted,
                startVideoOff: isAudioOnly ? true : this.videoOff,
                preferredAudioInput: this.selectedAudioInput,
                preferredVideoInput: this.selectedVideoInput,
                preferredAudioOutput: this.selectedAudioOutput,
            };

            this.stopPreviewMedia();
            this.isOpen = false;

            if (this.isGroup) {
                window.dispatchEvent(new CustomEvent('start-group-call-confirmed', { detail: callData }));
                if (this.$wire && typeof this.$wire.launchConfirmedGroupCall === 'function') {
                    this.$wire.launchConfirmedGroupCall(callData.conversationId, callData.type, callData.startMuted, callData.startVideoOff);
                } else {
                    window.dispatchEvent(new CustomEvent('start-group-call', { detail: callData }));
                }
            } else {
                window.dispatchEvent(new CustomEvent('start-direct-call-confirmed', { detail: callData }));
                if (this.$wire && typeof this.$wire.launchConfirmedCall === 'function') {
                    this.$wire.launchConfirmedCall(callData.receiverId, callData.type, callData.conversationId, callData.startMuted, callData.startVideoOff);
                } else {
                    window.dispatchEvent(new CustomEvent('start-call', { detail: callData }));
                }
            }
        },

        cancelPreview() {
            this.stopPreviewMedia();
            this.isOpen = false;
        },

        stopPreviewMedia() {
            if (this.animFrameId) {
                cancelAnimationFrame(this.animFrameId);
                this.animFrameId = null;
            }
            if (this.audioContext) {
                try {
                    this.audioContext.close();
                } catch (e) {}
                this.audioContext = null;
            }
            if (this.localStream) {
                stopMediaTracks(this.localStream);
                this.localStream = null;
            }
            if (this.$refs && this.$refs.preCallVideo) {
                try {
                    this.$refs.preCallVideo.srcObject = null;
                } catch (e) {}
            }
            this.audioLevel = 0;
            this.isLoadingMedia = false;
        }
    };
}

// -------------------------------------------------------------
// 2. Active WebRTC Call Overlay Component (Direct & Multi-Party Group Calling)
// -------------------------------------------------------------
export function chatCallOverlayAlpine(config = {}) {
    return {
        currentUserId: config.currentUserId || 0,
        localCallStatus: 'idle',
        localCallType: 'video',
        localPeerName: '',
        localPeerAvatar: null,
        isGroup: false,
        isMinimized: false,
        layoutMode: 'grid', // 'grid', 'speaker', 'pip'
        pinnedUserId: null,
        showParticipantsDrawer: false,
        showDeviceSettingsModal: false,
        showSwitchModeModal: false,
        requestedSwitchType: 'video',
        switchRequesterName: '',
        switchTimeoutTimer: null,
        audioAutoplayBlocked: false,
        connectionQuality: 'good', // 'good', 'fair', 'poor', 'reconnecting'
        hardwareNotice: {
            show: false,
            type: 'warning', // 'warning', 'danger', 'info'
            message: '',
            canRetryCamera: false,
            isRetrying: false,
        },

        // Hardware device lists
        availableCameras: [],
        availableMics: [],
        availableSpeakers: [],
        selectedCameraId: '',
        selectedMicId: '',
        selectedSpeakerId: '',

        get callStatus() {
            return this.localCallStatus || this.$wire?.callStatus || 'idle';
        },
        set callStatus(val) {
            this.localCallStatus = val;
            if (this.$wire && 'callStatus' in this.$wire) {
                try { this.$wire.callStatus = val; } catch (e) {}
            }
        },
        get callType() {
            const raw = (this.localCallType || this.$wire?.callType || 'video').toLowerCase();
            return (raw === 'audio' || raw === 'voice' || raw === 'phone') ? 'audio' : 'video';
        },
        set callType(val) {
            const raw = (val || 'video').toLowerCase();
            const normalized = (raw === 'audio' || raw === 'voice' || raw === 'phone') ? 'audio' : 'video';
            this.localCallType = normalized;
            if (this.$wire && 'callType' in this.$wire) {
                try { this.$wire.callType = normalized; } catch (e) {}
            }
        },
        get peerName() {
            return this.localPeerName || this.$wire?.peerName || '';
        },
        set peerName(val) {
            this.localPeerName = val;
            if (this.$wire && 'peerName' in this.$wire) {
                try { this.$wire.peerName = val; } catch (e) {}
            }
        },
        get peerAvatar() {
            return this.localPeerAvatar || this.$wire?.peerAvatar || null;
        },
        set peerAvatar(val) {
            this.localPeerAvatar = val;
            if (this.$wire && 'peerAvatar' in this.$wire) {
                try { this.$wire.peerAvatar = val; } catch (e) {}
            }
        },

        localStream: null,
        remoteStream: null,
        screenStream: null,
        peerConnection: null, // For 1-on-1
        peers: {}, // For Group Calling
        activeCallUuid: '',
        websocketConnected: window.WebSocketState ? window.WebSocketState.isConnected : false,
        isMuted: false,
        isVideoOff: false,
        remoteMuted: false,
        remoteVideoOff: false,
        isScreenSharing: false,
        durationSeconds: 0,
        timerInterval: null,
        isRinging: false,
        ringtoneContext: null,
        ringtoneInterval: null,
        isProcessingOffer: false,
        pendingOffer: null,
        pendingIceCandidates: [],
        localAudioLevel: 0,
        isLocalSpeaking: false,
        remoteAudioLevel: 0,
        localAudioCtx: null,
        localAnalyser: null,
        remoteAudioCtx: null,
        remoteAnalyser: null,
        audioMeterFrame: null,
        iceServers: config.iceServers && config.iceServers.length > 0 ? config.iceServers : [{ urls: 'stun:stun.l.google.com:19302' }],
        _listeners: [],
        _timers: [],

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

        isPeerMuted(userId) {
            return !!this.peers[Number(userId)]?.isMuted;
        },

        isPeerVideoOff(userId) {
            const peer = this.peers[Number(userId)];
            if (!peer) return true;
            return peer.isVideoOff !== undefined ? peer.isVideoOff : (peer.stream ? peer.stream.getVideoTracks().length === 0 : true);
        },

        isPeerSpeaking(userId) {
            return !!this.peers[Number(userId)]?.isSpeaking;
        },

        getPeerAudioLevel(userId) {
            return this.peers[Number(userId)]?.audioLevel || 0;
        },

        get connectedPeersCount() {
            return Object.keys(this.peers).length;
        },

        get allParticipantsCount() {
            return Object.keys(this.peers).length + 1;
        },

        toggleMinimize() {
            this.isMinimized = !this.isMinimized;
            if (!this.isMinimized) {
                this.$nextTick(() => {
                    this.rebindAllRemoteVideos();
                    this.rebindLocalVideo();
                });
            }
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

        init() {
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => {
                    this.destroy();
                });
            }

            const uid = this.currentUserId || (this.$wire && this.$wire.currentUserId);
            if (uid) {
                this.subscribeUserEchoChannel(uid);
            }

            this.websocketConnected = window.WebSocketState ? window.WebSocketState.isConnected : false;
            this.bindListener(window, 'websocket-status-changed', (e) => {
                const detail = extractEventData(e.detail);
                this.websocketConnected = !!detail.isConnected;
            });

            this.bindListener(window, 'incoming-call-received', (e) => {
                const payload = extractEventData(e.detail);
                if (this.callStatus === 'connected') {
                    return;
                }
                if (typeof window !== 'undefined' && (window.location?.pathname?.includes('/meetings/room/') || document.getElementById('meeting-room-container'))) {
                    console.info('Live Chat incoming call suppressed while user is actively in a meeting room');
                    if (window.Alpine && typeof window.Alpine.store === 'function' && window.Alpine.store('toasts')) {
                        const name = payload.callerName || payload.peerName || 'A contact';
                        window.Alpine.store('toasts').add('info', `${name} is calling you via Live Chat. Please finish your active meeting first.`);
                    }
                    return;
                }
                if (this.callStatus === 'incoming' && this.activeCallUuid && payload.callUuid && this.activeCallUuid === payload.callUuid) {
                    return;
                }
                const rawType = (payload.callType || payload.call_type || payload.type || '').toLowerCase();
                this.callType = (rawType === 'audio' || rawType === 'voice' || rawType === 'phone') ? 'audio' : 'video';
                this.isGroup = !!payload.is_group || !!payload.isGroup;
                if (payload.callerName || payload.peerName) {
                    this.peerName = payload.callerName || payload.peerName;
                }
                if (payload.callerAvatar || payload.peerAvatar) {
                    this.peerAvatar = payload.callerAvatar || payload.peerAvatar;
                }
                if (payload.callUuid) {
                    this.activeCallUuid = payload.callUuid;
                    this.subscribeCallEchoChannel(payload.callUuid);
                }
                this.pendingOffer = null;
                this.callStatus = 'incoming';
                this.isMinimized = false;
                this.playRingtone(true);
            });

            this.bindListener(window, 'webrtc-call-started', (e) => {
                const payload = extractEventData(e.detail);
                const rawType = (payload.callType || payload.call_type || payload.type || this.callType || 'video').toLowerCase();
                const callType = (rawType === 'audio' || rawType === 'voice' || rawType === 'phone') ? 'audio' : 'video';
                this.callType = callType;
                this.callStatus = 'outgoing';
                this.durationSeconds = 0;
                this.isMinimized = false;
                this.isGroup = !!payload.is_group || !!payload.isGroup;
                this.isMuted = !!payload.startMuted;
                this.isVideoOff = callType === 'audio' || !!payload.startVideoOff;
                if (payload.iceServers && payload.iceServers.length > 0) {
                    this.iceServers = payload.iceServers;
                }
                if (payload.callerName || payload.peerName) {
                    this.peerName = payload.callerName || payload.peerName;
                }
                if (payload.callerAvatar || payload.peerAvatar) {
                    this.peerAvatar = payload.callerAvatar || payload.peerAvatar;
                }
                if (payload.callUuid) {
                    this.activeCallUuid = payload.callUuid;
                    this.subscribeCallEchoChannel(payload.callUuid);
                }
                this.playRingtone(false);
                this.initWebRtc(this.iceServers, true, callType, this.isMuted, this.isVideoOff, this.isGroup, payload.peerUserId);
            });

            this.bindListener(window, 'webrtc-call-accepted', async (e) => {
                this.stopRingtone();
                const payload = extractEventData(e.detail);
                const rawType = (payload.callType || payload.call_type || payload.type || this.callType || 'video').toLowerCase();
                const callType = (rawType === 'audio' || rawType === 'voice' || rawType === 'phone') ? 'audio' : 'video';
                this.callType = callType;
                this.callStatus = 'connected';
                this.startTimer();
                this.isGroup = !!payload.is_group || !!payload.isGroup;
                this.isVideoOff = callType === 'audio';
                if (payload.iceServers && payload.iceServers.length > 0) {
                    this.iceServers = payload.iceServers;
                }
                if (payload.callUuid) {
                    this.activeCallUuid = payload.callUuid;
                    this.subscribeCallEchoChannel(payload.callUuid);
                }
                await this.initWebRtc(this.iceServers, false, callType, this.isMuted, this.isVideoOff, this.isGroup, payload.peerUserId);

                if (!this.isGroup && this.pendingOffer && this.peerConnection) {
                    try {
                        const desc = toSessionDescription(this.pendingOffer, 'offer');
                        if (desc) {
                            await this.peerConnection.setRemoteDescription(desc);
                            if (this.localStream) {
                                await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                            }
                            this.drainPendingIceCandidates();
                            const answer = await this.peerConnection.createAnswer();
                            await this.peerConnection.setLocalDescription(answer);
                            this.sendDirectSignal('answer', { sdp: this.peerConnection.localDescription || answer }, payload.peerUserId);
                            this.pendingOffer = null;
                        }
                    } catch (err) {
                        console.warn('Pending offer processing notice:', err);
                    }
                } else if (!this.isGroup && payload.peerUserId) {
                    this.sendDirectSignal('call_accepted', { userId: this.currentUserId }, payload.peerUserId);
                    this.sendDirectSignal('peer_presence', { userId: this.currentUserId }, payload.peerUserId);
                }
            });

            this.bindListener(window, 'webrtc-call-connected', () => {
                this.stopRingtone();
                this.callStatus = 'connected';
                this.startTimer();
            });

            this.bindListener(window, 'webrtc-signal-received', async (e) => {
                const signal = extractEventData(e.detail);
                await this.handleIncomingSignal(signal);
            });

            this.bindListener(window, 'webrtc-call-ended', () => {
                this.cleanupWebRtc();
            });

            this.bindListener(window, 'call-terminated-remotely', (e) => {
                const detail = extractEventData(e?.detail);
                if (detail && detail.message) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'info', message: detail.message } }));
                }
                this.cleanupWebRtc();
            });

            this.bindListener(window, 'webrtc-call-type-switched', async (e) => {
                const detail = extractEventData(e.detail);
                const newType = (detail.callType || 'video').toLowerCase();
                await this.executeCallModeSwitch(newType);
            });

            this.bindListener(window, 'beforeunload', () => {
                this.cleanupWebRtc();
            });

            this.bindListener(window, 'pagehide', () => {
                this.cleanupWebRtc();
            });

            this.bindListener(document, 'livewire:navigating', () => {
                this.cleanupWebRtc();
            });

            if (navigator.mediaDevices && typeof navigator.mediaDevices.addEventListener === 'function') {
                this.bindListener(navigator.mediaDevices, 'devicechange', () => {
                    this.refreshHardwareDevices();
                });
            }
        },

        destroy() {
            this.cleanupWebRtc();
            this.clearAllListeners();
        },

        subscribeUserEchoChannel(userId) {
            if (!userId || !window.Echo || typeof window.Echo.private !== 'function') return;
            try {
                window.Echo.private(`user.${userId}`)
                    .listen('.WebRtcCallSignal', (sig) => this.handleUserBroadcastedSignal(sig))
                    .listen('WebRtcCallSignal', (sig) => this.handleUserBroadcastedSignal(sig));
            } catch (e) {
                console.debug('User Echo call subscription notice:', e);
            }
        },

        async handleUserBroadcastedSignal(signal) {
            if (!signal) return;
            const fromUserId = signal.senderUserId || signal.sender_user_id || signal.fromUserId || signal.from_user_id;
            const myUserId = this.currentUserId || (this.$wire && this.$wire.currentUserId);
            if (fromUserId && myUserId && (Number(fromUserId) === Number(myUserId))) {
                return;
            }

            const type = signal.signalType || signal.signal_type || signal.type;
            const payload = signal.payload || {};
            const callUuid = signal.callUuid || signal.call_uuid || (payload && (payload.call_uuid || payload.callUuid));

            if (type === 'incoming_call' && (this.callStatus === 'idle' || !this.callStatus)) {
                this.activeCallUuid = callUuid;
                const rawType = (payload.call_type || payload.callType || payload.type || 'video').toLowerCase();
                this.callType = (rawType === 'audio' || rawType === 'voice' || rawType === 'phone') ? 'audio' : 'video';
                this.isGroup = !!payload.is_group || !!payload.isGroup;
                this.peerName = payload.caller_name || payload.callerName || (this.isGroup ? (payload.group_title || 'Group Call') : '');
                this.peerAvatar = payload.caller_avatar || payload.callerAvatar || null;
                this.callStatus = 'incoming';
                this.isMinimized = false;
                this.pendingOffer = null;
                this.subscribeCallEchoChannel(callUuid);
                this.playRingtone(true);

                if (this.$wire && typeof this.$wire.handleIncomingBroadcastedCall === 'function') {
                    this.$wire.handleIncomingBroadcastedCall(callUuid, this.callType, fromUserId, this.peerName, this.peerAvatar, this.isGroup);
                }
                return;
            }

            if (type === 'call_rejected' || type === 'call_ended' || type === 'call_declined') {
                this.cleanupWebRtc();
                if (this.$wire && typeof this.$wire.resetCallState === 'function') {
                    this.$wire.resetCallState();
                }
                return;
            }

            if (this.activeCallUuid && callUuid === this.activeCallUuid) {
                await this.handleIncomingSignal(signal);
            }
        },

        subscribeCallEchoChannel(uuid) {
            if (!uuid || !window.Echo || typeof window.Echo.private !== 'function') return;
            try {
                window.Echo.private(`call.${uuid}`)
                    .listen('.WebRtcCallSignal', (sig) => this.handleIncomingSignal(sig))
                    .listen('WebRtcCallSignal', (sig) => this.handleIncomingSignal(sig));
            } catch (e) {
                console.debug('Direct Echo call subscription notice:', e);
            }
        },

        sendDirectSignal(signalType, payload = {}, targetUserId = null) {
            if (this.$wire && typeof this.$wire.sendSignalPayload === 'function') {
                try {
                    this.$wire.sendSignalPayload(signalType, payload, targetUserId);
                } catch (e) {
                    console.debug('Direct signal transmission notice:', e);
                }
            }
        },

        async refreshHardwareDevices() {
            if (!navigator.mediaDevices || typeof navigator.mediaDevices.enumerateDevices !== 'function') return;
            try {
                const devices = await navigator.mediaDevices.enumerateDevices();
                this.availableMics = devices
                    .filter((d) => d.kind === 'audioinput')
                    .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Microphone ${i + 1}` }));
                this.availableCameras = devices
                    .filter((d) => d.kind === 'videoinput')
                    .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Camera ${i + 1}` }));
                this.availableSpeakers = devices
                    .filter((d) => d.kind === 'audiooutput')
                    .map((d, i) => ({ deviceId: d.deviceId, label: d.label || `Speaker ${i + 1}` }));
            } catch (e) {
                console.debug('In-call device refresh notice:', e);
            }
        },

        async switchCameraDevice(deviceId) {
            this.selectedCameraId = deviceId;
            if (this.callType === 'audio' || this.isVideoOff) return;

            try {
                const constraints = { deviceId: { exact: deviceId }, width: { ideal: 1280 }, height: { ideal: 720 } };
                const newStream = await navigator.mediaDevices.getUserMedia({ video: constraints });
                const newVideoTrack = newStream.getVideoTracks()[0];
                if (!newVideoTrack) return;

                if (this.localStream) {
                    const oldTracks = this.localStream.getVideoTracks();
                    oldTracks.forEach((t) => {
                        try { this.localStream.removeTrack(t); t.stop(); } catch (e) {}
                    });
                    this.localStream.addTrack(newVideoTrack);
                }

                if (this.$refs && this.$refs.localVideo && this.localStream) {
                    this.$refs.localVideo.srcObject = this.localStream;
                }

                // Replace track on 1-on-1 peer connection
                if (this.peerConnection) {
                    const sender = this.peerConnection.getSenders().find((s) => s.track && s.track.kind === 'video');
                    if (sender) {
                        await sender.replaceTrack(newVideoTrack);
                    }
                }

                // Replace track on group peer connections
                Object.values(this.peers).forEach(async (peer) => {
                    if (peer.pc) {
                        const sender = peer.pc.getSenders().find((s) => s.track && s.track.kind === 'video');
                        if (sender) {
                            await sender.replaceTrack(newVideoTrack);
                        }
                    }
                });
            } catch (e) {
                console.warn('Camera device switch error:', e);
            }
        },

        async switchMicrophoneDevice(deviceId) {
            this.selectedMicId = deviceId;
            try {
                const constraints = { deviceId: { exact: deviceId }, echoCancellation: true, noiseSuppression: true, autoGainControl: true };
                const newStream = await navigator.mediaDevices.getUserMedia({ audio: constraints });
                const newAudioTrack = newStream.getAudioTracks()[0];
                if (!newAudioTrack) return;

                newAudioTrack.enabled = !this.isMuted;

                if (this.localStream) {
                    const oldTracks = this.localStream.getAudioTracks();
                    oldTracks.forEach((t) => {
                        try { this.localStream.removeTrack(t); t.stop(); } catch (e) {}
                    });
                    this.localStream.addTrack(newAudioTrack);
                }

                this.setupLocalAudioMeter(this.localStream);

                if (this.peerConnection) {
                    const sender = this.peerConnection.getSenders().find((s) => s.track && s.track.kind === 'audio');
                    if (sender) {
                        await sender.replaceTrack(newAudioTrack);
                    }
                }

                Object.values(this.peers).forEach(async (peer) => {
                    if (peer.pc) {
                        const sender = peer.pc.getSenders().find((s) => s.track && s.track.kind === 'audio');
                        if (sender) {
                            await sender.replaceTrack(newAudioTrack);
                        }
                    }
                });
            } catch (e) {
                console.warn('Microphone device switch error:', e);
            }
        },

        switchAudioOutputDevice(deviceId) {
            this.selectedSpeakerId = deviceId;
            if (this.$refs && this.$refs.remoteAudio && typeof this.$refs.remoteAudio.setSinkId === 'function') {
                this.$refs.remoteAudio.setSinkId(deviceId).catch(() => {});
            }
            if (this.$refs && this.$refs.remoteVideo && typeof this.$refs.remoteVideo.setSinkId === 'function') {
                this.$refs.remoteVideo.setSinkId(deviceId).catch(() => {});
            }
            Object.keys(this.peers).forEach((userId) => {
                const audioEl = document.getElementById('remote-call-audio-' + userId);
                if (audioEl && typeof audioEl.setSinkId === 'function') {
                    audioEl.setSinkId(deviceId).catch(() => {});
                }
            });
        },

        unlockAutoplayAudio() {
            this.audioAutoplayBlocked = false;
            if (this.$refs && this.$refs.remoteAudio) {
                this.$refs.remoteAudio.play().catch(() => {});
            }
            if (this.$refs && this.$refs.remoteVideo) {
                this.$refs.remoteVideo.play().catch(() => {});
            }
            Object.keys(this.peers).forEach((userId) => {
                const audioEl = document.getElementById('remote-call-audio-' + userId);
                if (audioEl) audioEl.play().catch(() => {});
            });
        },

        processedSignalIds: new Set(),
        makingOffer: false,
        ignoreOffer: false,

        async drainPendingIceCandidates() {
            if (!this.peerConnection || !this.peerConnection.remoteDescription || !this.peerConnection.remoteDescription.type) return;
            while (this.pendingIceCandidates && this.pendingIceCandidates.length > 0) {
                const cand = this.pendingIceCandidates.shift();
                await addSafeIceCandidate(this.peerConnection, cand);
            }
        },

        getOrCreateGroupPeerConnection(userId, userName = '', userAvatar = null) {
            const id = Number(userId);
            if (!this.peers[id]) {
                const pc = new RTCPeerConnection({
                    iceServers: (this.iceServers && this.iceServers.length > 0) ? this.iceServers : [{ urls: 'stun:stun.l.google.com:19302' }]
                });

                // Guarantee fixed deterministic transceivers: Audio (m=0), Video (m=1)
                ensureDeterministicTransceivers(pc, this.localStream);

                const peerObj = {
                    userId: id,
                    name: userName || `Participant ${id}`,
                    avatar: userAvatar || null,
                    pc: pc,
                    stream: new MediaStream(),
                    pendingCandidates: [],
                    isMuted: false,
                    isVideoOff: this.callType === 'audio',
                    audioLevel: 0,
                    isSpeaking: false,
                    audioCtx: null,
                    analyser: null,
                    makingOffer: false,
                    ignoreOffer: false,
                };

                this.peers = { ...this.peers, [id]: peerObj };

                if (this.localStream) {
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

                    if (event.track.kind === 'audio') {
                        event.track.enabled = !this.peers[id].isMuted;
                        ensureAudioSink('group-peer-audio-' + id, stream);
                        this.setupRemotePeerAudioMeter(id, stream);
                    }

                    if (event.track.kind === 'video') {
                        event.track.enabled = true;
                        if (this.callType !== 'audio') {
                            this.peers[id].isVideoOff = false;
                        }
                        const handleGroupVideoReady = () => {
                            if (this.peers[id] && this.callType !== 'audio') {
                                this.peers[id].isVideoOff = false;
                                this.bindRemoteVideo(id, true);
                            }
                        };
                        event.track.onunmute = handleGroupVideoReady;
                        event.track.onloadedmetadata = handleGroupVideoReady;
                    }

                    this.bindRemoteVideo(id, true);
                    this.$nextTick(() => {
                        this.bindRemoteVideo(id, true);
                    });
                    setTimeout(() => {
                        this.bindRemoteVideo(id, true);
                    }, 150);
                };

                pc.onicecandidate = (event) => {
                    if (event.candidate) {
                        this.sendDirectSignal('ice_candidate', { candidate: event.candidate }, id);
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
            const { audio: audioTransceiver, video: videoTransceiver } = ensureDeterministicTransceivers(pc, stream);

            const audioTracks = stream ? stream.getAudioTracks() : [];
            const videoTracks = (stream && this.callType !== 'audio' && !this.isVideoOff) ? stream.getVideoTracks() : [];

            const audioTrack = audioTracks.length > 0 ? audioTracks[0] : null;
            const videoTrack = videoTracks.length > 0 ? videoTracks[0] : null;

            // 1. Audio Track Assignment (Media line 0)
            if (audioTrack) {
                audioTrack.enabled = !this.isMuted;
            }
            if (audioTransceiver && audioTransceiver.sender) {
                try {
                    await audioTransceiver.sender.replaceTrack(audioTrack || null);
                } catch (e) {}
                try {
                    audioTransceiver.direction = 'sendrecv';
                } catch (e) {}
            }

            // 2. Video Track Assignment (Media line 1)
            if (videoTrack) {
                videoTrack.enabled = !this.isVideoOff && this.callType !== 'audio';
            }
            if (videoTransceiver && videoTransceiver.sender) {
                try {
                    await videoTransceiver.sender.replaceTrack(videoTrack || null);
                } catch (e) {}
                try {
                    videoTransceiver.direction = (videoTrack && !this.isVideoOff && this.callType !== 'audio') ? 'sendrecv' : (this.callType === 'audio' ? 'inactive' : 'recvonly');
                } catch (e) {}
            }
        },

        async initiateGroupPeerOffer(targetUserId) {
            const id = Number(targetUserId);
            const peer = this.getOrCreateGroupPeerConnection(id);
            if (!peer || !peer.pc) return;

            if (peer.makingOffer || peer.pc.signalingState === 'have-local-offer') {
                return;
            }

            try {
                peer.makingOffer = true;
                if (this.localStream) {
                    await this.attachTracksToPeerConnection(peer.pc, this.localStream);
                }

                const offer = await peer.pc.createOffer();
                if (peer.pc.signalingState !== 'stable') {
                    return;
                }
                await peer.pc.setLocalDescription(offer);
                this.sendDirectSignal('offer', { sdp: peer.pc.localDescription || offer }, id);
            } catch (e) {
                console.warn(`Error creating group offer for peer ${id}:`, e);
            } finally {
                if (peer) peer.makingOffer = false;
            }
        },

        bindRemoteVideo(userId, force = false) {
            const id = Number(userId);
            const peer = this.peers[id];
            if (peer && peer.stream) {
                if (this.callType === 'audio') {
                    peer.isVideoOff = true;
                } else if (peer.stream.getVideoTracks().length > 0) {
                    peer.isVideoOff = false;
                }

                const elements = document.querySelectorAll(`[data-remote-call-video="${id}"]`);
                elements.forEach((el) => {
                    if (force || el.srcObject !== peer.stream) {
                        try {
                            el.srcObject = peer.stream;
                        } catch (e) {}
                    }
                    el.muted = true; // Video tag muted so dedicated audio sink handles audio without echo
                    el.playsInline = true;
                    el.play().catch((e) => {
                        if (e.name === 'NotAllowedError') this.audioAutoplayBlocked = true;
                    });
                });
                const defaultEl = document.getElementById('remote-call-video-' + id);
                if (defaultEl) {
                    if (force || defaultEl.srcObject !== peer.stream) {
                        try {
                            defaultEl.srcObject = peer.stream;
                        } catch (e) {}
                    }
                    defaultEl.muted = true;
                    defaultEl.playsInline = true;
                    defaultEl.play().catch((e) => {
                        if (e.name === 'NotAllowedError') this.audioAutoplayBlocked = true;
                    });
                }
                ensureAudioSink('group-peer-audio-' + id, peer.stream);
            }
        },

        rebindAllRemoteVideos(force = false) {
            if (this.isGroup) {
                Object.keys(this.peers).forEach((userId) => {
                    this.bindRemoteVideo(userId, force);
                });
            } else if (this.remoteStream) {
                if (this.callType === 'audio') {
                    this.remoteVideoOff = true;
                } else if (this.remoteStream.getVideoTracks().length > 0) {
                    this.remoteVideoOff = false;
                }

                const remoteVideoEls = document.querySelectorAll('video[x-ref="remoteVideo"], video[data-remote-call-video="direct"]');
                remoteVideoEls.forEach((el) => {
                    if (force || el.srcObject !== this.remoteStream) {
                        try {
                            el.srcObject = this.remoteStream;
                        } catch (e) {}
                    }
                    el.muted = true; // Video element muted to prevent echo; audio sink plays remote audio
                    el.play().catch((e) => {
                        if (e.name === 'NotAllowedError') this.audioAutoplayBlocked = true;
                    });
                });
                if (this.$refs && this.$refs.remoteVideo) {
                    if (force || this.$refs.remoteVideo.srcObject !== this.remoteStream) {
                        try {
                            this.$refs.remoteVideo.srcObject = this.remoteStream;
                        } catch (e) {}
                    }
                    this.$refs.remoteVideo.muted = true;
                    this.$refs.remoteVideo.play().catch((e) => {
                        if (e.name === 'NotAllowedError') this.audioAutoplayBlocked = true;
                    });
                }
                ensureAudioSink('direct-call-remote-audio', this.remoteStream);
            }
        },

        rebindLocalVideo(force = false) {
            if (this.localStream && this.callType !== 'audio' && !this.isVideoOff) {
                const localVideoEls = document.querySelectorAll('video[x-ref="localVideo"], video[data-local-call-video="true"]');
                localVideoEls.forEach((el) => {
                    if (force || el.srcObject !== this.localStream) {
                        try {
                            el.srcObject = this.localStream;
                        } catch (e) {}
                    }
                    el.muted = true; // Local video always muted to prevent feedback
                    el.play().catch(() => {});
                });
                if (this.$refs && this.$refs.localVideo) {
                    if (force || this.$refs.localVideo.srcObject !== this.localStream) {
                        try {
                            this.$refs.localVideo.srcObject = this.localStream;
                        } catch (e) {}
                    }
                    this.$refs.localVideo.muted = true;
                    this.$refs.localVideo.play().catch(() => {});
                }
            }
        },

        setupRemotePeerAudioMeter(userId, stream) {
            const id = Number(userId);
            const peer = this.peers[id];
            if (!peer || !stream || stream.getAudioTracks().length === 0) return;

            try {
                if (!peer.audioCtx) {
                    peer.audioCtx = createSafeAudioContext();
                }
                if (!peer.audioCtx) return;

                const source = peer.audioCtx.createMediaStreamSource(stream);
                peer.analyser = peer.audioCtx.createAnalyser();
                peer.analyser.fftSize = 32;
                source.connect(peer.analyser);
                this.startAudioMeterLoop();
            } catch (e) {
                console.debug(`Remote peer audio meter setup notice for user ${id}:`, e);
            }
        },

        async handleIncomingSignal(signal) {
            if (!signal) return;
            const sigId = signal.id || signal.signalId || signal.signal_id || (signal.payload && (signal.payload.id || signal.payload.signal_id));
            if (sigId) {
                if (!this.processedSignalIds) this.processedSignalIds = new Set();
                if (this.processedSignalIds.has(sigId)) {
                    return;
                }
                this.processedSignalIds.add(sigId);
                if (this.processedSignalIds.size > 500) {
                    const first = this.processedSignalIds.values().next().value;
                    this.processedSignalIds.delete(first);
                }
            }

            const fromUserId = signal.senderUserId || signal.sender_user_id || signal.fromUserId || signal.from_user_id;
            const myUserId = this.currentUserId || (this.$wire && this.$wire.currentUserId);
            if (fromUserId && myUserId && (Number(fromUserId) === Number(myUserId))) {
                return;
            }

            const type = signal.signalType || signal.signal_type || signal.type;
            const payload = signal.payload || {};
            const callUuid = signal.callUuid || signal.call_uuid || (payload && (payload.call_uuid || payload.callUuid));

            // 1. Participant joined in group call
            if (type === 'participant_joined') {
                this.stopRingtone();
                this.callStatus = 'connected';
                const newUserId = Number(payload.user_id || payload.userId || fromUserId);
                const userName = payload.user_name || payload.userName || '';
                const userAvatar = payload.user_avatar || payload.userAvatar || null;

                if (newUserId && myUserId && newUserId !== Number(myUserId)) {
                    this.getOrCreateGroupPeerConnection(newUserId, userName, userAvatar);

                    if (Number(myUserId) < newUserId) {
                        setTimeout(() => {
                            this.initiateGroupPeerOffer(newUserId);
                        }, 350);
                    } else {
                        setTimeout(() => {
                            this.sendDirectSignal('peer_presence', { userId: myUserId }, newUserId);
                        }, 350);
                    }
                }
                return;
            }

            // 2. Participant left group call
            if (type === 'participant_left') {
                const leftUserId = Number(payload.user_id || payload.userId || fromUserId);
                if (leftUserId && this.peers[leftUserId]) {
                    try {
                        this.peers[leftUserId].pc.close();
                    } catch (e) {}
                    if (this.peers[leftUserId].audioCtx) {
                        try { this.peers[leftUserId].audioCtx.close(); } catch (e) {}
                    }
                    delete this.peers[leftUserId];
                }
                return;
            }

            // 3. Call Accepted (1-on-1)
            if (type === 'call_accepted') {
                this.stopRingtone();
                this.callStatus = 'connected';
                this.startTimer();
                if (!this.isGroup && this.peerConnection) {
                    if (this.peerConnection.signalingState === 'have-local-offer' && this.peerConnection.localDescription) {
                        this.sendDirectSignal('offer', { sdp: this.peerConnection.localDescription }, fromUserId);
                    } else if (this.peerConnection.signalingState === 'stable') {
                        try {
                            this.makingOffer = true;
                            if (this.localStream) {
                                await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                            }
                            const offer = await this.peerConnection.createOffer();
                            if (this.peerConnection.signalingState === 'stable') {
                                await this.peerConnection.setLocalDescription(offer);
                                this.sendDirectSignal('offer', { sdp: this.peerConnection.localDescription || offer }, fromUserId);
                            }
                        } catch (err) {
                            console.warn('Error transmitting offer upon call acceptance:', err);
                        } finally {
                            this.makingOffer = false;
                        }
                    }
                }
                return;
            }

            // 4. Call Rejected or Ended
            if (type === 'call_rejected' || type === 'call_declined') {
                const userName = payload.user_name || payload.userName || 'Participant';
                if (!this.isGroup) {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'info', message: `${userName} has declined the call.` } }));
                    this.cleanupWebRtc();
                    if (this.$wire && typeof this.$wire.resetCallState === 'function') {
                        this.$wire.resetCallState();
                    }
                } else {
                    window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'info', message: `${userName} has declined the group call.` } }));
                }
                return;
            }

            if (type === 'call_ended') {
                this.cleanupWebRtc();
                if (this.$wire && typeof this.$wire.resetCallState === 'function') {
                    this.$wire.resetCallState();
                }
                return;
            }

            // 5. Call Mode Switch Request (Voice <-> Video)
            if (type === 'switch_mode_request') {
                this.requestedSwitchType = (payload.requested_type || payload.requestedType || 'video').toLowerCase();
                this.switchRequesterName = payload.requester_name || payload.requesterName || 'Participant';
                this.showSwitchModeModal = true;
                if (this.$wire) {
                    this.$wire.showSwitchModeConfirmModal = true;
                    this.$wire.requestedSwitchType = this.requestedSwitchType;
                    this.$wire.switchRequesterName = this.switchRequesterName;
                }

                // 20-second auto dismiss timer for request
                if (this.switchTimeoutTimer) clearTimeout(this.switchTimeoutTimer);
                this.switchTimeoutTimer = setTimeout(() => {
                    if (this.showSwitchModeModal) {
                        this.showSwitchModeModal = false;
                        if (this.$wire) this.$wire.showSwitchModeConfirmModal = false;
                    }
                }, 20000);
                return;
            }

            // 6. Call Mode Switch Response (Accepted or Declined)
            if (type === 'switch_mode_response') {
                const accepted = !emptyOrFalse(payload.accepted);
                const targetType = (payload.requested_type || payload.requestedType || 'video').toLowerCase();
                const responderName = payload.responder_name || payload.responderName || 'Participant';

                if (accepted) {
                    await this.executeCallModeSwitch(targetType);
                    window.dispatchEvent(new CustomEvent('toast', {
                        detail: {
                            type: 'success',
                            message: `${responderName} accepted the request. Switched to ${targetType === 'audio' ? 'Voice' : 'Video'} call.`
                        }
                    }));
                } else {
                    window.dispatchEvent(new CustomEvent('toast', {
                        detail: {
                            type: 'warning',
                            message: `${responderName} declined the request to switch to ${targetType === 'audio' ? 'Voice' : 'Video'} call.`
                        }
                    }));
                }
                return;
            }

            // 7. Call Mode Switched (Peer Broadcast)
            if (type === 'call_mode_switched') {
                const newType = (payload.call_type || payload.callType || 'video').toLowerCase();
                await this.executeCallModeSwitch(newType);
                return;
            }

            // 8. Participant Invited to Group Call
            if (type === 'participant_invited') {
                const name = payload.invited_user_name || payload.invitedUserName || 'A user';
                window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'info', message: `${name} has been invited to this call.` } }));
                return;
            }

            // 9. Peer Presence in Group and 1-on-1
            if (type === 'peer_presence' && fromUserId) {
                if (this.isGroup) {
                    if (Number(myUserId) < Number(fromUserId)) {
                        this.initiateGroupPeerOffer(Number(fromUserId));
                    }
                } else if (this.peerConnection) {
                    if (this.peerConnection.signalingState === 'have-local-offer' && this.peerConnection.localDescription) {
                        this.sendDirectSignal('offer', { sdp: this.peerConnection.localDescription }, fromUserId);
                    } else if (this.peerConnection.signalingState === 'stable') {
                        try {
                            this.makingOffer = true;
                            if (this.localStream) {
                                await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                            }
                            const offer = await this.peerConnection.createOffer();
                            if (this.peerConnection.signalingState === 'stable') {
                                await this.peerConnection.setLocalDescription(offer);
                                this.sendDirectSignal('offer', { sdp: this.peerConnection.localDescription || offer }, fromUserId);
                            }
                        } catch (e) {
                            console.warn('Error negotiating offer on peer presence:', e);
                        } finally {
                            this.makingOffer = false;
                        }
                    }
                }
                return;
            }

            // 10. SDP Offer Handling
            if (type === 'offer') {
                const sdp = payload.sdp;
                if (!sdp) return;

                if (this.isGroup) {
                    const peer = this.getOrCreateGroupPeerConnection(fromUserId);
                    if (!peer || !peer.pc) return;

                    const isPolite = Number(myUserId) > Number(fromUserId);
                    const offerCollision = (peer.pc.signalingState !== 'stable' || peer.makingOffer);
                    peer.ignoreOffer = !isPolite && offerCollision;
                    if (peer.ignoreOffer) {
                        console.debug(`Group offer collision for peer ${fromUserId}: impolite peer ignoring offer`);
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
                            try { await peer.pc.addIceCandidate(new RTCIceCandidate(cand)); } catch (e) {}
                        }

                        if (peer.pc.signalingState === 'have-remote-offer') {
                            const answer = await peer.pc.createAnswer();
                            await peer.pc.setLocalDescription(answer);
                            this.sendDirectSignal('answer', { sdp: peer.pc.localDescription || answer }, fromUserId);
                        }
                    } catch (e) {
                        console.warn(`Error handling group offer from peer ${fromUserId}:`, e);
                    }
                    return;
                }

                // 1-on-1 Direct Call Offer Path
                this.pendingOffer = sdp;
                if (this.callStatus === 'incoming') {
                    return;
                }

                if (this.peerConnection) {
                    const isPolite = Number(myUserId) > Number(this.peerUserId || 0);
                    const offerCollision = (this.peerConnection.signalingState !== 'stable' || this.makingOffer);
                    this.ignoreOffer = !isPolite && offerCollision;
                    if (this.ignoreOffer) {
                        console.debug('1-on-1 offer collision: impolite peer ignoring offer');
                        return;
                    }

                    if (offerCollision && isPolite) {
                        try {
                            await this.peerConnection.setLocalDescription({ type: 'rollback' });
                        } catch (err) {}
                    }

                    try {
                        const desc = toSessionDescription(sdp, 'offer');
                        if (desc) {
                            await this.peerConnection.setRemoteDescription(desc);
                        }
                        if (this.localStream) {
                            await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                        }
                        this.drainPendingIceCandidates();

                        if (this.peerConnection.signalingState === 'have-remote-offer') {
                            const answer = await this.peerConnection.createAnswer();
                            await this.peerConnection.setLocalDescription(answer);
                            this.sendDirectSignal('answer', { sdp: this.peerConnection.localDescription || answer }, fromUserId);
                            this.pendingOffer = null;
                        }
                    } catch (err) {
                        console.warn('Error handling 1-on-1 renegotiation offer:', err);
                    }
                }
                return;
            }

            // 11. SDP Answer Handling
            if (type === 'answer') {
                const sdp = payload.sdp;
                if (!sdp) return;

                if (this.isGroup) {
                    if (fromUserId && this.peers[Number(fromUserId)]) {
                        const peer = this.peers[Number(fromUserId)];
                        if (peer && peer.pc) {
                            if (peer.pc.signalingState === 'have-local-offer') {
                                try {
                                    const desc = toSessionDescription(sdp, 'answer');
                                    if (desc) {
                                        await peer.pc.setRemoteDescription(desc);
                                    }
                                    while (peer.pendingCandidates && peer.pendingCandidates.length > 0) {
                                        const cand = peer.pendingCandidates.shift();
                                        try { await peer.pc.addIceCandidate(new RTCIceCandidate(cand)); } catch (e) {}
                                    }
                                } catch (e) {
                                    console.warn(`Error setting group answer for peer ${fromUserId}:`, e);
                                }
                            }
                        }
                    }
                    return;
                }

                // 1-on-1 Direct Mode
                if (this.peerConnection && sdp) {
                    if (this.peerConnection.signalingState === 'have-local-offer') {
                        try {
                            const desc = toSessionDescription(sdp, 'answer');
                            if (desc) {
                                await this.peerConnection.setRemoteDescription(desc);
                            }
                            this.stopRingtone();
                            this.callStatus = 'connected';
                            this.startTimer();
                            this.drainPendingIceCandidates();
                        } catch (err) {
                            console.warn('Error handling WebRTC answer:', err);
                        }
                    } else if (this.peerConnection.signalingState === 'stable') {
                        this.stopRingtone();
                        this.callStatus = 'connected';
                    }
                }
                return;
            }

            // 12. ICE Candidate Handling
            if (type === 'ice_candidate') {
                const candidate = payload.candidate;
                if (!candidate) return;

                if (this.isGroup) {
                    if (fromUserId && this.peers[Number(fromUserId)]) {
                        const peer = this.peers[Number(fromUserId)];
                        if (peer && peer.pc) {
                            if (peer.pc.remoteDescription && peer.pc.remoteDescription.type) {
                                await addSafeIceCandidate(peer.pc, candidate);
                            } else {
                                peer.pendingCandidates.push(candidate);
                            }
                        }
                    }
                    return;
                }

                // 1-on-1 Direct Mode
                if (this.peerConnection && this.peerConnection.remoteDescription && this.peerConnection.remoteDescription.type) {
                    await addSafeIceCandidate(this.peerConnection, candidate);
                } else {
                    if (!this.pendingIceCandidates) this.pendingIceCandidates = [];
                    this.pendingIceCandidates.push(candidate);
                }
                return;
            }

            // 13. Remote Audio Mute Toggle
            if (type === 'toggle_audio') {
                const muted = !!payload.isMuted;
                if (fromUserId && this.peers[Number(fromUserId)]) {
                    this.peers[Number(fromUserId)].isMuted = muted;
                    if (this.peers[Number(fromUserId)].stream) {
                        this.peers[Number(fromUserId)].stream.getAudioTracks().forEach((t) => { t.enabled = !muted; });
                    }
                } else {
                    this.remoteMuted = muted;
                    if (this.remoteStream) {
                        this.remoteStream.getAudioTracks().forEach((t) => { t.enabled = !muted; });
                    }
                }
                return;
            }

            // 14. Remote Video Off Toggle
            if (type === 'toggle_video') {
                const videoOff = !!payload.isVideoOff;
                if (fromUserId && this.peers[Number(fromUserId)]) {
                    this.peers[Number(fromUserId)].isVideoOff = videoOff;
                } else {
                    this.remoteVideoOff = videoOff;
                }
                return;
            }
        },

        setupLocalAudioMeter(stream) {
            try {
                if (!stream || stream.getAudioTracks().length === 0) return;
                if (!this.localAudioCtx) {
                    this.localAudioCtx = createSafeAudioContext();
                }
                if (!this.localAudioCtx) return;
                const source = this.localAudioCtx.createMediaStreamSource(stream);
                this.localAnalyser = this.localAudioCtx.createAnalyser();
                this.localAnalyser.fftSize = 32;
                source.connect(this.localAnalyser);
                this.startAudioMeterLoop();
            } catch (e) {
                console.debug('Local audio meter setup error:', e);
            }
        },

        setupRemoteAudioMeter(stream) {
            try {
                if (!stream || stream.getAudioTracks().length === 0) return;
                if (!this.remoteAudioCtx) {
                    this.remoteAudioCtx = createSafeAudioContext();
                }
                if (!this.remoteAudioCtx) return;
                const source = this.remoteAudioCtx.createMediaStreamSource(stream);
                this.remoteAnalyser = this.remoteAudioCtx.createAnalyser();
                this.remoteAnalyser.fftSize = 32;
                source.connect(this.remoteAnalyser);
                this.startAudioMeterLoop();
            } catch (e) {
                console.debug('Remote audio meter setup error:', e);
            }
        },

        startAudioMeterLoop() {
            if (this.audioMeterFrame) return;
            const dataArray = new Uint8Array(16);

            const updateMeter = () => {
                if (this.callStatus !== 'connected' && this.callStatus !== 'outgoing') {
                    this.localAudioLevel = 0;
                    this.isLocalSpeaking = false;
                    this.remoteAudioLevel = 0;
                    this.audioMeterFrame = null;
                    return;
                }

                // Local Audio Level
                if (this.localAnalyser && !this.isMuted) {
                    try {
                        this.localAnalyser.getByteFrequencyData(dataArray);
                        let sum = 0;
                        for (let i = 0; i < 16; i++) sum += dataArray[i];
                        this.localAudioLevel = Math.min(100, Math.round((sum / (16 * 128)) * 100));
                        this.isLocalSpeaking = this.localAudioLevel > 14;
                    } catch (e) {
                        this.localAudioLevel = 0;
                        this.isLocalSpeaking = false;
                    }
                } else {
                    this.localAudioLevel = 0;
                    this.isLocalSpeaking = false;
                }

                // Remote 1-on-1 Audio Level
                if (this.remoteAnalyser && !this.remoteMuted) {
                    try {
                        this.remoteAnalyser.getByteFrequencyData(dataArray);
                        let sum = 0;
                        for (let i = 0; i < 16; i++) sum += dataArray[i];
                        this.remoteAudioLevel = Math.min(100, Math.round((sum / (16 * 128)) * 100));
                    } catch (e) {
                        this.remoteAudioLevel = 0;
                    }
                } else {
                    this.remoteAudioLevel = 0;
                }

                // Group Peers Audio Level
                Object.values(this.peers).forEach((peer) => {
                    if (peer.analyser && !peer.isMuted) {
                        try {
                            peer.analyser.getByteFrequencyData(dataArray);
                            let pSum = 0;
                            for (let i = 0; i < 16; i++) pSum += dataArray[i];
                            peer.audioLevel = Math.min(100, Math.round((pSum / (16 * 128)) * 100));
                            peer.isSpeaking = peer.audioLevel > 14;
                        } catch (e) {
                            peer.audioLevel = 0;
                            peer.isSpeaking = false;
                        }
                    } else {
                        peer.audioLevel = 0;
                        peer.isSpeaking = false;
                    }
                });

                this.audioMeterFrame = requestAnimationFrame(updateMeter);
            };

            updateMeter();
        },

        async initWebRtc(iceServers, isInitiator, callType, startMuted = false, startVideoOff = false, isGroup = false, peerUserId = null) {
            const rawType = (callType || this.callType || 'video').toLowerCase();
            const isAudioOnly = (rawType === 'audio' || rawType === 'voice' || rawType === 'phone');
            this.callType = isAudioOnly ? 'audio' : 'video';
            this.isVideoOff = isAudioOnly || !!startVideoOff;
            this.isMuted = !!startMuted;
            this.isGroup = !!isGroup;
            if (!this.pendingIceCandidates) {
                this.pendingIceCandidates = [];
            }

            if (iceServers && iceServers.length > 0) {
                this.iceServers = iceServers;
            }

            try {
                const mediaResult = await acquireMediaStreamWithFallback({
                    audio: true,
                    video: !isAudioOnly && !this.isVideoOff,
                    audioDeviceId: this.selectedMicId || null,
                    videoDeviceId: this.selectedCameraId || null,
                });

                this.localStream = mediaResult.stream;

                if (mediaResult.fallbackUsed) {
                    this.isVideoOff = true;
                    const isBusy = mediaResult.fallbackReason === 'camera_busy';
                    this.hardwareNotice = {
                        show: true,
                        type: 'warning',
                        message: isBusy
                            ? 'Camera is in use by another app (e.g. Zoom/Teams). Connected with microphone only.'
                            : 'Camera is currently unavailable. Connected with microphone only.',
                        canRetryCamera: true,
                        isRetrying: false,
                    };
                } else if (mediaResult.error) {
                    this.hardwareNotice = {
                        show: true,
                        type: 'danger',
                        message: mediaResult.error.message,
                        canRetryCamera: mediaResult.error.type === 'busy',
                        isRetrying: false,
                    };
                } else {
                    this.hardwareNotice = { show: false, type: 'info', message: '', canRetryCamera: false, isRetrying: false };
                }

                this.stopRingtone();

                if (this.localStream) {
                    if (this.isMuted) {
                        this.localStream.getAudioTracks().forEach((t) => { t.enabled = false; });
                    }
                    if (this.isVideoOff) {
                        this.localStream.getVideoTracks().forEach((t) => { t.enabled = false; });
                    }
                    this.rebindLocalVideo(true);
                    this.setupLocalAudioMeter(this.localStream);
                }

                await this.refreshHardwareDevices();

                if (isGroup) {
                    if (this.localStream) {
                        await Promise.all(Object.values(this.peers).map((peer) => {
                            return peer.pc ? this.attachTracksToPeerConnection(peer.pc, this.localStream) : Promise.resolve();
                        }));
                    }
                    if (isInitiator && peerUserId) {
                        this.initiateGroupPeerOffer(peerUserId);
                    }
                    return;
                }

                // 1-on-1 Direct Mode: Initialize fixed transceivers
                if (!this.peerConnection || this.peerConnection.signalingState === 'closed') {
                    this.peerConnection = new RTCPeerConnection({
                        iceServers: (this.iceServers && this.iceServers.length > 0) ? this.iceServers : [{ urls: 'stun:stun.l.google.com:19302' }]
                    });
                }

                ensureDeterministicTransceivers(this.peerConnection, this.localStream);

                if (this.localStream) {
                    await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                }

                this.peerConnection.ontrack = (event) => {
                    if (!this.remoteStream) {
                        this.remoteStream = new MediaStream();
                    }
                    if (event.streams && event.streams[0]) {
                        event.streams[0].getTracks().forEach((track) => {
                            if (!this.remoteStream.getTracks().some((t) => t.id === track.id)) {
                                this.remoteStream.addTrack(track);
                            }
                        });
                    }
                    if (event.track && !this.remoteStream.getTracks().some((t) => t.id === event.track.id)) {
                        this.remoteStream.addTrack(event.track);
                    }

                    if (event.track.kind === 'audio') {
                        event.track.enabled = !this.remoteMuted;
                        ensureAudioSink('direct-call-remote-audio', this.remoteStream);
                        this.setupRemoteAudioMeter(this.remoteStream);
                    }

                    if (event.track.kind === 'video') {
                        event.track.enabled = true;
                        if (this.callType !== 'audio') {
                            this.remoteVideoOff = false;
                        }
                        const handleDirectVideoReady = () => {
                            if (this.callType !== 'audio') {
                                this.remoteVideoOff = false;
                                this.rebindAllRemoteVideos(true);
                            }
                        };
                        event.track.onunmute = handleDirectVideoReady;
                        event.track.onloadedmetadata = handleDirectVideoReady;
                    }

                    this.rebindAllRemoteVideos(true);
                    this.$nextTick(() => {
                        this.rebindAllRemoteVideos(true);
                    });
                    setTimeout(() => {
                        this.rebindAllRemoteVideos(true);
                    }, 150);
                };

                this.peerConnection.onicecandidate = (event) => {
                    if (event.candidate) {
                        this.sendDirectSignal('ice_candidate', { candidate: event.candidate });
                    }
                };

                this.peerConnection.oniceconnectionstatechange = () => {
                    if (this.peerConnection) {
                        const state = this.peerConnection.iceConnectionState;
                        if (state === 'connected' || state === 'completed') {
                            this.stopRingtone();
                            this.callStatus = 'connected';
                            this.connectionQuality = 'good';
                        } else if (state === 'disconnected') {
                            this.connectionQuality = 'poor';
                        } else if (state === 'failed') {
                            this.connectionQuality = 'reconnecting';
                            try { this.peerConnection.restartIce(); } catch (e) {}
                        }
                    }
                };

                if (isInitiator) {
                    try {
                        this.makingOffer = true;
                        const offer = await this.peerConnection.createOffer();
                        if (this.peerConnection.signalingState === 'stable') {
                            await this.peerConnection.setLocalDescription(offer);
                            this.sendDirectSignal('offer', { sdp: this.peerConnection.localDescription || offer });
                        }
                    } catch (err) {
                        console.warn('Error creating initial 1-on-1 offer:', err);
                    } finally {
                        this.makingOffer = false;
                    }
                }
            } catch (err) {
                console.error('WebRTC Media Device Access Error:', err);
            }
        },

        async toggleMute() {
            this.isMuted = !this.isMuted;
            if (this.localStream) {
                this.localStream.getAudioTracks().forEach((track) => {
                    track.enabled = !this.isMuted;
                });
            }
            if (this.peerConnection) {
                await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
            }
            await Promise.all(Object.values(this.peers).map((peer) => {
                return peer.pc ? this.attachTracksToPeerConnection(peer.pc, this.localStream) : Promise.resolve();
            }));
            if (this.isMuted) {
                this.localAudioLevel = 0;
                this.isLocalSpeaking = false;
            }
            this.sendDirectSignal('toggle_audio', { isMuted: this.isMuted });
        },

        async toggleVideo() {
            if (this.callType === 'audio') return;

            this.isVideoOff = !this.isVideoOff;
            if (this.isVideoOff) {
                if (this.localStream) {
                    this.localStream.getVideoTracks().forEach((track) => {
                        track.enabled = false;
                    });
                }
                if (this.peerConnection) {
                    await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                }
                await Promise.all(Object.values(this.peers).map((peer) => {
                    return peer.pc ? this.attachTracksToPeerConnection(peer.pc, this.localStream) : Promise.resolve();
                }));
                if (this.$refs && this.$refs.localVideo) {
                    try { this.$refs.localVideo.pause(); } catch (e) {}
                }
            } else {
                let liveTrack = this.localStream ? this.localStream.getVideoTracks().find((t) => t.readyState === 'live') : null;
                if (!liveTrack) {
                    try {
                        const vidStream = await navigator.mediaDevices.getUserMedia({
                            video: this.selectedCameraId ? { deviceId: { exact: this.selectedCameraId }, width: { ideal: 1280 }, height: { ideal: 720 } } : { width: { ideal: 1280 }, height: { ideal: 720 } }
                        });
                        liveTrack = vidStream.getVideoTracks()[0];
                        if (this.localStream && liveTrack) {
                            this.localStream.addTrack(liveTrack);
                        }
                    } catch (e) {
                        console.warn('Could not re-acquire video track in call:', e);
                        this.isVideoOff = true;
                        const isBusy = (e.name === 'NotReadableError' || e.name === 'TrackStartError');
                        this.hardwareNotice = {
                            show: true,
                            type: 'warning',
                            message: isBusy ? 'Camera is currently in use by another app.' : 'Could not access camera.',
                            canRetryCamera: true,
                            isRetrying: false,
                        };
                        return;
                    }
                }
                if (liveTrack) {
                    liveTrack.enabled = true;
                }
                this.hardwareNotice.show = false;
                this.rebindLocalVideo(true);

                if (this.peerConnection) {
                    await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                }
                await Promise.all(Object.values(this.peers).map((peer) => {
                    return peer.pc ? this.attachTracksToPeerConnection(peer.pc, this.localStream) : Promise.resolve();
                }));
            }

            this.sendDirectSignal('toggle_video', { isVideoOff: this.isVideoOff });
        },

        async retryAcquireCamera() {
            if (this.hardwareNotice.isRetrying) return;
            this.hardwareNotice.isRetrying = true;

            try {
                const videoConstraints = this.selectedCameraId
                    ? { deviceId: { exact: this.selectedCameraId }, width: { ideal: 1280 }, height: { ideal: 720 } }
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

                    this.isVideoOff = false;
                    this.hardwareNotice.show = false;

                    this.rebindLocalVideo(true);

                    if (this.peerConnection) {
                        await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                    }

                    await Promise.all(Object.values(this.peers).map((peer) => {
                        return peer.pc ? this.attachTracksToPeerConnection(peer.pc, this.localStream) : Promise.resolve();
                    }));

                    this.sendDirectSignal('toggle_video', { isVideoOff: false });

                    if (window.Alpine?.store('toasts')) {
                        window.Alpine.store('toasts').add('success', 'Camera reconnected successfully.');
                    }
                }
            } catch (err) {
                console.warn('Retry camera failed:', err);
                const isBusy = (err.name === 'NotReadableError' || err.name === 'TrackStartError');
                this.hardwareNotice.message = isBusy
                    ? 'Camera is still in use by another app. Close other applications and retry.'
                    : 'Camera access notice: ' + (err.message || err.name);
            } finally {
                this.hardwareNotice.isRetrying = false;
            }
        },

        // Request Call Mode Switch with Consent
        requestSwitchCallMode(newType) {
            const normalized = (newType || 'video').toLowerCase();
            const targetType = (normalized === 'audio' || normalized === 'voice') ? 'audio' : 'video';
            if (this.$wire && typeof this.$wire.requestSwitchCallMode === 'function') {
                this.$wire.requestSwitchCallMode(targetType);
            } else {
                this.sendDirectSignal('switch_mode_request', {
                    requested_type: targetType,
                    requester_name: this.peerName || 'Participant',
                    call_uuid: this.activeCallUuid
                });
                window.dispatchEvent(new CustomEvent('toast', {
                    detail: { type: 'info', message: `Request sent to participants to switch to ${targetType === 'audio' ? 'Voice' : 'Video'} call.` }
                }));
            }
        },

        // Accept Mode Switch and trigger SDP renegotiation
        async acceptSwitchMode() {
            const newType = this.requestedSwitchType || 'video';
            this.showSwitchModeModal = false;
            if (this.$wire) this.$wire.showSwitchModeConfirmModal = false;

            if (this.$wire && typeof this.$wire.acceptSwitchMode === 'function') {
                this.$wire.acceptSwitchMode();
            } else {
                this.sendDirectSignal('switch_mode_response', {
                    accepted: true,
                    requested_type: newType,
                    responder_name: this.peerName || 'Participant',
                    call_uuid: this.activeCallUuid
                });
                await this.executeCallModeSwitch(newType);
            }
        },

        // Decline Mode Switch
        declineSwitchMode() {
            this.showSwitchModeModal = false;
            if (this.$wire) this.$wire.showSwitchModeConfirmModal = false;

            if (this.$wire && typeof this.$wire.declineSwitchMode === 'function') {
                this.$wire.declineSwitchMode();
            } else {
                this.sendDirectSignal('switch_mode_response', {
                    accepted: false,
                    requested_type: this.requestedSwitchType,
                    responder_name: this.peerName || 'Participant',
                    call_uuid: this.activeCallUuid
                });
            }
            window.dispatchEvent(new CustomEvent('toast', { detail: { type: 'info', message: 'You declined the switch request.' } }));
        },

        // Core Dynamic SDP Renegotiation and Track Upgrade/Downgrade Engine
        async executeCallModeSwitch(newType) {
            const rawType = (newType || 'video').toLowerCase();
            const isAudio = (rawType === 'audio' || rawType === 'voice');
            this.callType = isAudio ? 'audio' : 'video';
            this.isVideoOff = isAudio;

            if (isAudio) {
                // Video -> Voice Downgrade
                if (this.localStream) {
                    this.localStream.getVideoTracks().forEach((track) => {
                        track.enabled = false;
                    });
                }
                if (this.peerConnection) {
                    await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                }
                await Promise.all(Object.values(this.peers).map((peer) => {
                    return peer.pc ? this.attachTracksToPeerConnection(peer.pc, this.localStream) : Promise.resolve();
                }));
                if (this.$refs && this.$refs.localVideo) {
                    try { this.$refs.localVideo.pause(); } catch (e) {}
                }
            } else {
                // Voice -> Video Upgrade & SDP Renegotiation
                let liveTrack = this.localStream ? this.localStream.getVideoTracks().find((t) => t.readyState === 'live') : null;
                if (!liveTrack) {
                    try {
                        const vidStream = await navigator.mediaDevices.getUserMedia({
                            video: { width: { ideal: 1280 }, height: { ideal: 720 } }
                        });
                        liveTrack = vidStream.getVideoTracks()[0];
                        if (this.localStream && liveTrack) {
                            this.localStream.addTrack(liveTrack);
                        }
                    } catch (e) {
                        console.warn('Could not acquire video track during switch:', e);
                        this.isVideoOff = true;
                        return;
                    }
                }

                if (liveTrack) {
                    liveTrack.enabled = true;
                }

                this.rebindLocalVideo();

                // 1-on-1 SDP Renegotiation
                if (this.peerConnection) {
                    await this.attachTracksToPeerConnection(this.peerConnection, this.localStream);
                    if (this.peerConnection.signalingState === 'stable' && !this.makingOffer) {
                        try {
                            this.makingOffer = true;
                            const offer = await this.peerConnection.createOffer();
                            if (this.peerConnection.signalingState === 'stable') {
                                await this.peerConnection.setLocalDescription(offer);
                                this.sendDirectSignal('offer', { sdp: this.peerConnection.localDescription || offer, isRenegotiation: true, call_type: 'video' });
                            }
                        } catch (e) {
                            console.warn('Renegotiation offer generation error:', e);
                        } finally {
                            this.makingOffer = false;
                        }
                    }
                }

                // Group SDP Renegotiation
                await Promise.all(Object.values(this.peers).map(async (peer) => {
                    if (peer.pc) {
                        await this.attachTracksToPeerConnection(peer.pc, this.localStream);
                        if (peer.pc.signalingState === 'stable' && !peer.makingOffer) {
                            try {
                                peer.makingOffer = true;
                                const offer = await peer.pc.createOffer();
                                if (peer.pc.signalingState === 'stable') {
                                    await peer.pc.setLocalDescription(offer);
                                    this.sendDirectSignal('offer', { sdp: peer.pc.localDescription || offer, isRenegotiation: true, call_type: 'video' }, peer.userId);
                                }
                            } catch (e) {
                                console.warn(`Renegotiation offer generation error for peer ${peer.userId}:`, e);
                            } finally {
                                peer.makingOffer = false;
                            }
                        }
                    }
                }));
            }

            this.$nextTick(() => {
                this.rebindAllRemoteVideos();
                this.rebindLocalVideo();
            });
            setTimeout(() => {
                this.rebindAllRemoteVideos();
                this.rebindLocalVideo();
            }, 200);
        },

        async toggleScreenShare() {
            if (this.isScreenSharing) {
                if (this.screenStream) {
                    stopMediaTracks(this.screenStream);
                    this.screenStream = null;
                }
                this.isScreenSharing = false;

                if (!this.isVideoOff && this.localStream) {
                    const vidTrack = this.localStream.getVideoTracks()[0];
                    if (vidTrack) {
                        if (this.peerConnection) {
                            const s = this.peerConnection.getSenders().find((s) => (s.track && s.track.kind === 'video') || s.track === null);
                            if (s) await s.replaceTrack(vidTrack);
                        }
                        Object.values(this.peers).forEach(async (peer) => {
                            if (peer.pc) {
                                const s = peer.pc.getSenders().find((s) => (s.track && s.track.kind === 'video') || s.track === null);
                                if (s) await s.replaceTrack(vidTrack);
                            }
                        });
                        if (this.$refs?.localVideo) {
                            this.$refs.localVideo.srcObject = this.localStream;
                        }
                    }
                }
                return;
            }

            try {
                if (!navigator.mediaDevices || typeof navigator.mediaDevices.getDisplayMedia !== 'function') {
                    alert('Screen sharing is not supported by your browser.');
                    return;
                }

                this.screenStream = await navigator.mediaDevices.getDisplayMedia({
                    video: { cursor: 'always' },
                    audio: false
                });

                const screenTrack = this.screenStream.getVideoTracks()[0];
                if (!screenTrack) return;

                this.isScreenSharing = true;

                if (this.$refs?.localVideo) {
                    this.$refs.localVideo.srcObject = this.screenStream;
                    this.$refs.localVideo.play().catch(() => {});
                }

                if (this.peerConnection) {
                    const s = this.peerConnection.getSenders().find((s) => (s.track && s.track.kind === 'video') || s.track === null);
                    if (s) await s.replaceTrack(screenTrack);
                }
                Object.values(this.peers).forEach(async (peer) => {
                    if (peer.pc) {
                        const s = peer.pc.getSenders().find((s) => (s.track && s.track.kind === 'video') || s.track === null);
                        if (s) await s.replaceTrack(screenTrack);
                    }
                });

                screenTrack.onended = () => {
                    if (this.isScreenSharing) {
                        this.toggleScreenShare();
                    }
                };
            } catch (err) {
                console.warn('Screen sharing denied or notice:', err);
                this.isScreenSharing = false;
            }
        },

        startTimer() {
            this.durationSeconds = 0;
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
            this.timerInterval = setInterval(() => {
                this.durationSeconds++;
            }, 1000);
        },

        formatDuration(sec) {
            const m = Math.floor((sec || 0) / 60);
            const s = (sec || 0) % 60;
            return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
        },

        playRingtone(isIncoming = true) {
            this.stopRingtone();
            if (this.callStatus === 'connected' || this.callStatus === 'ended' || this.callStatus === 'idle') {
                return;
            }
            this.isRinging = true;

            try {
                this.ringtoneContext = createSafeAudioContext();
                if (!this.ringtoneContext) return;

                this.activeRingOscillators = [];
                this.activeRingGains = [];

                const playToneBurst = () => {
                    if (!this.isRinging || this.callStatus === 'connected' || this.callStatus === 'ended' || this.callStatus === 'idle') {
                        this.stopRingtone();
                        return;
                    }
                    if (!this.ringtoneContext || this.ringtoneContext.state === 'closed') return;
                    try {
                        const now = this.ringtoneContext.currentTime;
                        const osc1 = this.ringtoneContext.createOscillator();
                        const osc2 = this.ringtoneContext.createOscillator();
                        const gain = this.ringtoneContext.createGain();

                        osc1.type = 'sine';
                        osc2.type = 'sine';
                        osc1.frequency.setValueAtTime(440, now);
                        osc2.frequency.setValueAtTime(isIncoming ? 480 : 440, now);

                        gain.gain.setValueAtTime(0.06, now);
                        gain.gain.exponentialRampToValueAtTime(0.0001, now + (isIncoming ? 1.5 : 1.0));

                        osc1.connect(gain);
                        osc2.connect(gain);
                        gain.connect(this.ringtoneContext.destination);

                        this.activeRingOscillators.push(osc1, osc2);
                        this.activeRingGains.push(gain);

                        osc1.start(now);
                        osc2.start(now);
                        osc1.stop(now + (isIncoming ? 1.5 : 1.0));
                        osc2.stop(now + (isIncoming ? 1.5 : 1.0));

                        osc1.onended = () => {
                            this.activeRingOscillators = (this.activeRingOscillators || []).filter((o) => o !== osc1);
                        };
                        osc2.onended = () => {
                            this.activeRingOscillators = (this.activeRingOscillators || []).filter((o) => o !== osc2);
                        };
                    } catch (toneErr) {}
                };

                playToneBurst();
                this.ringtoneInterval = setInterval(playToneBurst, isIncoming ? 3000 : 3500);
            } catch (e) {
                console.debug('Ringtone playback notice:', e);
            }
        },

        stopRingtone() {
            this.isRinging = false;
            if (this.ringtoneInterval) {
                clearInterval(this.ringtoneInterval);
                this.ringtoneInterval = null;
            }
            if (this.activeRingGains && this.activeRingGains.length > 0) {
                this.activeRingGains.forEach((g) => {
                    try {
                        g.gain.cancelScheduledValues(0);
                        g.gain.setValueAtTime(0, 0);
                        g.disconnect();
                    } catch (e) {}
                });
                this.activeRingGains = [];
            }
            if (this.activeRingOscillators && this.activeRingOscillators.length > 0) {
                this.activeRingOscillators.forEach((o) => {
                    try {
                        o.stop(0);
                        o.disconnect();
                    } catch (e) {}
                });
                this.activeRingOscillators = [];
            }
            if (this.ringtoneContext) {
                try {
                    this.ringtoneContext.close();
                } catch (e) {}
                this.ringtoneContext = null;
            }
        },

        cleanupWebRtc() {
            this.stopRingtone();
            if (this.timerInterval) {
                clearInterval(this.timerInterval);
                this.timerInterval = null;
            }
            if (this.switchTimeoutTimer) {
                clearTimeout(this.switchTimeoutTimer);
                this.switchTimeoutTimer = null;
            }
            this.durationSeconds = 0;
            this.pendingIceCandidates = [];
            this.pendingOffer = null;
            this.audioAutoplayBlocked = false;
            this.showDeviceSettingsModal = false;
            this.showSwitchModeModal = false;

            if (this.audioMeterFrame) {
                cancelAnimationFrame(this.audioMeterFrame);
                this.audioMeterFrame = null;
            }
            if (this.localAudioCtx) {
                try { this.localAudioCtx.close(); } catch (e) {}
                this.localAudioCtx = null;
            }
            if (this.remoteAudioCtx) {
                try { this.remoteAudioCtx.close(); } catch (e) {}
                this.remoteAudioCtx = null;
            }
            this.localAudioLevel = 0;
            this.isLocalSpeaking = false;
            this.remoteAudioLevel = 0;

            if (this.localStream) {
                stopMediaTracks(this.localStream);
                this.localStream = null;
            }
            if (this.screenStream) {
                stopMediaTracks(this.screenStream);
                this.screenStream = null;
            }
            if (this.remoteStream) {
                stopMediaTracks(this.remoteStream);
                this.remoteStream = null;
            }
            if (this.peerConnection) {
                try {
                    this.peerConnection.getSenders().forEach((sender) => {
                        if (sender.track) {
                            try {
                                sender.track.enabled = false;
                                sender.track.stop();
                            } catch (e) {}
                        }
                    });
                    this.peerConnection.close();
                } catch (e) {}
                this.peerConnection = null;
            }

            removeAudioSink('direct-call-remote-audio');

            // Cleanup group peers
            Object.values(this.peers).forEach((peer) => {
                if (peer.userId) {
                    removeAudioSink('group-peer-audio-' + peer.userId);
                }
                if (peer.pc) {
                    try {
                        peer.pc.close();
                    } catch (e) {}
                }
                if (peer.audioCtx) {
                    try {
                        peer.audioCtx.close();
                    } catch (e) {}
                }
            });
            this.peers = {};

            if (this.$refs) {
                try {
                    if (this.$refs.localVideo) {
                        this.$refs.localVideo.srcObject = null;
                        try { this.$refs.localVideo.pause(); } catch (e) {}
                    }
                    if (this.$refs.remoteVideo) {
                        this.$refs.remoteVideo.srcObject = null;
                        try { this.$refs.remoteVideo.pause(); } catch (e) {}
                    }
                    if (this.$refs.remoteAudio) {
                        this.$refs.remoteAudio.srcObject = null;
                        try { this.$refs.remoteAudio.pause(); } catch (e) {}
                    }
                } catch (e) {}
            }
            this.callStatus = 'idle';
            this.isScreenSharing = false;
            this.isGroup = false;
            this.isMinimized = false;
            this.pinnedUserId = null;
        }
    };
}

// -------------------------------------------------------------
// 3. Live Chat Alpine Component
// -------------------------------------------------------------
export function chatAlpine(config = {}) {
    return {
        currentUserId: Number(config.currentUserId || 0),
        currentUserName: config.currentUserName || 'You',
        currentUserAvatar: config.currentUserAvatar || null,
        activeConversationId: config.activeConversationId ? Number(config.activeConversationId) : null,
        activeConversationEcho: null,
        mobileSidebarOpen: true,
        showEmojiPicker: false,
        highlightedMessageId: null,
        copiedInvite: false,
        isFullscreen: false,
        soundMuted: false,
        typingUsers: [], // Array of { id, name, avatar, expiresAt }
        typingThrottleTimer: null,
        typingResetTimer: null,
        previewModal: {
            open: false,
            url: '',
            name: '',
            type: 'image',
            size: '',
            isPdf: false,
        },
        _listeners: [],
        _timers: [],

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

        get typingCount() {
            return this.typingUsers.length;
        },

        get typingText() {
            if (this.typingUsers.length === 0) return '';
            if (this.typingUsers.length === 1) {
                return `${this.typingUsers[0].name} is typing...`;
            }
            if (this.typingUsers.length === 2) {
                return `${this.typingUsers[0].name} and ${this.typingUsers[1].name} are typing...`;
            }
            return `${this.typingUsers[0].name} and ${this.typingUsers.length - 1} others are typing...`;
        },

        init() {
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => {
                    this.destroy();
                });
            }

            this.scrollToBottom();

            if (this.activeConversationId) {
                this.subscribeConversationEcho(this.activeConversationId);
            }

            // Auto-clean expired typing indicators every 1.0s
            const cleanInterval = setInterval(() => {
                const now = Date.now();
                this.typingUsers = this.typingUsers.filter((u) => u.expiresAt > now);
            }, 1000);
            this._timers.push(cleanInterval);

            this.bindListener(window, 'fullscreenchange', () => {
                this.isFullscreen = !!document.fullscreenElement;
            });

            this.bindListener(window, 'chat-scrolled-to-bottom', () => {
                this.scrollToBottom();
            });

            this.bindListener(window, 'chat-typing-received', (e) => {
                const detail = extractEventData(e.detail);
                this.handleTypingSignal(detail);
            });

            this.bindListener(window, 'focus-composer', () => {
                if (typeof this.$nextTick === 'function') {
                    this.$nextTick(() => {
                        try {
                            this.$refs?.composerInput?.focus?.();
                        } catch (e) {}
                    });
                }
            });

            this.bindListener(document, 'livewire:navigating', () => {
                this.destroy();
            });
        },

        destroy() {
            this.clearOwnTyping();
            this.clearAllTimers();
            this.clearAllListeners();
            if (this.activeConversationEcho) {
                try {
                    this.activeConversationEcho.stopListeningForWhisper('typing');
                } catch (e) {}
                this.activeConversationEcho = null;
            }
        },

        subscribeConversationEcho(convId) {
            const id = Number(convId);
            if (!id || !window.Echo || typeof window.Echo.private !== 'function') return;

            if (this.activeConversationEcho) {
                try {
                    this.activeConversationEcho.stopListeningForWhisper('typing');
                } catch (e) {}
            }

            try {
                this.activeConversationEcho = window.Echo.private(`conversation.${id}`);

                // 1. Direct Real-Time Whisper (sub-5ms instant delivery)
                this.activeConversationEcho.listenForWhisper('typing', (data) => {
                    this.handleTypingSignal(data);
                });

                // 2. Incoming messages on conversation channel
                this.activeConversationEcho
                    .listen('.ChatMessageSent', (e) => this.handleIncomingMessageEvent(e))
                    .listen('ChatMessageSent', (e) => this.handleIncomingMessageEvent(e));
            } catch (e) {
                console.debug('Conversation Echo setup note:', e);
            }
        },

        handleIncomingMessageEvent(eventData) {
            const payload = extractEventData(eventData);
            const senderId = Number(payload.user_id || payload.userId || (payload.message && payload.message.user_id));
            const myUserId = this.currentUserId || (this.$wire && this.$wire.currentUserId);

            // Immediately clear sender's typing status
            if (senderId) {
                this.typingUsers = this.typingUsers.filter((u) => u.id !== senderId);
            }

            // Play appropriate sound chime
            if (senderId && myUserId && senderId !== Number(myUserId)) {
                this.playMessageReceivedChime();
            }

            this.scrollToBottom();
        },

        onComposerInput() {
            const uid = this.currentUserId || (this.$wire && this.$wire.currentUserId);
            const uname = this.currentUserName || (this.$wire && this.$wire.currentUserName) || 'Someone';
            const uavatar = this.currentUserAvatar || null;

            // 1. Instant WebSocket Whisper (sub-5ms!)
            if (this.activeConversationEcho && typeof this.activeConversationEcho.whisper === 'function') {
                this.activeConversationEcho.whisper('typing', {
                    user_id: uid,
                    user_name: uname,
                    user_avatar: uavatar,
                    is_typing: true
                });
            }

            // 2. Throttle HTTP server broadcast backup (every 2.0s)
            if (!this.typingThrottleTimer) {
                if (this.$wire && typeof this.$wire.sendTypingIndicator === 'function') {
                    this.$wire.sendTypingIndicator(true);
                }
                this.typingThrottleTimer = setTimeout(() => {
                    this.typingThrottleTimer = null;
                }, 2000);
            }

            // 3. Inactivity timer: 2.0s of no keystrokes resets typing
            if (this.typingResetTimer) {
                clearTimeout(this.typingResetTimer);
            }
            this.typingResetTimer = setTimeout(() => {
                this.clearOwnTyping();
            }, 2000);
        },

        clearOwnTyping() {
            if (this.typingResetTimer) {
                clearTimeout(this.typingResetTimer);
                this.typingResetTimer = null;
            }
            this.typingThrottleTimer = null;

            const uid = this.currentUserId || (this.$wire && this.$wire.currentUserId);

            // Instant Whisper Clear
            if (this.activeConversationEcho && typeof this.activeConversationEcho.whisper === 'function') {
                this.activeConversationEcho.whisper('typing', {
                    user_id: uid,
                    is_typing: false
                });
            }

            // Fallback HTTP Clear
            if (this.$wire && typeof this.$wire.sendTypingIndicator === 'function') {
                this.$wire.sendTypingIndicator(false);
            }
        },

        handleTypingSignal(payload) {
            if (!payload) return;
            const myUserId = this.currentUserId || (this.$wire && this.$wire.currentUserId);
            const userId = Number(payload.user_id || payload.userId);
            const isTyping = payload.is_typing !== undefined ? !!payload.is_typing : (payload.isTyping !== undefined ? !!payload.isTyping : true);
            const userName = payload.user_name || payload.userName || 'Someone';
            const userAvatar = payload.user_avatar || payload.userAvatar || null;

            if (!userId || (myUserId && userId === Number(myUserId))) {
                return; // Ignore own typing event
            }

            if (!isTyping) {
                this.typingUsers = this.typingUsers.filter((u) => u.id !== userId);
            } else {
                const existingIndex = this.typingUsers.findIndex((u) => u.id === userId);
                const expiry = Date.now() + 2500;
                if (existingIndex >= 0) {
                    this.typingUsers[existingIndex].expiresAt = expiry;
                } else {
                    this.typingUsers.push({
                        id: userId,
                        name: userName,
                        avatar: userAvatar,
                        expiresAt: expiry
                    });
                }
            }
        },

        toggleFullscreen() {
            const root = document.getElementById('chat-root-container');
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

        playMessageSentChime() {
            if (this.soundMuted) return;
            try {
                const ctx = createSafeAudioContext();
                if (!ctx) return;
                const osc = ctx.createOscillator();
                const gain = ctx.createGain();
                const now = ctx.currentTime;

                osc.type = 'sine';
                osc.frequency.setValueAtTime(659.25, now); // E5
                osc.frequency.exponentialRampToValueAtTime(880, now + 0.12); // A5

                gain.gain.setValueAtTime(0.08, now);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.16);

                osc.connect(gain);
                gain.connect(ctx.destination);

                osc.start(now);
                osc.stop(now + 0.16);

                osc.onended = () => {
                    try { ctx.close(); } catch (e) {}
                };
            } catch (e) {
                console.debug('Sent chime suppressed', e);
            }
        },

        playMessageReceivedChime() {
            if (this.soundMuted) return;
            try {
                const ctx = createSafeAudioContext();
                if (!ctx) return;
                const now = ctx.currentTime;

                const osc1 = ctx.createOscillator();
                const osc2 = ctx.createOscillator();
                const gain = ctx.createGain();

                osc1.type = 'sine';
                osc2.type = 'sine';

                osc1.frequency.setValueAtTime(523.25, now); // C5
                osc1.frequency.setValueAtTime(659.25, now + 0.08); // E5

                osc2.frequency.setValueAtTime(783.99, now); // G5
                osc2.frequency.setValueAtTime(1046.50, now + 0.08); // C6

                gain.gain.setValueAtTime(0.08, now);
                gain.gain.exponentialRampToValueAtTime(0.0001, now + 0.22);

                osc1.connect(gain);
                osc2.connect(gain);
                gain.connect(ctx.destination);

                osc1.start(now);
                osc2.start(now);
                osc1.stop(now + 0.22);
                osc2.stop(now + 0.22);

                osc1.onended = () => {
                    try { ctx.close(); } catch (e) {}
                };
            } catch (e) {
                console.debug('Received chime suppressed', e);
            }
        },

        openFilePreview(url, name, type, size, isPdf = false) {
            this.previewModal.open = true;
            this.previewModal.url = url || '';
            this.previewModal.name = name || '';
            this.previewModal.type = type || 'image';
            this.previewModal.size = size || '';
            this.previewModal.isPdf = isPdf;
        },

        closeFilePreview() {
            this.previewModal.open = false;
            this.previewModal.url = '';
        },

        scrollToBottom() {
            if (typeof this.$nextTick === 'function') {
                this.$nextTick(() => {
                    const container = this.$refs?.messagesContainer;
                    if (container) {
                        try {
                            container.scrollTop = container.scrollHeight;
                        } catch (e) {}
                    }
                });
            }
        },

        scrollToMessage(id) {
            const el = document.getElementById('msg-' + id);
            if (el) {
                try {
                    el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    this.highlightedMessageId = id;
                    this.setTimeoutTracked(() => {
                        this.highlightedMessageId = null;
                    }, 2500);
                } catch (e) {}
            }
        },

        insertEmoji(emoji) {
            if (this.$wire && typeof this.$wire.messageText !== 'undefined') {
                this.$wire.messageText += emoji;
            }
            this.showEmojiPicker = false;
        }
    };
}


// -------------------------------------------------------------
// 5. Image Editor Alpine Component
// -------------------------------------------------------------
export function alpineImageEditor(config = {}) {
    return {
        isOpen: false,
        imageSrc: null,
        originalImg: null,
        imageTarget: null,
        activeTab: 'crop',
        aspectRatio: config.aspectRatio || 'free',
        rotation: 0,
        flipH: false,
        flipV: false,
        cropX: 0,
        cropY: 0,
        cropW: 0,
        cropH: 0,
        isDrawing: false,
        drawColor: '#ef4444',
        brushSize: 4,
        drawActions: [],
        currentStroke: [],
        textOverlays: [],
        newText: '',
        textColor: '#ffffff',
        textSize: 24,
        emojiOverlays: [],
        displayWidth: 400,
        displayHeight: 300,
        onSaveCallback: null,
        _listeners: [],

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

        init() {
            if (typeof this.$cleanup === 'function') {
                this.$cleanup(() => {
                    this.destroy();
                });
            }
        },

        destroy() {
            this.clearAllListeners();
            this.isOpen = false;
            this.imageSrc = null;
            this.originalImg = null;
        },

        open(payload, callback) {
            const data = extractEventData(payload);
            let src = (typeof payload === 'string' ? payload : (data.src || data.image || data.url || ''));
            let cb = callback || data.callback || null;
            let target = data.target || (typeof callback === 'string' ? callback : null);
            let ratio = data.aspectRatio || config.aspectRatio || 'free';

            this.imageTarget = target;
            this.aspectRatio = ratio;
            this.imageSrc = src;
            this.onSaveCallback = cb;
            this.rotation = 0;
            this.flipH = false;
            this.flipV = false;
            this.drawActions = [];
            this.textOverlays = [];
            this.emojiOverlays = [];
            this.activeTab = 'crop';
            this.isOpen = true;
            
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = () => {
                this.originalImg = img;
                if (typeof this.$nextTick === 'function') {
                    this.$nextTick(() => {
                        this.initCanvas();
                    });
                }
            };
            img.src = src;
        },

        close() {
            this.isOpen = false;
            this.imageSrc = null;
            this.originalImg = null;
        },

        initCanvas() {
            const canvas = this.$refs?.mainCanvas;
            const container = this.$refs?.canvasContainer;
            if (!canvas || !container || !this.originalImg) return;
            
            const maxW = Math.min(container.clientWidth - 32, 680);
            const maxH = Math.min(window.innerHeight * 0.55, 480);
            
            let w = this.originalImg.naturalWidth || 400;
            let h = this.originalImg.naturalHeight || 300;
            
            const scale = Math.min(maxW / w, maxH / h, 1);
            this.displayWidth = Math.max(100, Math.round(w * scale));
            this.displayHeight = Math.max(100, Math.round(h * scale));
            
            canvas.width = this.displayWidth;
            canvas.height = this.displayHeight;
            
            this.resetCropBox();
            this.render();
        },

        resetCropBox() {
            const w = this.displayWidth;
            const h = this.displayHeight;
            
            if (this.aspectRatio === '1:1' || this.aspectRatio === 'circle') {
                const size = Math.min(w, h) * 0.8;
                this.cropW = size;
                this.cropH = size;
            } else if (this.aspectRatio === '4:3') {
                const sizeW = w * 0.8;
                this.cropW = sizeW;
                this.cropH = sizeW * (3 / 4);
            } else if (this.aspectRatio === '16:9') {
                const sizeW = w * 0.8;
                this.cropW = sizeW;
                this.cropH = sizeW * (9 / 16);
            } else {
                this.cropW = w * 0.9;
                this.cropH = h * 0.9;
            }
            
            this.cropX = Math.round((w - this.cropW) / 2);
            this.cropY = Math.round((h - this.cropH) / 2);
        },

        setAspectRatio(ratio) {
            this.aspectRatio = ratio;
            this.resetCropBox();
        },

        rotateRight() {
            this.rotation = (this.rotation + 90) % 360;
            this.render();
        },

        toggleFlipH() {
            this.flipH = !this.flipH;
            this.render();
        },

        toggleFlipV() {
            this.flipV = !this.flipV;
            this.render();
        },

        startDrawing(e) {
            if (this.activeTab !== 'draw') return;
            this.isDrawing = true;
            const canvas = this.$refs?.mainCanvas;
            if (!canvas) return;
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            this.currentStroke = [{ x, y }];
        },

        draw(e) {
            if (!this.isDrawing || this.activeTab !== 'draw') return;
            const canvas = this.$refs?.mainCanvas;
            if (!canvas) return;
            const rect = canvas.getBoundingClientRect();
            const x = e.clientX - rect.left;
            const y = e.clientY - rect.top;
            this.currentStroke.push({ x, y });
            this.render();
        },

        stopDrawing() {
            if (!this.isDrawing) return;
            this.isDrawing = false;
            if (this.currentStroke.length > 0) {
                this.drawActions.push({
                    color: this.drawColor,
                    size: this.brushSize,
                    points: [...this.currentStroke]
                });
                this.currentStroke = [];
            }
        },

        addTextOverlay() {
            const text = (this.newText || '').trim();
            if (!text) return;
            this.textOverlays.push({
                text: text,
                color: this.textColor,
                size: parseInt(this.textSize, 10) || 24,
                x: this.displayWidth / 2,
                y: this.displayHeight / 2
            });
            this.newText = '';
            this.render();
        },

        addEmojiOverlay(emoji) {
            this.emojiOverlays.push({
                emoji: emoji,
                size: 36,
                x: this.displayWidth / 2,
                y: this.displayHeight / 2
            });
            this.render();
        },

        undo() {
            if (this.drawActions.length > 0) {
                this.drawActions.pop();
            } else if (this.textOverlays.length > 0) {
                this.textOverlays.pop();
            } else if (this.emojiOverlays.length > 0) {
                this.emojiOverlays.pop();
            }
            this.render();
        },

        render() {
            const canvas = this.$refs?.mainCanvas;
            if (!canvas || !this.originalImg) return;
            const ctx = canvas.getContext('2d');
            if (!ctx) return;
            
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.save();
            
            ctx.translate(canvas.width / 2, canvas.height / 2);
            ctx.rotate((this.rotation * Math.PI) / 180);
            ctx.scale(this.flipH ? -1 : 1, this.flipV ? -1 : 1);
            ctx.drawImage(
                this.originalImg,
                -canvas.width / 2,
                -canvas.height / 2,
                canvas.width,
                canvas.height
            );
            ctx.restore();
            
            this.drawActions.forEach((action) => {
                if (!action.points || action.points.length < 2) return;
                ctx.beginPath();
                ctx.strokeStyle = action.color;
                ctx.lineWidth = action.size;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.moveTo(action.points[0].x, action.points[0].y);
                for (let i = 1; i < action.points.length; i++) {
                    ctx.lineTo(action.points[i].x, action.points[i].y);
                }
                ctx.stroke();
            });
            
            if (this.currentStroke.length > 1) {
                ctx.beginPath();
                ctx.strokeStyle = this.drawColor;
                ctx.lineWidth = this.brushSize;
                ctx.lineCap = 'round';
                ctx.lineJoin = 'round';
                ctx.moveTo(this.currentStroke[0].x, this.currentStroke[0].y);
                for (let i = 1; i < this.currentStroke.length; i++) {
                    ctx.lineTo(this.currentStroke[i].x, this.currentStroke[i].y);
                }
                ctx.stroke();
            }
            
            this.textOverlays.forEach((item) => {
                ctx.save();
                ctx.font = `bold ${item.size}px sans-serif`;
                ctx.fillStyle = item.color;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.shadowColor = 'rgba(0,0,0,0.8)';
                ctx.shadowBlur = 4;
                ctx.fillText(item.text, item.x, item.y);
                ctx.restore();
            });
            
            this.emojiOverlays.forEach((item) => {
                ctx.save();
                ctx.font = `${item.size}px sans-serif`;
                ctx.textAlign = 'center';
                ctx.textBaseline = 'middle';
                ctx.fillText(item.emoji, item.x, item.y);
                ctx.restore();
            });
        },

        applyAndExport() {
            const canvas = this.$refs?.mainCanvas;
            if (!canvas) return;

            const tempCanvas = document.createElement('canvas');
            const tempCtx = tempCanvas.getContext('2d');
            if (!tempCtx) return;
            
            let cropW = Math.max(20, this.cropW);
            let cropH = Math.max(20, this.cropH);
            let cropX = Math.max(0, this.cropX);
            let cropY = Math.max(0, this.cropY);
            
            tempCanvas.width = cropW;
            tempCanvas.height = cropH;
            
            if (this.aspectRatio === 'circle') {
                tempCtx.beginPath();
                tempCtx.arc(cropW / 2, cropH / 2, Math.min(cropW, cropH) / 2, 0, Math.PI * 2);
                tempCtx.closePath();
                tempCtx.clip();
            }
            
            tempCtx.drawImage(
                canvas,
                cropX, cropY, cropW, cropH,
                0, 0, cropW, cropH
            );
            
            const base64 = tempCanvas.toDataURL('image/png', 0.92);
            
            if (typeof this.onSaveCallback === 'function') {
                this.onSaveCallback(base64, this.imageTarget);
            }
            
            window.dispatchEvent(new CustomEvent('image-editor-saved', { 
                detail: { base64: base64, target: this.imageTarget } 
            }));

            // Direct hook into Livewire component if available
            if (this.$wire) {
                if (this.imageTarget === 'attachment') {
                    if (typeof this.$wire.saveBase64Attachment === 'function') {
                        this.$wire.saveBase64Attachment(base64);
                    }
                } else if (this.imageTarget) {
                    if (typeof this.$wire.saveBase64Avatar === 'function') {
                        this.$wire.saveBase64Avatar(this.imageTarget, base64);
                    }
                }
            }
            
            this.close();
        }
    };
}

// -------------------------------------------------------------
// Universal Alpine and Global Window Registry
// -------------------------------------------------------------

// Always attach factories directly onto `window` so x-data="chatAlpine()" works everywhere
window.chatPreCallPreviewAlpine = chatPreCallPreviewAlpine;
window.chatCallOverlayAlpine = chatCallOverlayAlpine;
window.chatAlpine = chatAlpine;
window.alpineImageEditor = alpineImageEditor;

export function registerChatAndMediaComponents() {
    // Re-verify window globals
    window.chatPreCallPreviewAlpine = chatPreCallPreviewAlpine;
    window.chatCallOverlayAlpine = chatCallOverlayAlpine;
    window.chatAlpine = chatAlpine;
        window.alpineImageEditor = alpineImageEditor;

    const alpineInstance = window.Alpine || (window.Livewire && window.Livewire.Alpine);

    if (alpineInstance && typeof alpineInstance.data === 'function') {
        try {
            alpineInstance.data('chatPreCallPreviewAlpine', chatPreCallPreviewAlpine);
            alpineInstance.data('chatCallOverlayAlpine', chatCallOverlayAlpine);
            alpineInstance.data('chatAlpine', chatAlpine);
                        alpineInstance.data('alpineImageEditor', alpineImageEditor);
        } catch (e) {
            console.debug('Alpine.data registration note:', e);
        }
    }
}

// Auto-register immediately upon script execution
registerChatAndMediaComponents();

// Listen across all Alpine and Livewire lifecycle events
['alpine:init', 'alpine:initialized', 'livewire:init', 'livewire:initialized', 'livewire:navigated'].forEach((evt) => {
    document.addEventListener(evt, () => {
        registerChatAndMediaComponents();
    });
});
