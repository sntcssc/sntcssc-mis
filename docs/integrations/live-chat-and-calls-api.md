# SNTCSSC MIS — Realtime Live Chat, WebRTC Calling & Online Meetings REST API Integration Guide
### Multi-Platform Enterprise Implementation Guide (Flutter, React Native, iOS Swift, Android Kotlin, Electron Desktop & Web Apps)

---

## 1. Executive Summary & Architecture Overview

The **Live Chat, WebRTC Calling & Online Meetings Module** of the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS** delivers a high-concurrency, real-time communication platform. Designed for cross-platform integration across **Mobile Apps** (Flutter, React Native, Swift, Kotlin), **Web Applications** (Vue, React, Livewire), and **Desktop Clients** (Electron, Tauri, C# .NET), this system powers instantaneous messaging, group channels, broadcast announcements, peer-to-peer and mesh WebRTC audio/video calls, and dedicated virtual classrooms/video conferences.

### Core Architectural Capabilities:
1. **Realtime Dual Engine:** Powered by **Laravel Reverb** / Pusher WebSockets for sub-10ms event distribution (new message delivery, typing indicators, read receipts, participant presence, and WebRTC signal exchanges) paired with fallback HTTP polling endpoints.
2. **WebRTC Signaling & Media Flow:** End-to-end SDP (Session Description Protocol) offer/answer negotiation and ICE candidate exchange via WebSockets or authenticated REST endpoints, backed by dynamic STUN/TURN configuration.
3. **Versatile Messaging Hierarchy:**
   - **Direct 1-on-1 Chats:** Peer-to-peer conversations with automated thread discovery.
   - **Group Conversations:** Multi-user chats with designated administrators, moderators, and membership management.
   - **Official Channels:** Broadcast and announcement channels with granular permission gates (`is_broadcast_only`).
   - **Personalized Broadcast Dispatch:** Mass targeted announcements to specific cohorts, academic batches, courses, or roles.
4. **Rich Communication Attributes:**
   - Multi-file attachments (images, PDFs, documents up to 25MB).
   - Threaded replies (`reply_to_id`).
   - Message pinning and emoji reactions.
   - Per-user message deletion and administrative delete-for-everyone.
5. **Dedicated Online Meetings & Virtual Classrooms:**
   - Passcode protection and Waiting Room admission workflow.
   - Host controls: Mute all attendees, screen-sharing locks, room locking, and co-host delegation.
   - Live meeting reactions and bulk email calendar invitations.
6. **Dual Authentication Gateway:**
   - **Sanctum Personal Access Tokens (`auth:sanctum`):** Ideal for end-user native mobile and desktop applications.
   - **Enterprise API Keys (`auth.api_key`):** Suited for backend microservices, kiosk hardware, and server-to-server integrations with granular scopes (`read:chat`, `write:chat`, `call:chat`, `meeting:chat`).

---

## 2. System Architecture & Sequence Flows

### 2.1 Multi-Platform Communication Architecture

```mermaid
graph TD
    subgraph Multi-Platform Clients
        Mobile[Mobile App\nFlutter / React Native]
        Web[Web Portal\nReact / Vue / Livewire]
        Desktop[Desktop Client\nElectron / C# .NET]
    end

    subgraph API & Signaling Gateway
        GW[API Gateway / Route Engine]
        AuthGuard{Auth Guard\nSanctum / API Key}
        ScopeCheck{Scope Check\nread:chat\nwrite:chat\ncall:chat\nmeeting:chat}
    end

    subgraph Realtime & Media Infrastructure
        ReverbWS[Laravel Reverb WebSockets\nEvents & Signals]
        STUN_TURN[Coturn / Xirsys\nSTUN & TURN Servers]
    end

    subgraph Application Core
        ChatCtrl[ChatApiController]
        ChatSvc[ChatService]
        CallSvc[WebRtcCallService]
        MeetSvc[MeetingService]
        AuditEngine[AuditLogService]
    end

    subgraph Persistence Layer
        DB[(MySQL / PostgreSQL)]
        Storage[(Local / S3 Storage)]
    end

    Mobile -->|Bearer Token / API Key| GW
    Web -->|Sanctum Session / API Key| GW
    Desktop -->|API Key / Bearer Token| GW

    GW --> AuthGuard
    AuthGuard --> ScopeCheck
    ScopeCheck --> ChatCtrl

    ChatCtrl --> ChatSvc
    ChatCtrl --> CallSvc
    ChatCtrl --> MeetSvc

    ChatSvc --> DB
    ChatSvc --> Storage
    ChatSvc --> ReverbWS
    ChatSvc --> AuditEngine

    CallSvc --> ReverbWS
    CallSvc --> STUN_TURN
    CallSvc --> DB

    MeetSvc --> ReverbWS
    MeetSvc --> DB
```

### 2.2 WebRTC 1-on-1 Audio/Video Call Signaling Sequence

```mermaid
sequenceDiagram
    autonumber
    actor Caller as Caller (App A)
    participant API as SNTCSSC MIS API
    participant WS as Reverb WebSockets
    actor Callee as Callee (App B)

    Caller->>API: POST /api/v1/chat/calls/initiate {conversation_id, call_type: "video"}
    API-->>Caller: 201 Created {uuid, ice_servers, status: "ringing"}
    API->>WS: Broadcast WebRtcCallSignalEvent (Incoming Call)
    WS-->>Callee: Event: WebRtcCallSignalEvent (type: "incoming_call", call_uuid)

    Callee->>API: POST /api/v1/chat/calls/{uuid}/accept
    API-->>Callee: 200 OK {status: "connected", ice_servers}
    API->>WS: Broadcast WebRtcCallSignalEvent (type: "call_accepted")
    WS-->>Caller: Event: WebRtcCallSignalEvent (type: "call_accepted")

    Note over Caller,Callee: WebRTC P2P Peer Connection Setup
    Caller->>API: POST /api/v1/chat/calls/{uuid}/signal {signal_type: "offer", payload: SDP}
    API->>WS: Relay SDP Offer to Callee
    WS-->>Callee: Signal Event {signal_type: "offer", payload: SDP}

    Callee->>API: POST /api/v1/chat/calls/{uuid}/signal {signal_type: "answer", payload: SDP}
    API->>WS: Relay SDP Answer to Caller
    WS-->>Caller: Signal Event {signal_type: "answer", payload: SDP}

    Caller->>API: POST /api/v1/chat/calls/{uuid}/signal {signal_type: "candidate", payload: ICE}
    API->>WS: Relay ICE Candidate to Callee
    WS-->>Callee: Candidate Event

    Note over Caller,Callee: Direct Encrypted SRTP Audio/Video Stream Established
```

---

## 3. Authentication, Base URLs & Scopes Matrix

### 3.1 Base URLs

| Environment | Base URL | WebSocket Server |
| :--- | :--- | :--- |
| **Production** | `https://sntcssc.org/api/v1` | `wss://sntcssc.org:8080/app/{app_key}` |
| **Sandbox / Staging** | `https://staging.sntcssc.org/api/v1` | `wss://staging.sntcssc.org:8080/app/{app_key}` |
| **Local Development** | `http://127.0.0.1:8000/api/v1` | `ws://127.0.0.1:8080/app/{app_key}` |

### 3.2 Authentication Headers

Requests must pass authentication using either standard Sanctum user tokens or institutional API Keys:

```http
# Option A: Institutional API Key
X-API-Key: sntc_live_9d8f3a1b4c7e2...
# or
Authorization: Bearer sntc_live_9d8f3a1b4c7e2...

# Option B: Native App Logged-In User Bearer Token (Sanctum)
Authorization: Bearer 42|N8r0w0iZ9oPkA1yUvBc...
```

### 3.3 API Scopes Reference

| Scope Key | Scope Category | Intended Actions & Permissions |
| :--- | :--- | :--- |
| `read:chat` | Chat & Presence | Browse conversations, fetch message history, participant lists, and call logs. |
| `write:chat` | Messaging & Channels | Send messages, post attachments, react, create groups/channels, update roles. |
| `call:chat` | WebRTC Calling | Initiate, answer, reject, join, and signal 1-on-1 and group voice/video calls. |
| `meeting:chat` | Video Conferencing | Schedule meetings, admit attendees from waiting rooms, control host settings. |

---

## 4. Complete RESTful API Endpoints Reference

### 4.1 System & Telemetry

#### `GET /api/v1/chat/status`
* **Scope:** `read:chat` or `ping`
* **Description:** Retrieves realtime system health, Reverb server connectivity, ICE server status, and active call/meeting counters.
* **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "realtime": {
      "driver": "reverb",
      "connected": true,
      "host": "127.0.0.1",
      "port": 8080
    },
    "ice_configured": true,
    "telemetry": {
      "active_calls": 3,
      "ongoing_meetings": 1,
      "server_time": "2026-10-31T21:15:00+05:30"
    },
    "authenticated_user": {
      "id": 1,
      "name": "Prof. S. Sengupta",
      "email": "director@sntcssc.org"
    }
  }
}
```

#### `GET /api/v1/chat/calls/ice-servers`
* **Scope:** `read:chat`, `call:chat`, or `meeting:chat`
* **Description:** Returns the active STUN/TURN server configuration for WebRTC peer connections.
* **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "ice_servers": [
      { "urls": "stun:stun.l.google.com:19302" },
      {
        "urls": "turn:turn.sntcssc.org:3478?transport=udp",
        "username": "sntcssc_user",
        "credential": "secure_dynamic_token"
      }
    ],
    "ice_transport_policy": "all",
    "ice_candidate_pool_size": 10
  }
}
```

