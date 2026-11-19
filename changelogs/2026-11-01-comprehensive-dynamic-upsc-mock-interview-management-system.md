# Changelog: Comprehensive Dynamic UPSC Mock Interview Management System

**Date:** 2026-11-01  
**Category:** UPSC Civil Services Examination Operations, Mock Interview Panels, Candidate Scheduling, Multi-Channel Communications & Student Portal  
**Scope:** Full-stack enterprise mock interview management system featuring 5-member interview boards (Chairperson + 4 Members), multi-session management, candidate sequence ordering, 25-30 minute time slot auto-generation, hybrid delivery (in-person offline venue & online Zoom/Google Meet/MS Teams/Webex), personalized invitation drafting with live preview and multi-channel dispatch (Email, SMS, WhatsApp, Telegram, In-App), interview recording vault with per-candidate download dispatch, student dashboard with confidential session appearance order view, RESTful API endpoints, RBAC permissions, and comprehensive automated test suite.

---

## 1. Overview & Business Context

In civil services preparation, the UPSC Personality Test (Interview) is the decisive final stage. To replicate the authentic Union Public Service Commission interview environment, the Satyendranath Tagore Civil Services Study Centre (SNTCSSC) organizes annual Mock Interview drives comprising 5 or more intensive interview sessions. Each session is presided over by a distinguished **5-member board** consisting of:
- **1 Board Chairperson** (e.g., Retired IAS/IPS/IFS Officer, Former UPSC Member, or Eminent Bureaucrat) who chairs the board.
- **4 Board Members** (Subject Experts, Senior Academics, Psychologists, and Domain Specialists).

Aspirants attend sessions either **in-person (offline)** at the administrative training campus or **remotely (online)** via platforms such as Zoom, Google Meet, Microsoft Teams, or Cisco Webex Meetings. Each candidate undergoes a 25 to 30-minute structured interview with dedicated preparation buffers.

The new **UPSC Mock Interview Management System** provides an end-to-end operational hub allowing administrators to:
1. Plan, configure, and manage annual mock interview sessions with date, time spans, offline venue room addresses, and guidelines.
2. Formulate 5-member board panels from an active master pool of board members, designating the Chairperson and panel members.
3. Enroll students batch-wise and course-wise, sequence their order of appearance, and automatically compute exact time slots based on configurable durations (25-30 min) and inter-candidate buffer intervals.
4. Draft, preview in real time, and dispatch personalized invitation communiqués across multiple channels (Email, SMS, WhatsApp, Telegram, and In-App notifications). Communiqués are dynamically personalized with distinct templates for Board Chairpersons, Board Members, and Candidates (customized for offline campus reporting or online meeting links/passwords).
5. Securely record and store individual interview video links (Google Drive, YouTube Unlisted, Zoom Cloud, Vimeo) or uploaded files, and dispatch download access to respective aspirants across communication channels.
6. Provide students with an intuitive, mobile-responsive **Student Mock Interview Dashboard** where they can monitor scheduled timings, review venue or meeting credentials, watch or download their recorded interview, and access a **Confidential Session Appearance Order View** showing their peers' sequence, timings, and mode of attendance while strictly protecting recording privacy (peers can never view or download each other's recordings).
7. Execute full CRUD operations, advanced dynamic searching, filtering, CSV candidate import, and export comprehensive official session timetables to PDF, Excel, and CSV.

---

## 2. Database Schema & Architecture

### Migration: `2026_11_01_060000_create_mock_interview_management_tables.php`

Five normalized database tables were designed and migrated with optimized indexes, foreign key relationships, soft deletes, and audit tracking:

```
+---------------------------------------+
|        mock_interview_sessions        |
+---------------------------------------+
       | 1                           | 1
       |                             |
       v *                           v *
+---------------------------------+  +-------------------------------+
|  mock_interview_session_members |  |   mock_interview_candidates   |
+---------------------------------+  +-------------------------------+
       ^ *                                           | 1
       |                                             |
+------------------------------+                     v *
|   interview_board_members    |        +-----------------------------------+
+------------------------------+        |   mock_interview_invitation_logs  |
                                        +-----------------------------------+
```

