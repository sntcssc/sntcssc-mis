# Communications Hub: Personalisation, Delivery Logs & Monitoring, Individual/Bulk Messaging & Drafts

This plan details the full implementation of:
1. **User Personalisation** across all Email and SMS dispatches (including OTPs, alerts, notifications, and custom messages) using dynamic `{name}` and related recipient variables.
2. **Enterprise Communications Delivery Monitoring Dashboard**:
   - Track every outgoing SMS and Email with delivery status (`sent`, `delivered`, `failed`), recipient name, address/phone, channel, timestamp, rendered content, variables, and failure reason.
   - One-click **Resend** button (individual & bulk retry) with live status updates.
   - Soft deletes, search, filters by status/channel/date, and detail view modal.
3. **Bulk & Individual Message Composer with Saved Drafts**:
   - Compose & send Email and SMS to individual users or bulk target groups (by role, custom selection, or all users).
   - Dynamic tag injection (`{name}`, `{email}`, `{phone}`, `{app_name}`, `{date}`, etc.) personalised per recipient during dispatch.
   - Saved Drafts management (create, edit, duplicate, send, delete).
   - Template selection and real-time live preview.
4. **Enterprise Architecture & Standards**:
   - `SoftDeletes`, `DB::transaction`, `try/catch` error resilience, `AuditLogService` integration.
   - Comprehensive Pest tests covering all features.
   - Changelog entry under `changelogs/`.

---

## User Review Required

> [!IMPORTANT]
> **Database Changes**: We will create two new tables via Laravel migrations:
> 1. `communication_logs`: Tracks every outbound email & SMS with delivery status, failure reasons, rendered contents, recipient user metadata, and resend history.
> 2. `communication_campaigns`: Stores individual & bulk message campaigns, templates used, audience filters, and saved drafts.

> [!NOTE]
> All existing SMS and Email services (`SmsService`, `EmailService`, `OtpService`) will automatically record delivery logs and handle name personalisation gracefully even for guest/unregistered phone numbers or emails.

---

## Proposed Changes

### Database & Models

#### [NEW] [create_communication_logs_table.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_24_000001_create_communication_logs_table.php)
- Columns:
  - `id`
  - `channel` (`email`, `sms`)
  - `type` (`otp`, `notification`, `notice`, `broadcast`, `custom_individual`, `custom_bulk`)
  - `recipient` (email or phone)
  - `recipient_name` (nullable string)
  - `user_id` (nullable foreignId to `users`)
  - `template_code` (nullable string)
  - `subject` (nullable string)
  - `content` (longText - rendered body)
  - `variables` (json nullable)
  - `metadata` (json nullable - headers, gateway response, driver, IP)
  - `status` (`sent`, `delivered`, `failed`, `pending`, `draft`)
  - `error_message` (text nullable)
  - `sent_by` (nullable foreignId to `users`)
  - `campaign_id` (nullable foreignId)
  - `sent_at`, `delivered_at`, `failed_at` (timestamps)
  - `resend_count` (int default 0)
  - `last_resent_at` (timestamp nullable)
  - `deleted_at` (soft deletes), `created_at`, `updated_at`

#### [NEW] [create_communication_campaigns_table.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_24_000002_create_communication_campaigns_table.php)
- Columns:
  - `id`
  - `title` (string)
  - `channel` (`email`, `sms`, `both`)
  - `recipient_type` (`individual`, `role`, `all_users`, `custom_list`)
  - `recipient_role` (nullable string)
  - `recipient_ids` (json nullable)
  - `template_code` (nullable string)
  - `subject` (nullable string)
  - `content` (text)
  - `status` (`draft`, `scheduled`, `processing`, `completed`, `failed`, `cancelled`)
  - `total_recipients` (int default 0)
  - `sent_count` (int default 0)
  - `failed_count` (int default 0)
  - `scheduled_at`, `sent_at` (timestamps)
  - `created_by` (foreignId to `users`), `updated_by`
  - `deleted_at` (soft deletes), `created_at`, `updated_at`

#### [NEW] [CommunicationLog.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/CommunicationLog.php)
- Eloquent Model with `SoftDeletes`, `Auditable`, casts for JSON, relationships: `user()`, `sender()`, `campaign()`.
- Scopes for `sms()`, `email()`, `failed()`, `delivered()`, `sent()`.

#### [NEW] [CommunicationCampaign.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/CommunicationCampaign.php)
- Eloquent Model with `SoftDeletes`, `Auditable`, casts for JSON, relationships: `creator()`, `logs()`.
- Scopes for `drafts()`, `completed()`.

---

### Backend Services & Personalisation