---

### 4.2 Conversations Management

#### `GET /api/v1/chat/conversations`
* **Scope:** `read:chat`
* **Query Parameters:**
  - `type` (optional): `direct`, `group`, or `channel`
  - `search` (optional): Search by name or description
  - `per_page` (optional, default: 20, max: 100)
* **Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 4,
      "uuid": "9d8a1c3e-8f2b-4d7a-8f1e-9a2c3e4d5f6a",
      "type": "group",
      "title": "WBCS 2026 Batch Alpha — Study Group",
      "raw_title": "WBCS 2026 Batch Alpha — Study Group",
      "description": "Official peer discussion group for Batch Alpha students.",
      "avatar_url": "/images/avatars/group-default.png",
      "is_channel": false,
      "is_group": true,
      "is_direct": false,
      "unread_count": 3,
      "last_message": {
        "id": 142,
        "body": "Sir has uploaded the Mains answer writing strategy PDF.",
        "type": "text",
        "created_at": "2026-10-31T20:45:10+05:30",
        "sender": { "id": 12, "name": "Ananya Roy" }
      },
      "participants_count": 48,
      "updated_at": "2026-10-31T20:45:10+05:30"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 1,
    "last_page": 1
  }
}
```

#### `POST /api/v1/chat/conversations/direct`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "recipient_id": 15
}
```
* **Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "id": 8,
    "uuid": "e3a1c7d2-4b5f-6a7e-8c9d-0e1f2a3b4c5d",
    "type": "direct",
    "title": "Rahul Mukherjee",
    "avatar_url": "https://sntcssc.org/storage/avatars/rahul.jpg",
    "recipient": {
      "id": 15,
      "name": "Rahul Mukherjee",
      "email": "rahul.m@gmail.com",
      "avatar": "avatars/rahul.jpg"
    },
    "created_at": "2026-10-31T21:00:00+05:30"
  }
}
```

#### `POST /api/v1/chat/conversations/group`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "title": "UPSC Mains Ethics Discussion",
  "description": "GS Paper IV case studies and ethics preparation circle.",
  "participant_ids": [15, 22, 38]
}
```