#### 1. `mock_interview_sessions` (Session Master Table)
- `id`: Auto-incrementing primary key.
- `session_code`: Unique reference identifier (`MIS-YYYY-####`).
- `title`: Session designation (e.g., `UPSC Mock Interview 2026 - Day 1 Session A`).
- `year`: Academic/recruitment cycle year (defaulting to current year).
- `session_number`: Sequential session index (1, 2, 3, etc.).
- `session_date`: Scheduled date of the interview board.
- `start_time`, `end_time`: Operational timetable window (e.g., `09:30:00` to `17:30:00`).
- `reporting_time`: Mandatory student check-in and briefing time (e.g., `09:00:00`).
- `default_interview_duration`: Standard candidate duration in minutes (25 to 30 minutes, default: 30).
- `buffer_minutes`: Break or transition time between sequential candidates (default: 5 minutes).
- `venue_name`, `venue_address`, `room_number`: Physical location coordinates for offline attendance.
- `instructions`: Comprehensive operational remarks, board instructions, and dress code advisories.
- `remarks`: Internal administrative notes.
- `status`: Lifecycle state (`draft`, `scheduled`, `in_progress`, `completed`, `cancelled`).
- `invitation_template_chairperson`, `invitation_template_member`, `invitation_template_candidate`: Session-specific template overrides with customizable placeholders.
- `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.
- Indexed on `[session_date, status]`, `year`, `session_number`, and `session_code`.

#### 2. `interview_board_members` (Master Panelist Directory)
- `id`: Auto-incrementing primary key.
- `user_id`: Optional foreign key linking to `users` for MIS login access.
- `member_code`: Unique panelist identifier (`PANEL-YYYY-###`).
- `name`, `email`, `phone`, `whatsapp_number`, `telegram_chat_id`: Multi-channel communication channels.
- `designation`: Current or retired official designation (e.g., `Former Additional Chief Secretary, IAS (Retd.)`).
- `organization`: Service, cadre, or institutional affiliation.
- `specialization`: Domain expertise (e.g., `Public Administration & International Relations`).
- `bio`: Panelist profile and interview dossier.
- `avatar`: Profile picture file path.
- `is_active`: Status flag.
- `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.

#### 3. `mock_interview_session_members` (Session Board Composition)
- `id`: Auto-incrementing primary key.
- `mock_interview_session_id`: Foreign key with cascade deletion.
- `interview_board_member_id`: Foreign key referencing master directory.
- `role`: Board role designation (`chairperson` vs `member`). Enforces 1 Chairperson per board.
- `order_index`: Display sequence in board listing (1 for Chair, 2 to 5 for Members).
- `attendance_status`: Panelist presence status (`confirmed`, `pending`, `declined`, `attended`, `absent`).
- `invitation_sent_at`, `invitation_channels`: Array of dispatched invitation delivery logs.
- `invitation_notes`: Notes regarding panelist availability.
- `timestamps`. Unique constraint on `[mock_interview_session_id, interview_board_member_id]`.

#### 4. `mock_interview_candidates` (Candidate Allotment & Scheduling)
- `id`: Auto-incrementing primary key.
- `mock_interview_session_id`: Foreign key with cascade deletion.
- `user_id`: Foreign key linking to the enrolled student user account.
- `batch_id`, `course_id`: Academic cohort context.
- `sequence_order`: Integer appearance queue number (1, 2, 3...).
- `scheduled_start_time`, `scheduled_end_time`: Exact allotted interview window.
- `duration_minutes`: Duration in minutes (25 to 30 mins).
- `attending_mode`: `offline` (in-person at campus) or `online` (video conference).
- `platform`: `Zoom`, `Google Meet`, `Microsoft Teams`, `Cisco Webex Meetings`, `Others`.
- `meeting_link`, `meeting_id`, `meeting_password`, `platform_other`: Online video conference credentials.
- `recording_url`: Cloud interview video stream/download link.
- `recording_file_path`: Internal storage upload location.
- `recording_notes`: Panel observations or timestamp indices.
- `recording_sent_at`, `recording_channels`: Dispatch timestamps and channels.
- `candidate_status`: `scheduled`, `checked_in`, `in_interview`, `completed`, `absent`, `rescheduled`.
- `score`, `board_feedback`: Evaluation mark (e.g., out of 275) and confidential board review.
- `invitation_sent_at`, `invitation_channels`: Candidate invitation dispatch logs.
- `created_by`, `updated_by`, `deleted_by`, `timestamps`, `softDeletes`.
- Indexed on `[mock_interview_session_id, sequence_order]`, `[user_id]`, `[batch_id, course_id]`, and `attending_mode`.

#### 5. `mock_interview_invitation_logs` (Audit Log of Dispatched Communiqués)
- `id`: Auto-incrementing primary key.
- `mock_interview_session_id`: Foreign key reference.
- `recipient_type`: `candidate` or `board_member`.
- `mock_interview_candidate_id`, `mock_interview_session_member_id`: Polymorphic references.
- `recipient_name`, `recipient_identifier`: Contact coordinates (email address, phone number).
- `channel`: `email`, `sms`, `whatsapp`, `telegram`, `in_app`.
- `invitation_type`: `invitation`, `reminder`, `recording_ready`, `schedule_update`, `cancellation`.
- `subject`, `message_content`: Complete payload record.
- `status`: `pending`, `sent`, `failed`, `delivered`.
- `error_message`: Dispatch error log if failed.
- `sent_by`: User ID of the staff member who initiated dispatch.
- `timestamps`.

---

## 3. Eloquent Models & Service Architecture

### Eloquent Models (`app/Models/`)

- **`MockInterviewSession`**:
  - Implements `Auditable`, `HasFactory`, and `SoftDeletes`.
  - Automatic `session_code` generation (`MIS-YYYY-####`).
  - Scopes: `scopeSearch()`, `scopeUpcoming()`, `scopeScheduled()`, `scopeForYear()`.
  - Relationships: `members()` (`hasMany MockInterviewSessionMember`), `boardMembers()` (`belongsToMany InterviewBoardMember`), `chairperson()` (`hasOneThrough / hasOne where role = chairperson`), `candidates()` (`hasMany MockInterviewCandidate`), `invitationLogs()`.
  - Accessors: `formatted_date`, `formatted_time_range`, `status_badge_color`, `duration_text`.

- **`InterviewBoardMember`**:
  - Implements `Auditable`, `HasFactory`, and `SoftDeletes`.
  - Automatic `member_code` generation (`PANEL-YYYY-###`).
  - Scopes: `scopeActive()`, `scopeSearch()`.
  - Accessors: `initials`, `avatar_url`.
  - Relationships: `user()` (`belongsTo User`), `sessionMembers()` (`hasMany MockInterviewSessionMember`), `sessions()` (`belongsToMany MockInterviewSession`).

- **`MockInterviewSessionMember`**:
  - Constants: `ROLE_CHAIRPERSON = 'chairperson'`, `ROLE_MEMBER = 'member'`.
  - Relationships: `session()`, `boardMember()`, `invitationLogs()`.

- **`MockInterviewCandidate`**:
  - Implements `Auditable`, `HasFactory`, and `SoftDeletes`.
  - Constants: `MODE_OFFLINE = 'offline'`, `MODE_ONLINE = 'online'`.
  - Relationships: `session()`, `user()`, `batch()`, `course()`, `invitationLogs()`.
  - Accessors & Helpers: `time_slot_formatted`, `has_recording`, `effective_recording_url`.

- **`MockInterviewInvitationLog`**:
  - Audit trail model logging every notification with channel, timestamp, payload, and delivery status.