#### [NEW] [CommunicationService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/CommunicationService.php)
- Central orchestrator for communications logging, bulk personalized dispatching, resending, and draft lifecycle:
  - `logAndSendEmail(...)`: Renders personalized body, dispatches via `EmailService`, writes `CommunicationLog`, logs failure details if any.
  - `logAndSendSms(...)`: Renders personalized SMS body, dispatches via `SmsService`, writes `CommunicationLog`, logs failure details if any.
  - `sendToUser(User $user, string $channel, string $subject, string $content, ...)`: Replaces `{name}`, `{email}`, `{phone}`, etc. with user attributes and sends.
  - `sendBulk(array $target, string $channel, string $subject, string $content, ?int $campaignId, ?int $senderId)`: Resolves recipient users, iterates with per-user personalized placeholder substitution, manages transactions and batch progress.
  - `resend(CommunicationLog $log, ?int $adminId)`: Re-attempts dispatch of an existing log, updates retry count, records failure or success, and audit logs.
  - `bulkResend(array $logIds, ?int $adminId)`: Batch resends selected logs.

#### [MODIFY] [SmsService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/SmsService.php)
- Ensure all SMS dispatches (raw, template, or test) hook into `CommunicationLog` when called independently.
- Return structured status result (success bool + detailed reason) so callers can capture exact error messages (e.g. gateway disabled, missing API key, HTTP error response).

#### [MODIFY] [EmailService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/EmailService.php)
- Support returning detailed status / exception messages and hook into `CommunicationLog`.

#### [MODIFY] [OtpService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/OtpService.php)
- Enhance OTP generation and templates to automatically include personalized recipient name (`$user?->name ?? 'User'`), and record logs with recipient user relationship.

#### [MODIFY] [MessageTemplateSeeder.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/MessageTemplateSeeder.php)
- Update default SMS and Email seed templates to include personalized user greeting `{name}` (e.g. `Dear {name}, your SNT CSSC verification OTP is {otp}...`).

---

### Admin Panel UI & Livewire Components

#### [NEW] [⚡logs.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/communications/%E2%9A%A1logs.blade.php)
- Delivery Monitoring Dashboard:
  - **Metric Stat Cards**: Total Messages Sent, Success Rate %, Failed Count, SMS Dispatches, Email Dispatches.
  - **Filters**: Channel (All / Email / SMS), Status (All / Sent / Failed / Delivered), Date Range, Search (recipient, name, subject, body).
  - **Data Table**:
    * Recipient with Avatar / Initials & Name & Email/Phone
    * Channel Badge (Email / SMS)
    * Type & Template
    * Subject / Preview snippet
    * Status Badge (Sent, Delivered, Failed) with error tooltip
    * Sent Datetime & Resend Counter
    * Action buttons: View Details Modal, Resend button with spinner, Delete (soft delete)
  - **Batch Actions**: Bulk Resend selected failed messages, Bulk Soft Delete.
  - **Detail Modal**: Full rendered message preview (HTML viewer for email, phone bubble preview for SMS), raw variables JSON, gateway metadata, delivery timestamp, error traceback.

#### [NEW] [⚡compose.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/communications/%E2%9A%A1compose.blade.php)
- Interactive Composer & Drafts Manager:
  - **Tabs**: "Compose Message", "Saved Drafts", "Broadcast History".
  - **Composer Features**:
    * Channel Selector: Email, SMS, or Both
    * Audience Targeting: Single User (live searchable user picker), Bulk by Role (Admin, Member, etc.), All Registered Users, or Custom Manual Input (pasted list of phone numbers / emails).
    * Template Loader: Quick load from registered SMS or Email templates.
    * Personalization Tag Inserter toolbar: Click to insert `{name}`, `{email}`, `{phone}`, `{app_name}`, `{date}`, `{time}`.
    * Subject line (for Email) & Body (with live character/SMS part counter).
    * Live Personalized Preview tab: Preview how the message looks rendered with real sample user data.
    * Actions: "Save as Draft", "Send Now" (with confirmation dialog).
  - **Drafts Management**:
    * View saved drafts, Edit draft into composer, Duplicate, Send now, Delete draft.
  - **Broadcast History**:
    * View completed campaigns, total recipients, sent/failed metrics, view campaign logs.

#### [MODIFY] [sidebar.blade.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php)
- Add "Delivery Logs" and "Send & Drafts" under the `COMMUNICATIONS` sidebar section.

#### [MODIFY] [web.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register Livewire routes:
  - `communications/logs` -> `pages::admin.communications.logs`
  - `communications/compose` -> `pages::admin.communications.compose`

---

### Verification & Testing

#### [NEW] [CommunicationsTest.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/tests/Feature/CommunicationsTest.php)
- Test suite verifying:
  1. Personalised `{name}` placeholder substitution in SMS & Email dispatches (including OTP).
  2. Automatic `CommunicationLog` generation on outbound messages.
  3. Single and bulk resend functionality for failed and sent messages.
  4. Draft saving, updating, and dispatching.
  5. Bulk sending to users with per-user personalization.
  6. Soft deleting and restoring communication logs and campaigns.
  7. Audit log generation for communication activities.

#### [NEW] [changelogs/2026-08-24-communications-hub.md](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-24-communications-hub.md)
- Markdown changelog file detailing the newly introduced features, architecture, schemas, and UI capabilities.

---

## Verification Plan

### Automated Tests
```bash
php artisan test --compact tests/Feature/CommunicationsTest.php
php artisan test --compact
```

### Formatting & Quality Checks
```bash
vendor/bin/pint --dirty --format agent
```