#### `POST /api/v1/chat/conversations/channel`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "title": "Official Administrative Notices",
  "description": "One-way broadcasts for test schedules and holiday declarations.",
  "is_broadcast_only": true,
  "participant_ids": [15, 22, 38, 45]
}
```

#### `GET /api/v1/chat/conversations/{id}`
* **Scope:** `read:chat`
* **Description:** Retrieves conversation details, active participant roster with administrative flags, and pinned message history.

#### `PUT /api/v1/chat/conversations/{id}`
* **Scope:** `write:chat`
* **Description:** Update conversation title, description, or broadcast-only flag (requires admin role in conversation).

#### `DELETE /api/v1/chat/conversations/{id}`
* **Scope:** `write:chat`
* **Description:** Leaves the conversation or soft-deletes the thread if initiated by an administrator.

#### `POST /api/v1/chat/conversations/{id}/read`
* **Scope:** `write:chat`
* **Description:** Marks all unread messages as read and dispatches real-time read receipt events (`ChatMessageReadEvent`).

#### `POST /api/v1/chat/conversations/{id}/typing`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "is_typing": true
}
```

#### `POST /api/v1/chat/conversations/{id}/participants`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "user_id": 42,
  "role": "member"
}
```

#### `DELETE /api/v1/chat/conversations/{id}/participants/{userId}`
* **Scope:** `write:chat`
* **Description:** Removes a participant from the conversation.

#### `PUT /api/v1/chat/conversations/{id}/participants/{userId}/role`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "role": "moderator"
}
```