- **`User` Extended**:
  - Added relationships `mockInterviewCandidates()` and `interviewBoardMember()`.

---

## 4. Service Layer: `MockInterviewService`

Located at `app/Services/MockInterviewService.php`, this service encapsulates core business logic:

1. **Auto Time-Slot Generator (`autoGenerateTimeSlots`)**:
   - Calculates candidate schedule intervals starting from the session start time.
   - For each sequentially ordered candidate, calculates `scheduled_start_time = start_time + (index * (duration + buffer))` and `scheduled_end_time = scheduled_start_time + duration`.
   - Automatically commits updated slots within a database transaction.

2. **Personalized Template Engine & Live Preview**:
   - Dynamic token replacement supports:
     - `{candidate_name}`, `{session_title}`, `{session_number}`, `{session_date}`
     - `{start_time}`, `{end_time}`, `{reporting_time}`, `{duration_minutes}`, `{sequence_order}`
     - `{mode}`, `{venue_name}`, `{venue_address}`, `{room_number}`
     - `{platform}`, `{meeting_link}`, `{meeting_id}`, `{meeting_password}`
     - `{chairperson_name}`, `{board_members_list}`, `{recording_url}`, `{instructions}`
   - Differentiated templates:
     - **Board Chairperson:** Formal invitation acknowledging their prestigious role presiding over the interview board, summarizing session logistics, venue/reporting times, and the list of accompanying board members.
     - **Board Members:** Formal invitation inviting them as esteemed panelists, indicating the Board Chairperson presiding and session timing.
     - **Candidates:** Detailed notification specifying their individual sequence number, exact time slot, interview duration, reporting time, instructions, and mode-specific instructions (venue room details for offline or platform credentials for online).

3. **Multi-Channel Dispatch Engine**:
   - Seamlessly dispatches communiqués across:
     - **Email:** Dispatched via `EmailService::send()` with formatted HTML layouts.
     - **SMS:** Dispatched via `SmsService::send()`.
     - **WhatsApp:** Dispatched via `WhatsAppService::sendTextMessage()`.
     - **Telegram:** Dispatched via `TelegramService::sendMessage()`.
     - **In-App:** Dispatched via `NotificationService::send()`.
   - Records every communication in `mock_interview_invitation_logs`.

4. **Recording Vault & Secure Link Dispatch**:
   - Manages video link attachments and internal video uploads.
   - Generates personalized notifications with direct download/view URLs dispatched to candidates across selected channels.

---

## 5. UI/UX Design & Livewire Components

### 1. Admin Mock Interview Workspace
**File:** `resources/views/pages/admin/⚡mock-interviews.blade.php`  
**Route:** `admin.mock-interviews.index` (`/admin/mock-interviews`)  
**Features:**
- **Tabbed Dashboard:**
  - *Sessions View:* Cards and table view displaying all sessions with status badges, date, timings, board size, candidate count, search, year filter, status filter, and pagination.
  - *Session Detail Hub:* Active workspace for a selected session featuring:
    - 5-Member Board Panel Manager (designating Chairperson, managing 4 members, attendance tracking, and individual invitation buttons).
    - Candidate Timetable & Sequence Queue (drag/order indexing, mode badges, platform icons, exact time slots, status flags, and individual action menus).
    - 1-Click "Auto-Generate Slots" action calculating sequential 25-30 min intervals + buffer.
    - Live Invitation Hub with interactive live preview modal showing actual rendered message before dispatch.
    - Quick CSV Candidate Import modal supporting bulk student allocation.
    - PDF & CSV Timetable Export actions.
  - *Board Members Pool:* Directory of all available panelists with contact info, designations, organizations, and quick CRUD modal.
  - *Recordings Vault:* Unified review table for attaching cloud URLs or uploading video files, previewing media, and sending download links to candidates.
- **Modern Interactive Modals (`x-ui.modal`):**
  - Session Create/Edit Modal with comprehensive inputs, timepickers, venue addresses, and custom template tabs.
  - Board Member Assignment Modal with role selector (Chairperson vs Member) and order sequence.
  - Candidate Assignment Modal with student search, batch/course selector, mode toggle, online platform picker (Zoom, Teams, Meet, Webex), and meeting credentials.
  - Personalized Invitation Preview & Dispatch Modal featuring channel toggles (Email, SMS, WhatsApp, Telegram, In-App) and live parsed preview.
  - Recording Attachment & Dispatch Modal with link/file fields and instant channel dispatch.
  - Candidate Evaluation Modal for recording interview score and board feedback.

### 2. Student Mock Interview Portal
**File:** `resources/views/pages/student/⚡mock-interviews.blade.php`  
**Route:** `student.mock-interviews.index` (`/student/mock-interviews`)  
**Features:**
- Responsive interface showcasing upcoming sessions, past sessions, and available interview recordings.
- Detailed card view displaying session title, date, sequence number, exact time slot, duration, and reporting time.
- Mode-aware presentation:
  - *Offline:* Shows venue name, address, and room number.
  - *Online:* Shows platform badge, direct meeting link button, Meeting ID, and passcode.
- In-page HTML5 video player and external download link for available interview recordings.
- **Confidential Session Appearance Order Modal:**
  - Aspirants can open a modal to see the complete sequence of candidates scheduled for their session, their sequence number, attendance mode (Online/Offline), and time slots to stay aware of the interview progression.
  - **Privacy Enforcement:** Peer names, scores, feedback, and **all video recording links are strictly hidden**; students can only ever see their own video recording.

### 3. Official Timetable PDF View
**File:** `resources/views/exports/mock-interview-session-pdf.blade.php`  
- High-fidelity printable document featuring SNTCSSC official branding, session metadata, 5-member board constitution (highlighting Chairperson and members), candidate appearance sequence table with mode, exact time slot, roll number, and signature columns.

---

## 6. RESTful API Endpoints