---

### 4.3 Messages & Attachments

#### `GET /api/v1/chat/conversations/{id}/messages`
* **Scope:** `read:chat`
* **Query Parameters:**
  - `before_id` (optional): Message cursor ID for backwards pagination
  - `per_page` (optional, default: 50, max: 100)
* **Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 142,
      "conversation_id": 4,
      "sender": {
        "id": 12,
        "name": "Ananya Roy",
        "email": "ananya.roy@example.com",
        "avatar": "avatars/ananya.jpg"
      },
      "is_me": false,
      "body": "Please review the attached Indian Polity handout for tomorrow's lecture.",
      "type": "file",
      "is_pinned": true,
      "pinned_at": "2026-10-31T20:30:00+05:30",
      "edited_at": null,
      "reply_to": null,
      "attachments": [
        {
          "id": 28,
          "file_name": "Polity_Handout_04.pdf",
          "file_url": "https://sntcssc.org/storage/chat/attachments/polity_04.pdf",
          "file_type": "application/pdf",
          "file_size": 2458120,
          "is_image": false
        }
      ],
      "reactions": [
        {
          "emoji": "👍",
          "count": 14,
          "reacted_by_me": true
        },
        {
          "emoji": "❤️",
          "count": 5,
          "reacted_by_me": false
        }
      ],
      "created_at": "2026-10-31T20:25:00+05:30"
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 50,
    "total": 142,
    "has_more": true
  }
}
```

#### `POST /api/v1/chat/conversations/{id}/messages`
* **Scope:** `write:chat`
* **Content-Type:** `multipart/form-data` or `application/json`
* **Payload:**
  - `body` (string, optional if attachments present)
  - `reply_to_id` (integer, optional)
  - `attachments[]` (binary files, max 25MB each)
* **Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "id": 143,
    "conversation_id": 4,
    "body": "Noted, thank you!",
    "type": "text",
    "created_at": "2026-10-31T21:12:00+05:30",
    "sender": {
      "id": 1,
      "name": "Prof. S. Sengupta",
      "avatar": null
    },
    "attachments_count": 0
  }
}
```

#### `PUT /api/v1/chat/messages/{messageId}`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "body": "Updated message content."
}
```

#### `DELETE /api/v1/chat/messages/{messageId}`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "mode": "everyone"
}
```

#### `POST /api/v1/chat/messages/{messageId}/pin`
* **Scope:** `write:chat`
* **Description:** Pins message to the top of the conversation.

#### `DELETE /api/v1/chat/messages/{messageId}/pin`
* **Scope:** `write:chat`
* **Description:** Unpins message.

#### `POST /api/v1/chat/messages/{messageId}/reaction`
* **Scope:** `write:chat`
* **Payload:**
```json
{
  "emoji": "🔥"
}
```

#### `POST /api/v1/chat/broadcast`
* **Scope:** `write:chat` (requires `chat.broadcast` permission)
* **Payload:**
```json
{
  "title": "Admit Card Download Alert",
  "body": "Mock Test 06 Hall Tickets are now available for download in the student portal.",
  "recipient_user_ids": [12, 15, 22, 28, 35, 41]
}
```

---

### 4.4 WebRTC Calls & Realtime Voice/Video Calling

#### `POST /api/v1/chat/calls/initiate`
* **Scope:** `call:chat`
* **Payload:**
```json
{
  "conversation_id": 4,
  "call_type": "video"
}
```
* **Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "uuid": "call-7b9e2a4d-1c3f-4e8a-9a0b-1c2d3e4f5a6b",
    "conversation_id": 4,
    "call_type": "video",
    "status": "ringing",
    "caller_id": 1,
    "receiver_id": 15,
    "ice_servers": [
      { "urls": "stun:stun.l.google.com:19302" }
    ],
    "created_at": "2026-10-31T21:20:00+05:30"
  }
}
```

#### `POST /api/v1/chat/calls/group-initiate`
* **Scope:** `call:chat`
* **Payload:**
```json
{
  "conversation_id": 4,
  "call_type": "audio"
}
```

#### `POST /api/v1/chat/calls/{callUuid}/signal`
* **Scope:** `call:chat`
* **Payload:**
```json
{
  "signal_type": "offer",
  "target_user_id": 15,
  "payload": {
    "type": "offer",
    "sdp": "v=0\r\no=- 4611731400 2 IN IP4 127.0.0.1..."
  }
}
```
* **Supported `signal_type` values:**
  - `offer`: Initial WebRTC SDP offer from caller.
  - `answer`: Counter SDP answer from callee.
  - `candidate`: Trickle ICE candidate object.
  - `hangup`: Call termination signal.
  - `mode_switch_request`: Request to upgrade from audio to video (or vice-versa).
  - `mode_switch_response`: Accept/reject mode switch.

#### `POST /api/v1/chat/calls/{callUuid}/accept`
* **Scope:** `call:chat`
* **Description:** Callee accepts incoming call session.

#### `POST /api/v1/chat/calls/{callUuid}/reject`
* **Scope:** `call:chat`
* **Payload:**
```json
{
  "reason": "busy"
}
```

#### `POST /api/v1/chat/calls/{callUuid}/join`
* **Scope:** `call:chat`
* **Description:** Join an ongoing group call session.

#### `POST /api/v1/chat/calls/{callUuid}/leave`
* **Scope:** `call:chat`
* **Description:** Leaves active call without terminating it for other members.

#### `POST /api/v1/chat/calls/{callUuid}/end`
* **Scope:** `call:chat`
* **Description:** Immediately terminates the call session for all participants.

#### `GET /api/v1/chat/calls/{callUuid}/status`
* **Scope:** `call:chat` or `read:chat`
* **Description:** Returns live call status, active participants, and duration.

#### `GET /api/v1/chat/calls/history`
* **Scope:** `call:chat` or `read:chat`
* **Description:** Paginated call history log (incoming, outgoing, missed, duration).

---

### 4.5 Online Meetings & Video Conferencing

#### `GET /api/v1/chat/meetings`
* **Scope:** `meeting:chat` or `read:chat`
* **Query Parameters:**
  - `status`: `scheduled`, `in_progress`, `ended`, `cancelled`
  - `search`: Keyword search across titles or invite codes

#### `POST /api/v1/chat/meetings`
* **Scope:** `meeting:chat`
* **Payload:**
```json
{
  "title": "Civil Services Prelims Masterclass 2026",
  "description": "Comprehensive strategy on Indian Economy & Current Affairs.",
  "meeting_type": "scheduled",
  "start_time": "2026-11-05T10:00:00+05:30",
  "duration_minutes": 90,
  "passcode": "SNTC2026",
  "require_passcode": true,
  "waiting_room_enabled": true,
  "allow_screen_share": true,
  "mute_upon_entry": true
}
```
* **Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "uuid": "meet-3a7b9c1d-8e2f-4a5b-9c0d-1e2f3a4b5c6d",
    "title": "Civil Services Prelims Masterclass 2026",
    "description": "Comprehensive strategy on Indian Economy & Current Affairs.",
    "meeting_type": "scheduled",
    "status": "scheduled",
    "invite_code": "SNTC-PRELIMS-2026",
    "passcode": "SNTC2026",
    "waiting_room_enabled": true,
    "start_time": "2026-11-05T10:00:00+05:30",
    "duration_minutes": 90,
    "join_url": "https://sntcssc.org/meetings/room/meet-3a7b9c1d-8e2f-4a5b-9c0d-1e2f3a4b5c6d"
  }
}
```

#### `GET /api/v1/chat/meetings/{uuid}`
* **Scope:** `meeting:chat` or `read:chat`
* **Description:** Retrieve single meeting dossier, host credentials, restrictions, and participant roster.