**Controller:** `app/Http/Controllers/Api/MockInterviewApiController.php`  
**Base URL:** `/api/v1/mock-interviews`  
Authenticated via API Key or Sanctum bearer token.

| Method | Endpoint | Description |
|---|---|---|
| `GET` | `/api/v1/mock-interviews/sessions` | List sessions with search, year, and status filters |
| `POST` | `/api/v1/mock-interviews/sessions` | Create a new mock interview session |
| `GET` | `/api/v1/mock-interviews/sessions/{id}` | Retrieve session details with board and candidates |
| `PUT` | `/api/v1/mock-interviews/sessions/{id}` | Update session metadata and parameters |
| `DELETE` | `/api/v1/mock-interviews/sessions/{id}` | Soft delete a session |
| `POST` | `/api/v1/mock-interviews/sessions/{id}/members` | Add a board member (Chairperson/Member) to session |
| `DELETE` | `/api/v1/mock-interviews/sessions/{id}/members/{memberId}` | Remove a board member from session |
| `POST` | `/api/v1/mock-interviews/sessions/{id}/candidates` | Add a student candidate with mode & credentials |
| `DELETE` | `/api/v1/mock-interviews/sessions/{id}/candidates/{candId}` | Remove a candidate from session |
| `POST` | `/api/v1/mock-interviews/sessions/{id}/auto-generate-slots` | Automatically compute 25-30 min candidate time slots |
| `POST` | `/api/v1/mock-interviews/candidates/{id}/recording` | Attach recording URL/file and dispatch to candidate |
| `POST` | `/api/v1/mock-interviews/candidates/{id}/send-invitation` | Dispatch personalized candidate invitation |
| `POST` | `/api/v1/mock-interviews/members/{id}/send-invitation` | Dispatch personalized board member invitation |
| `GET` | `/api/v1/mock-interviews/board-members` | List master board member pool |
| `POST` | `/api/v1/mock-interviews/board-members` | Add new panelist to directory |
| `GET` | `/api/v1/student/mock-interviews` | Candidate's personal schedule and recordings |
| `GET` | `/api/v1/student/mock-interviews/sessions/{id}/order` | Candidate's view of session appearance order (confidential) |

---

## 7. Role-Based Access Control (RBAC) & Audit Trails

1. **Permissions Registered in `RbacService`:**
   - `mock_interviews.view` - View mock interview sessions and rosters
   - `mock_interviews.create` - Create new mock interview sessions
   - `mock_interviews.edit` - Edit session details, venues, and timings
   - `mock_interviews.delete` - Delete mock interview sessions
   - `mock_interviews.members` - Manage 5-member interview board composition
   - `mock_interviews.students` - Add, sequence, and manage candidate allocations
   - `mock_interviews.invite` - Draft, preview, and dispatch multi-channel invitations
   - `mock_interviews.recordings` - Attach, upload, and dispatch interview recordings
   - `mock_interviews.export` - Export timetables to PDF, Excel, and CSV
   - `mock_interviews.import` - Import candidate allocations from CSV/Excel
   - `mock_interviews.scores` - Evaluate candidates and record board scores/feedback

2. **Default Role Assignments:**
   - **Super Administrator:** All 11 permissions.
   - **Administrator:** All 11 permissions.
   - **Faculty:** `view`, `scores`, `recordings`.
   - **Staff:** `view`, `members`, `students`, `invite`, `export`.

3. **Audit Trails:**
   - Implemented `Auditable` trait on `MockInterviewSession`, `InterviewBoardMember`, and `MockInterviewCandidate`.
   - Tracked all actions (creation, updates, slot generations, invitations, recording dispatches) via `AuditLogService::log()` with user context, IP address, and payload snapshots.

---

## 8. Localization & Translations

Added bilingual localization keys to `lang/en.json` and `lang/bn.json`:
- `Mock Interviews` / `মক ইন্টারভিউ`
- `UPSC Mock Interview System` / `ইউপিএসসি মক ইন্টারভিউ সিস্টেম`
- `Interview Board Members` / `ইন্টারভিউ বোর্ডের সদস্যবৃন্দ`
- `Board Chairperson` / `বোর্ড সভাপতি`
- `Board Member` / `বোর্ড সদস্য`
- `Order of Appearance` / `উপস্থিতির ক্রম`
- `Interview Duration` / `ইন্টারভিউয়ের সময়কাল`
- `Interview Recordings` / `ইন্টারভিউ রেকর্ডিং`
- `Send Recording Link` / `রেকর্ডিং লিঙ্ক পাঠান`
- `Auto Generate Slots` / `স্বয়ংক্রিয় সময় নির্ধারণ`

---

## 9. Automated Testing & Verification

A dedicated Pest feature test suite was implemented in `tests/Feature/MockInterviewManagementTest.php`:
- **`test_can_create_mock_interview_session`**: Tests session creation, code generation, venue details, and default durations.
- **`test_can_setup_five_member_board_with_chairperson`**: Verifies configuration of 1 Chairperson and 4 Board Members.
- **`test_can_add_offline_and_online_candidates_to_session`**: Verifies student enrollment with offline venue and online platform credentials (Zoom/Teams).
- **`test_auto_generates_sequential_time_slots`**: Validates mathematical generation of 25-30 minute intervals plus buffer times.
- **`test_can_attach_and_dispatch_recording_to_candidate`**: Tests interview video attachment and multi-channel dispatch logging.
- **`test_student_can_view_own_interview_and_confidential_session_order`**: Asserts student can view their interview schedule and peer appearance order while confirming peer recording links are strictly hidden.
- **`test_admin_can_export_session_timetable_as_csv`**: Verifies streamed CSV timetable generation.
- **`test_api_endpoints_for_mock_interviews`**: Tests API authentication and endpoints for sessions and candidate appearance order.
- **`test_invitation_preview_renders_personalized_content`**: Validates placeholder substitution for Chairperson, Members, and Candidates.
- **`test_student_can_download_own_interview_video_recording_directly_and_cannot_download_peers_recording`**: Verifies direct video recording download for authorized students and enforces strict 403 authorization when attempting to download another candidate's video.
- **`test_admin_can_open_invitations_hub_and_dispatch_communiques_without_app_expression_error`**: Validates clean method resolution for invitation previews and multi-channel dispatch without client-side container circular JSON serialization errors.
- **`test_admin_can_import_mock_interview_sessions_and_board_members_from_csv`**: Asserts bulk CSV import of session schedules and board panelists into the database with audit trails.
- **`test_admin_can_upload_and_remove_board_member_avatar_and_candidate_recording_with_renames_uploads_trait`**: Tests file renaming through the `RenamesUploads` trait, storage location verification, and the deletion of uploaded avatars and video files upon activating remove options.