#### `PUT /api/v1/chat/meetings/{uuid}`
* **Scope:** `meeting:chat`
* **Description:** Update scheduled meeting start time, title, duration, or security toggles.

#### `DELETE /api/v1/chat/meetings/{uuid}`
* **Scope:** `meeting:chat`
* **Payload:**
```json
{
  "reason": "Rescheduled due to State Civil Services preliminary examination."
}
```

#### `POST /api/v1/chat/meetings/{uuid}/join`
* **Scope:** `meeting:chat` or `read:chat`
* **Payload:**
```json
{
  "passcode": "SNTC2026"
}
```
* **Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "participant_id": 84,
    "role": "attendee",
    "status": "waiting",
    "is_admitted": false,
    "in_waiting_room": true,
    "ice_servers": [
      { "urls": "stun:stun.l.google.com:19302" }
    ],
    "meeting": {
      "uuid": "meet-3a7b9c1d-8e2f-4a5b-9c0d-1e2f3a4b5c6d",
      "title": "Civil Services Prelims Masterclass 2026",
      "status": "in_progress",
      "allow_screen_share": true,
      "mute_upon_entry": true
    }
  }
}
```

#### `POST /api/v1/chat/meetings/{uuid}/signal`
* **Scope:** `meeting:chat`
* **Description:** WebRTC signal exchange for meeting audio, video, and screen sharing tracks.

#### `GET /api/v1/chat/meetings/{uuid}/sync`
* **Scope:** `meeting:chat`
* **Description:** Rapid poll and synchronization of active speakers, waiting room changes, hand raises, and reactions.

#### `POST /api/v1/chat/meetings/{uuid}/participants/{participantId}/admit`
* **Scope:** `meeting:chat`
* **Description:** Host admits an attendee from the waiting room into the active meeting.

#### `POST /api/v1/chat/meetings/{uuid}/participants/{participantId}/deny`
* **Scope:** `meeting:chat`
* **Description:** Host rejects an attendee from the waiting room.

#### `PUT /api/v1/chat/meetings/{uuid}/participants/{participantId}/role`
* **Scope:** `meeting:chat`
* **Payload:**
```json
{
  "role": "co_host"
}
```

#### `POST /api/v1/chat/meetings/{uuid}/restrictions`
* **Scope:** `meeting:chat`
* **Payload:**
```json
{
  "lock_meeting": true,
  "mute_all": true,
  "allow_screen_share": false
}
```

#### `POST /api/v1/chat/meetings/{uuid}/reaction`
* **Scope:** `meeting:chat`
* **Payload:**
```json
{
  "emoji": "👏"
}
```

#### `POST /api/v1/chat/meetings/{uuid}/invite`
* **Scope:** `meeting:chat`
* **Payload:**
```json
{
  "emails": ["candidate1@gmail.com", "candidate2@gmail.com"]
}
```

#### `POST /api/v1/chat/meetings/{uuid}/end`
* **Scope:** `meeting:chat`
* **Description:** Terminates the online meeting for all participants.

---

## 5. WebSockets & Realtime Broadcasting Channels

The backend dispatches real-time broadcast events over **Laravel Reverb / Pusher WebSockets**:

### 5.1 Channel Authorization Matrix

| Channel Pattern | Channel Type | Access Rule | Realtime Events Dispatched |
| :--- | :--- | :--- | :--- |
| `user.{id}` | Private | Authenticated user matches `{id}` | `WebRtcCallSignalEvent` (Incoming call alerting, call status notifications) |
| `conversation.{id}` | Private | User is an active conversation participant | `ChatMessageSentEvent`, `ChatMessageUpdatedEvent`, `ChatMessageReadEvent`, `ChatUserTypingEvent` |
| `call.{uuid}` | Private | User is caller, receiver, or ongoing call participant | `WebRtcCallSignalEvent` (SDP Offers, Answers, ICE Candidates, Mode Switches) |
| `meeting.{uuid}` | Private | User is host, admitted attendee, or open meeting | `MeetingRealtimeEvent` (Signals, reactions, participant admission, restrictions) |

### 5.2 Realtime Event Payload Schemas

#### Event: `ChatMessageSentEvent`
Channel: `conversation.{conversation_id}`
```json
{
  "id": 143,
  "conversation_id": 4,
  "user_id": 1,
  "sender_name": "Prof. S. Sengupta",
  "body": "Class begins in 10 minutes.",
  "type": "text",
  "created_at": "2026-10-31T21:12:00+05:30",
  "attachments": []
}
```

#### Event: `WebRtcCallSignalEvent`
Channel: `call.{uuid}` or `user.{recipient_id}`
```json
{
  "call_uuid": "call-7b9e2a4d-1c3f-4e8a-9a0b-1c2d3e4f5a6b",
  "sender_id": 1,
  "signal_type": "offer",
  "target_user_id": 15,
  "payload": {
    "type": "offer",
    "sdp": "v=0\r\no=- 4611731400 2 IN IP4 127.0.0.1..."
  }
}
```

---

## 6. Multi-Platform SDK Integration Recipes

### 6.1 Flutter (Dart) Mobile App Implementation

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;
import 'package:flutter_webrtc/flutter_webrtc.dart';

class SntcsscLiveChatService {
  final String baseUrl = 'https://sntcssc.org/api/v1';
  final String userToken;

  SntcsscLiveChatService({required this.userToken});

  Map<String, String> get _headers => {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
    'Authorization': 'Bearer $userToken',
  };

  // 1. Fetch Conversations
  Future<List<dynamic>> getConversations({String? type}) async {
    final uri = Uri.parse('$baseUrl/chat/conversations${type != null ? '?type=$type' : ''}');
    final res = await http.get(uri, headers: _headers);
    final body = jsonDecode(res.body);
    return body['data'] ?? [];
  }

  // 2. Send Message
  Future<Map<String, dynamic>> sendMessage(int conversationId, String text) async {
    final uri = Uri.parse('$baseUrl/chat/conversations/$conversationId/messages');
    final res = await http.post(uri, headers: _headers, body: jsonEncode({
      'body': text,
    }));
    return jsonDecode(res.body)['data'];
  }

  // 3. Initiate WebRTC Call
  Future<Map<String, dynamic>> initiateCall(int conversationId, String callType) async {
    final uri = Uri.parse('$baseUrl/chat/calls/initiate');
    final res = await http.post(uri, headers: _headers, body: jsonEncode({
      'conversation_id': conversationId,
      'call_type': callType,
    }));
    return jsonDecode(res.body)['data'];
  }

  // 4. Send WebRTC Signal
  Future<void> sendSignal(String callUuid, String signalType, Map<String, dynamic> payload, {int? targetUserId}) async {
    final uri = Uri.parse('$baseUrl/chat/calls/$callUuid/signal');
    await http.post(uri, headers: _headers, body: jsonEncode({
      'signal_type': signalType,
      'payload': payload,
      'target_user_id': targetUserId,
    }));
  }

  // 5. Join Virtual Classroom Meeting
  Future<Map<String, dynamic>> joinMeeting(String meetingUuid, {String? passcode}) async {
    final uri = Uri.parse('$baseUrl/chat/meetings/$meetingUuid/join');
    final res = await http.post(uri, headers: _headers, body: jsonEncode({
      'passcode': passcode,
    }));
    return jsonDecode(res.body)['data'];
  }
}
```