**Test Results:**
- `MockInterviewManagementTest`: **16 passed, 95 assertions (100% pass)**.
- `UserManagementTest` (Regression check): **16 passed, 92 assertions**.
- Code styling checked and verified via `vendor/bin/pint --format agent`.

---

## 10. Bug Fixes & Enhancement Additions (Post-Implementation)

1. **Resolution of Livewire Circular JSON / `app` Method Error:**
   - **Root Cause:** In the Blade templates, `wire:click` and `wire:submit` expressions contained `app('App\\Services\\MockInterviewService')`. When parsed by the browser Alpine/Livewire runtime, the client attempted to serialize the global `window.app` object into JSON, triggering `Livewire Expression Error: Converting circular structure to JSON` and a server error `Unable to call component method. Public method [app] not found on component`.
   - **Fix:** Removed all client-side service invocations from `wire:click` and `wire:submit` attributes. Created a clean server-side method `openInvitationsForSession(int $sessionId)`, and resolved `MockInterviewService` dependencies directly inside the component actions via `app(MockInterviewService::class)`.

2. **Session and Board Member Bulk Import Functionality:**
   - Implemented `importSessions()` with downloadable CSV format template (`downloadSessionImportTemplate()`) and dedicated interactive modal `import-sessions-modal`.
   - Implemented `importBoardMembers()` with downloadable CSV format template (`downloadBoardMemberImportTemplate()`) and dedicated interactive modal `import-board-members-modal`.
   - Added matching Export Panelists functionality (`exportBoardMembers`) for CSV, Excel, and PDF formats.

3. **`RenamesUploads` Trait & File Removal Integration:**
   - Added `RenamesUploads` trait to standardize file naming across all mock interview uploads:
     - Board member avatars: `panel_member_avatar_user_{id}_{timestamp}.{ext}` in `mock-interviews/avatars/`.
     - Candidate video recordings: `interview_recording_user_{id}_{timestamp}.{ext}` in `mock-interviews/recordings/`.
   - Added interactive preview and file removal buttons (`removeAvatar` and `removeRecordingFile`) in edit modals with automatic deletion of physical files from `Storage::disk('public')` when files are removed or replaced.

4. **Student Direct Recording Download Capability:**
   - Added `downloadRecording(int $candidateId)` to `pages::student.mock-interviews`.
   - Provided a 1-click "Download Video Recording" button directly inside the "My Interview Video Recording" modal player and footer.
   - Enforced strict authorization: only the authenticated candidate can stream or download their recording; attempts by other students are blocked and logged.

---

## 11. Personalized Invitation Template Editor & Global Upload Cancellation

### 11.1 Invitation Templates Customization (Chairman, Board Member, Student)
- **Role-Differentiated Template Architecture:**
  - Distinct template customization and storage per role:
    - **♔ Chairman of the Board**: Stored in `mock_interview_sessions.chairman_invitation_template`.
    - **👥 Board Panelist Member**: Stored in `mock_interview_sessions.member_invitation_template`.
    - **🎓 Attending Aspirant Student**: Stored in `mock_interview_sessions.student_invitation_template`.
- **Interactive Template Editor within Invitations Hub Modal (`invitation-hub-modal`):**
  - Added dual-tab interface toggling between **`[Live Preview]`** and **`[Edit Template]`**.
  - Dynamic role switcher: Allows switching between Chairman, Board Panelist, and Student views on the fly.
  - Real-time debounced textarea (`wire:model.live.debounce.300ms="activeTemplateText"`) that instantly re-renders the live preview with dynamic placeholder replacements.
  - **Clickable Variable Tokens Pill Palette:** Admins can click any dynamic placeholder badge (`{name}`, `{session_no}`, `{session_date}`, `{attending_mode}`, `{slot_time}`, `{venue_address}`, `{chairperson_name}`, etc.) to automatically insert the variable token into the template textarea at the current cursor position.
  - **Save for this Session (`saveSessionInvitationTemplate`):** Persists the edited template to the specific session record in the database, with full audit trail logging (`mock_interview_template_saved`).
  - **Reset to Default (`resetInvitationTemplateToDefault`):** Clears the session template override and immediately reverts back to the standard system default from `MockInterviewService`.
- **Session Create/Edit Modal Integration (`session-form-modal`):**
  - Added collapsible "Personalized Invitation Templates (Optional Overrides)" section with tabs for Chairperson, Panelist Member, and Student templates.
  - Integrated "Load Default" buttons for each template type to pre-fill standard templates for easy editing prior to session creation.
- **Dispatch Integrity:**
  - `dispatchInvitations()` ensures each recipient receives their designated custom or default template without cross-role pollution.

### 11.2 Global File Upload Progress Indicator with Cancel Capability
- **Component & Property Tracking:**
  - Updated the global upload progress listener in `resources/views/layouts/app.blade.php`:
    - Captures `componentId = $event.detail.id; property = $event.detail.property;` on `livewire-upload-start.window`.
    - Resets on `livewire-upload-finish.window`, `livewire-upload-error.window`, and `livewire-upload-cancel.window`.