---

### 6.2 React Native (JavaScript / TypeScript) Implementation

```typescript
import axios from 'axios';
import { RTCPeerConnection, RTCSessionDescription, RTCIceCandidate } from 'react-native-webrtc';

const API_BASE = 'https://sntcssc.org/api/v1';

export class SntcsscChatClient {
  private token: string;

  constructor(token: string) {
    this.token = token;
  }

  private get authHeaders() {
    return {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': `Bearer ${this.token}`,
    };
  }

  // Fetch Message History
  async fetchMessages(conversationId: number, beforeId?: number) {
    const url = `${API_BASE}/chat/conversations/${conversationId}/messages${beforeId ? `?before_id=${beforeId}` : ''}`;
    const { data } = await axios.get(url, { headers: this.authHeaders });
    return data.data;
  }

  // Send Message with Attachments
  async postMessage(conversationId: number, body: string, attachments?: File[]) {
    if (attachments && attachments.length > 0) {
      const formData = new FormData();
      formData.append('body', body);
      attachments.forEach((file) => formData.append('attachments[]', file as any));
      const { data } = await axios.post(`${API_BASE}/chat/conversations/${conversationId}/messages`, formData, {
        headers: { ...this.authHeaders, 'Content-Type': 'multipart/form-data' },
      });
      return data.data;
    }

    const { data } = await axios.post(`${API_BASE}/chat/conversations/${conversationId}/messages`, { body }, {
      headers: this.authHeaders,
    });
    return data.data;
  }

  // WebRTC Signal Exchange
  async transmitSignal(callUuid: string, signalType: 'offer' | 'answer' | 'candidate', payload: any, targetUserId?: number) {
    return await axios.post(`${API_BASE}/chat/calls/${callUuid}/signal`, {
      signal_type: signalType,
      payload,
      target_user_id: targetUserId,
    }, { headers: this.authHeaders });
  }

  // Online Meeting Sync
  async syncMeeting(meetingUuid: string, since = 0.0) {
    const { data } = await axios.get(`${API_BASE}/chat/meetings/${meetingUuid}/sync?since=${since}`, {
      headers: this.authHeaders,
    });
    return data.data;
  }
}
```