- **Livewire 3 Request Cancellation:**
  - Implemented Alpine.js `cancel()` method that locates the active Livewire component via `window.Livewire.find(this.componentId)` and triggers Livewire's built-in `$cancelUpload(this.property)` method to abort the active XMLHttpRequest/fetch upload.
  - Integrated a dedicated `<button type="button" x-on:click="cancel()"><x-icon name="x"/></button>` cancel icon button inside the floating progress indicator with hover state and tooltip, allowing users to safely abort any in-flight file upload (candidate recordings, session CSVs, member CSVs, photos) at any time.

### 11.3 Aspirant Detailed Information Modal & New Tab Access in Interview Timetable
- **Timetable Row Interaction:**
  - In the **Attending Aspirants & Interview Timetable** table list:
    - **Clickable Candidate Name:** Clicking candidate name triggers `viewCandidateDetails(id)` to open the interactive dossier modal.
    - **Check Details Icon Button:** Added `<button type="button" wire:click="viewCandidateDetails(id)"><x-icon name="user"/></button>` right next to the candidate name and in the table's Actions column.
    - **Direct "Open in New Tab" Link:** Added `<a href="...admin.students.index?search=..." target="_blank"><x-icon name="external-link"/></a>` directly beside the student details and in the Actions column.
- **Interactive Candidate Details Modal (`candidate-details-modal`):**
  - **Banner:** Full name, Student ID, Category, Optional subject badge, attendance mode, status badge, and an *"Open in New Tab"* button.
  - **Tab 1 (Mock Interview & Timetable):** Appearance sequence order (`#1`), scheduled start and end times, 20-min reporting time, offline venue address & room number or online platform join link & passcode, candidate-specific instructions, panel evaluation score (/275 marks) & remarks, interview video recording link.
  - **Tab 2 (UPSC & DAF Profile):** UPSC roll number, UPSC Optional Subject, medium of examination, attempts given and remaining, Detailed Application Form (DAF) extracurricular activities and hobbies, historical UPSC CSE attempt records table.
  - **Tab 3 (Personal & Addresses):** Father's name, mother's name, DOB, gender, category, PwBD status, occupation, permanent address, and correspondence address.
  - **Tab 4 (Academics & Enrollments):** Highest education level, degrees/qualifications table with percentages/CGPA and passing years, active course and batch enrollments at SNTCSSC.
  - **Footer:** Direct link to open the student's full profile in a new tab alongside modal close button.

### 11.4 Multi-Dimensional Report Generation & Multi-Format Exports (PDF, Excel, CSV)
- **Multi-Dimensional Perspectives Engine:**
  - Implemented comprehensive reporting engine in `resources/views/pages/admin/⚡mock-interviews.blade.php` via `buildReportData()` and `exportReport()`:
    1. **🎓 Student Wise Report:** Comprehensive aspirant performance register tracking Student ID, Candidate Name, UPSC Optional Subject, Reservation Category, Session Code & Date, Interview Slot & Sequence Order, Attending Mode (In-Person Offline vs. Remote Online), Status, Evaluation Marks (/275), Board Feedback Remarks, and Video Recording Status.
    2. **👥 Board Member Wise Report:** Panelist deployment analysis tracking Panelist Name, Official Designation & Organization, Specialization, Role in Board (Chairperson vs. Board Panelist), Session Code, Date & Venue/Chamber, Total Candidates Allotted, Number Evaluated, Average Marks Given (/275), Highest Score Awarded, and Lowest Score Awarded.
    3. **📅 Year Wise & Session Overview Report:** Macro-level annual analysis across academic cycles tracking Academic Year/Cycle, Session Code, Session Title, Date & Hours, Chairperson, Panel Size, Enrolled Candidates, Mode Breakdown (In-Person vs. Remote), Completed Interviews, Evaluated Candidates, Overall Average Marks (/275), Recordings Available, and Session Lifecycle Status.
- **Dynamic Live Scope Filter Matrix:**
  - Real-time reactive filters with live preview updating as filters change:
    - **Academic Year / Cycle:** Dynamic list of existing academic years + current/previous years.
    - **Session Filter:** Scoped to the selected academic cycle.
    - **Attending Mode Filter:** All Modes, In-Person (Offline), Remote (Online).
    - **Evaluation Status Filter:** All Aspirants, Evaluated Only, Pending Marks.
    - **Attendance Status Filter:** Scheduled, Completed, In Progress, Absent, Cancelled.
    - **Board Panelist Selector:** Filter report metrics specific to a single board expert.
    - **Date Range Filters:** Start Date (`reportStartDate`) and End Date (`reportEndDate`).
    - **Active Scope Subtitle & Record Counter:** Displays exact active filter combination string and matched record count.
    - **One-Click Reset Filters (`resetReportFilters`):** Clears all filter state variables back to default view.
- **Live Summary KPI Metric Cards:**
  - Automatically calculates perspective-specific aggregates:
    - *Student Wise:* Total Candidates, In-Person Offline count, Remote Online count, Total Evaluated, and Average Marks out of 275.
    - *Board Member Wise:* Total Deployments, Unique Panelists, Sessions Chaired, and Total Candidates Evaluated.
    - *Year Wise:* Total Sessions, Total Candidates Enrolled, Completed Interviews, Overall Average Score (/275), and Video Recordings Vault count.
- **Live Sample Data Preview:**
  - Responsive preview table rendered directly inside `<x-ui.modal name="mock-interview-reports-modal" maxWidth="max-w-6xl">` showing up to 8 live records with total record count and notification when full dataset is available via export.
- **Multi-Format Export Pipelines:**
  - **Formatted Landscape PDF:** Rendered via `Barryvdh\DomPDF\Facade\Pdf` using dedicated template `resources/views/exports/mock-interview-report-pdf.blade.php`. Includes official SNTCSSC Govt. of West Bengal letterhead, report metadata, dynamic filter criteria box, KPI stat cards, and alternating styled table rows with role and status badges.
  - **Microsoft Excel (.xlsx):** Streamed export via `App\Support\Export\TableExporter::downloadArray` and `App\Exports\GenericArrayExport`.
  - **Comma-Separated Values (.csv):** Direct UTF-8 BOM-encoded CSV download with column headers for full Microsoft Excel and database compatibility.
- **UI Access Points:**
  - Added dedicated **"Reports & Analytics"** button (`openReportsModal()`) in the top admin toolbar.
  - Added **"Session Report"** button in each session row in the sessions data table.
  - Added **"Reports"** button in the session detail header bar, pre-filtering the reports modal to that session.
- **Audit Logging & Automated Pest Testing:**
  - Dispatches `mock_interview_report_exported` event with format and report type in `AuditLogService`.
  - Comprehensive feature tests added in `tests/Feature/MockInterviewManagementTest.php` testing modal opening, dynamic filter matrix, report data generation, and PDF, Excel, and CSV downloads (19 passing tests, 119 assertions).

### 11.5 Fixes: Database Column Alignment & PDF Empty Pages Elimination
- **Resolved Unknown Column `display_order` (Error 1054):**
  - In `resources/views/pages/admin/⚡mock-interviews.blade.php` inside `buildReportData()` for `board_member_wise`, updated `->orderBy('display_order', 'asc')` to reference the correct schema column `order_index` defined on `mock_interview_session_members`.
  - Updated test cases in `tests/Feature/MockInterviewManagementTest.php` to use `'order_index' => 1`.
- **Eliminated PDF Empty Pages & Layout Overflow in DomPDF:**
  - **Fixed Footer Flow:** Relocated the `.footer` container to the top of `<body>` and changed its inner structure from a floating `div` to a clean borderless `<table>` (`bottom: -7mm`), preventing DomPDF from treating footer overflow as trailing body content that created phantom page breaks.
  - **Fixed Table Layout & Sizing:** Added `table-layout: fixed; width: 100%;` and `page-break-inside: avoid;` to `table.data-table tr`.
  - **Dynamic Heading Widths:** Introduced dynamic `$colWidths` mapping tailored for each report perspective (`student_wise`, `board_member_wise`, `year_wise`) so wide tables never overflow the A4 landscape printable boundary (~785pt).
  - **Stats Grid Restructuring:** Converted KPI stat cards into a borderless single-tier `.stats-table` with table-cell sizing (`width: {{ 100 / count($stats) }}%`), preventing block reflow pagination artifacts.
  - **Results:** PDFs render with zero blank or orphan pages, fitting concise datasets onto 1 page and smoothly flowing multi-record data across consecutive pages.

---

## 12. RESTful API Architecture, Developer Console & Docs Integration, and Multi-Platform SDK Support

### 12.1 Enterprise RESTful API Catalog (27 Endpoints)
Implemented a complete, production-ready RESTful API in `app/Http/Controllers/Api/MockInterviewApiController.php` registered in `routes/api.php` under `api/v1/mock-interviews` and `api/v1/student/mock-interviews`:

| Method | Endpoint | Scope | Description |
|---|---|---|---|
| `GET` | `/api/v1/mock-interviews/dashboard` | `read:mock_interviews` | Real-time executive dashboard telemetry and operational overview |
| `GET` | `/api/v1/mock-interviews/sessions` | `read:mock_interviews` | List & filter interview sessions with pagination |
| `POST` | `/api/v1/mock-interviews/sessions` | `manage:mock_interviews` | Create interview session with date, timing, and venue |
| `GET` | `/api/v1/mock-interviews/sessions/{id}` | `read:mock_interviews` | Detailed session dossier, panel members, & candidates |
| `PUT` | `/api/v1/mock-interviews/sessions/{id}` | `manage:mock_interviews` | Update session attributes, timing, venue, or status |
| `DELETE` | `/api/v1/mock-interviews/sessions/{id}` | `manage:mock_interviews` | Soft delete mock interview session |
| `POST` | `/api/v1/mock-interviews/sessions/{id}/members` | `manage:mock_interviews` | Assign expert panelist / Chairman to interview session |
| `DELETE` | `/api/v1/mock-interviews/sessions/{id}/members/{id}` | `manage:mock_interviews` | Remove board panelist appointment from session |
| `POST` | `/api/v1/mock-interviews/sessions/{id}/candidates` | `manage:mock_interviews` | Allocate aspirant to session with mode & timing |
| `GET` | `/api/v1/mock-interviews/candidates/{id}` | `read:mock_interviews` | Detailed candidate interview dossier & timetable slot |
| `PUT` | `/api/v1/mock-interviews/candidates/{id}` | `manage:mock_interviews` | Update slot timing, mode (online/offline), or virtual links |
| `DELETE` | `/api/v1/mock-interviews/candidates/{id}` | `manage:mock_interviews` | Remove aspirant from interview session |
| `POST` | `/api/v1/mock-interviews/candidates/{id}/evaluate` | `write:mock_interviews` | Record panel scores (marks out of 275) and feedback |
| `PUT` | `/api/v1/mock-interviews/candidates/{id}/recording` | `manage:mock_interviews` | Attach cloud recording URL (Zoom/Drive) & password |
| `POST` | `/api/v1/mock-interviews/candidates/{id}/dispatch-recording` | `manage:mock_interviews` | Dispatch recording link to student via Email/SMS/WhatsApp |
| `POST` | `/api/v1/mock-interviews/sessions/{id}/auto-slots` | `manage:mock_interviews` | Auto-generate sequential time slots for candidates |
| `POST` | `/api/v1/mock-interviews/sessions/{id}/invite` | `manage:mock_interviews` | Send personalized invitations to Chairman, Panel, or Students |
| `GET` | `/api/v1/mock-interviews/sessions/{id}/templates` | `read:mock_interviews` | Retrieve personalized invitation templates & variables |
| `PUT` | `/api/v1/mock-interviews/sessions/{id}/templates` | `manage:mock_interviews` | Update invitation templates for Chairman, Panelists, & Students |
| `GET` | `/api/v1/mock-interviews/board-members` | `read:mock_interviews` | List & search master directory of board panelists |
| `POST` | `/api/v1/mock-interviews/board-members` | `manage:mock_interviews` | Add external dignitary/expert to board pool |
| `GET` | `/api/v1/mock-interviews/board-members/{id}` | `read:mock_interviews` | Show panelist profile and historical session deployment |
| `PUT` | `/api/v1/mock-interviews/board-members/{id}` | `manage:mock_interviews` | Update panelist designation, organization, or contact details |
| `DELETE` | `/api/v1/mock-interviews/board-members/{id}` | `manage:mock_interviews` | Soft delete board panelist from master pool |
| `GET` | `/api/v1/mock-interviews/reports` | `read:mock_interviews` | Dynamic reporting JSON (`student_wise`, `board_member_wise`, `year_wise`) |
| `GET` | `/api/v1/mock-interviews/analytics` | `read:mock_interviews` | Macro-level executive telemetry, turnout rate, score distribution |
| `GET` | `/api/v1/student/mock-interviews` | `read:mock_interviews` | Student portal/mobile endpoint with order of appearance |