---

### 6.3 Electron / Desktop Client (Node.js) Implementation

```javascript
const axios = require('axios');

class SntcsscDesktopChatSDK {
  constructor(apiKey) {
    this.client = axios.create({
      baseURL: 'https://sntcssc.org/api/v1',
      headers: {
        'Accept': 'application/json',
        'X-API-Key': apiKey,
      },
    });
  }

  async getIceServers() {
    const res = await this.client.get('/chat/calls/ice-servers');
    return res.data.data.ice_servers;
  }

  async markConversationAsRead(convId) {
    return (await this.client.post(`/chat/conversations/${convId}/read`)).data;
  }

  async endCall(callUuid) {
    return (await this.client.post(`/chat/calls/${callUuid}/end`)).data;
  }
}

module.exports = SntcsscDesktopChatSDK;
```

---

## 7. Mobile Background Push Notifications (FCM / APNs)

For native mobile clients (Flutter / React Native / Swift / Kotlin) when the app is in the background or terminated:

1. **Incoming WebRTC Call Notification:**
   - Deliver high-priority VoIP / Data push via **Firebase Cloud Messaging (FCM)** or **Apple Push Notification Service (APNs)**.
   - Include payload:
   ```json
   {
     "type": "incoming_call",
     "call_uuid": "call-7b9e2a4d-1c3f-4e8a-9a0b-1c2d3e4f5a6b",
     "caller_name": "Prof. S. Sengupta",
     "call_type": "video"
   }
   ```
   - On Android: Trigger a full-screen Intent with default ringtone.
   - On iOS: Use CallKit framework to display native incoming call UI.

2. **New Chat Message Notification:**
   - Notification channel: `sntcssc_chat_channel`.
   - Payload:
   ```json
   {
     "type": "chat_message",
     "conversation_id": 4,
     "sender_name": "Ananya Roy",
     "body": "Sir has uploaded the Mains answer writing strategy PDF."
   }
   ```

---

## 8. Enterprise Production Checklist & Error Handling

1. **Soft Delete Compliance:** All messages, calls, conversations, and meetings implement Laravel `SoftDeletes`. When deleted, records remain safely auditable.
2. **Audit Logging:** Administrative actions (channel creation, broadcast dispatch, participant removal, meeting termination, call initiation) automatically write to the centralized `audit_logs` table via `AuditLogService`.
3. **Database Transactions:** Every multi-step mutation (creating a group with participants, message with attachments, meeting scheduling) is wrapped inside explicit `DB::transaction()` blocks.
4. **Error Handling Envelope:**
   Standard RFC-7807 compliant JSON envelopes are guaranteed across all 47 endpoints:
   ```json
   {
     "success": false,
     "error": {
       "code": "FORBIDDEN",
       "message": "You do not have permission to post messages in this conversation."
     }
   }
   ```
5. **Rate Limiting:** WebRTC signaling endpoints (`/signal`, `/sync`) enforce adaptive rate limiting (240 requests/minute) to mitigate flood attacks while ensuring seamless streaming.