### 12.2 Fine-Grained API Scopes (`ApiKeyService`)
Registered four dedicated scopes under the new **"UPSC Mock Interview Management"** category in `app/Services/ApiKey/ApiKeyService.php`:
- `read:mock_interviews`: View mock interview sessions, board panelists, student interview rosters, timetable order, reporting registers, and analytics metrics.
- `write:mock_interviews`: Record panel evaluation scores (/275 marks), candidate qualitative feedback, and update aspirant timetable preferences.
- `manage:mock_interviews`: Full administrative operations (create/update/delete sessions, assign board members and aspirants, calculate auto-slots, attach video recordings, and dispatch multi-channel invitations).
- `download:mock_interviews`: Stream and download mock interview reports, timetables, and candidate video recordings.

### 12.3 Peer Video Recording Privacy Guard
In `studentSessions()` (`GET /api/v1/student/mock-interviews`):
- Candidates can inspect the full session roster and order of appearance so they know their turn in the interview queue and timetable.
- **Privacy Enforcement:** Video recording URLs and passwords (`recording_url`, `recording_file`, `recording_password`) are **strictly nullified** for all other peer candidates, ensuring complete confidentiality between candidates.

### 12.4 Interactive Developer Console & Docs Integration (`⚡docs.blade.php`)
- **Direct Guide Rendering:** Added `'mock_interviews'` to `openGuide()`, which reads `docs/integrations/mock-interview-management-api.md` and renders it directly inside the interactive console modal (`<x-ui.modal name="interactive-guide-modal">`).
- **Endpoints Catalog Registration:** Registered all 26 endpoints under category `'mock_interviews'` with corresponding HTTP methods, paths, required scopes, and enterprise descriptions.
- **Filter Preset Pill:** Added `[Mock Interviews]` filter pill under `Filter Presets:` in the sandbox UI.
- **Ready-To-Use Sandbox Presets:** Added quick execution buttons with pre-filled mock JSON payloads for sessions, candidates, evaluation, video recording, templates, and student endpoints.
- **Documentation Card:** Added a dedicated "UPSC Mock Interview Management Integration Guide" card in the developer portal documentation grid.

### 12.5 Multi-Platform Integration Guide (`docs/integrations/mock-interview-management-api.md`)
Created a comprehensive, 10-chapter markdown integration manual:
1. **Executive Architecture & Visual Workflows:** Mermaid diagrams for Mobile, Web, Desktop, and Recording Studios.
2. **Authentication & Security:** `X-API-KEY` header, Bearer token, and fine-grained scope authorization.
3. **Complete RESTful Endpoint Catalog:** Request/response JSON schemas for all 26 endpoints.
4. **Peer Recording Privacy Enforcement:** Architectural specifications for candidate data protection.
5. **Cross-Platform Implementation Recipes:** Complete copy-pasteable SDK code examples in:
   - **Flutter (Dart):** Service class for mobile apps with slot listing and video playback.
   - **React Native (TypeScript):** Component for mobile student schedule and recording view.
   - **Next.js / React (TypeScript):** Live board panelist evaluation form with instant autosave.
   - **Python (FastAPI / Automation):** Recording studio ingest worker for automatic video attachment.
6. **RFC 7807 Standard Error Codes & Handling:** Standard error formats and recovery workflows.
7. **Production Deployment & Security Checklist:** Best practices for webhooks, rate limiting, and token rotation.

### 12.6 Automated Verification & Test Results
- Implemented `tests/Feature/MockInterviewApiTest.php` with 8 comprehensive tests (101 assertions):
  - Developer console guide modal and sandbox preset verification.
  - API key authentication and scope enforcement (401 on missing key, 403 on insufficient scope).
  - Sessions CRUD with audit logging and soft deletes.
  - Board members master pool and session assignments.
  - Candidate timetable updates, evaluation scoring (/275), and recording dispatches.
  - Auto-slots generation and personalized invitation templates.
  - Multi-dimensional reports (`student_wise`, `board_member_wise`, `year_wise`) and macro analytics.
  - Peer candidate video recording privacy isolation.
- **Combined Test Suite Result:** `tests/Feature/MockInterviewManagementTest.php` + `tests/Feature/MockInterviewApiTest.php`:
  - **27 passed, 236 assertions (100% pass, 0 failures)**.
- **Resolved Column Not Found on Analytics Endpoint (`recording_file` vs `recording_file_path`):** Corrected column query in `MockInterviewApiController::analytics()` to reference the schema column `recording_file_path` on the `mock_interview_candidates` table.
- Formatted all modified PHP files with Laravel Pint (`vendor/bin/pint --format agent`).




