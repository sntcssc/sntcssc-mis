# Enterprise Communications Hub: Personalisation, Delivery Logs & Monitoring, Individual/Bulk Messaging & Drafts

**Date:** 2026-08-24  
**Scope:** Dynamic recipient personalization (`{name}`, `{email}`, `{phone}`, etc.) across all system SMS and Email dispatches, centralized delivery logging & monitoring dashboard with real-time stats and failure analysis, one-click & bulk resend functionality, individual & bulk message composer with saved drafts management, visual vs HTML source modes, live personalized preview, toggle switch animations, icon library expansion, soft deletes, DB transactions, audit logs, and comprehensive Pest tests.

---

## Summary of Completed Changes

### 1. Database Migrations & Models

- **`database/migrations/2026_08_24_000001_create_communication_logs_table.php`**:
  - Created `communication_logs` table storing `channel` (`email`, `sms`), `type` (`otp`, `notification`, `notice`, `broadcast`, `custom_individual`, `custom_bulk`), `recipient`, `recipient_name`, `user_id` (foreign key to `users`), `template_code`, `subject`, `content` (longText), `variables` (json), `metadata` (json for gateway responses, IP, headers), `status` (`sent`, `delivered`, `failed`, `pending`, `draft`), `error_message` (failure reason), `sent_by` (admin FK), `campaign_id`, `sent_at`, `delivered_at`, `failed_at`, `resend_count`, `last_resent_at`, `deleted_at` (`SoftDeletes`), and `timestamps`.
  - Added indexes on `['channel', 'status']`, `recipient`, `user_id`, `type`, and `created_at`.
- **`database/migrations/2026_08_24_000002_create_communication_campaigns_table.php`**:
  - Created `communication_campaigns` table supporting message campaigns and drafts with `title`, `channel` (`email`, `sms`, `both`), `recipient_type` (`individual`, `role`, `all_users`, `custom_list`), `recipient_role`, `recipient_ids` (json), `template_code`, `subject`, `content`, `status` (`draft`, `scheduled`, `processing`, `completed`, `failed`), metrics counters (`total_recipients`, `sent_count`, `failed_count`), `scheduled_at`, `sent_at`, `created_by`, `updated_by`, and `deleted_at` (`SoftDeletes`).
- **`app/Models/CommunicationLog.php`**:
  - Eloquent model with `Auditable`, `SoftDeletes`, `HasFactory`, relationship mappings (`user`, `sender`, `campaign`), query scopes (`sms`, `email`, `successful`, `failed`, `inDateRange`, `channel`, `status`), and helper methods (`isEmail()`, `isSms()`, `isDelivered()`, `isFailed()`).
- **`app/Models/CommunicationCampaign.php`**:
  - Eloquent model with `Auditable`, `SoftDeletes`, `HasFactory`, relationship mappings (`creator`, `updater`, `logs`), query scopes (`drafts`, `completed`, `active`), and helper methods.

---

### 2. Core Service Layer & Personalisation Engine

- **`app/Services/CommunicationService.php`**:
  - Central orchestrator for all outgoing communications, delivery logging, individual/bulk dispatching, resending, and draft lifecycle.
  - **Personalisation Engine (`personalizeString`)**: Dynamically evaluates and interpolates recipient variables (`{name}`, `{email}`, `{phone}`, `{app_name}`, `{date}`, `{time}`) for every individual recipient.
  - **`logAndSendEmail` & `logAndSendSms`**: Executes dispatch through underlying gateways, captures errors gracefully without throwing unhandled exceptions, and writes detailed delivery log entries.
  - **`resend(CommunicationLog $log)` & `bulkResend(array $logIds)`**: Re-attempts delivery of single or multiple failed/sent messages in a `DB::transaction`, updates retry counters and timestamps, records new error traces if failed, and emits `AuditLogService` events.
  - **`dispatchCampaign`**: Resolves recipient audiences (individual users, team roles, all users, or custom email/phone lists) and delivers personalized dispatches while maintaining progress counters (`total_recipients`, `sent_count`, `failed_count`).
- **`app/Services/SmsService.php`**:
  - Added `sendDirect` returning structured `array{success: bool, error: ?string}` with detailed gateway error diagnostics.
  - Updated `send` and `sendTemplate` to automatically record outbound logs and pass recipient `$user` model for greeting personalization.
- **`app/Services/EmailService.php`**:
  - Added `sendDirect` returning structured `array{success: bool, error: ?string}` with SMTP / mail transport error diagnostics.
  - Updated `send` and `sendTemplate` to automatically record outbound logs and pass recipient `$user` model for greeting personalization.
- **`app/Services/OtpService.php`**:
  - Enhanced OTP dispatching to automatically resolve `$user` by email or phone and inject personalized user greeting (`{name}`) across all OTP messages.
- **`database/seeders/MessageTemplateSeeder.php`**:
  - Updated default SMS and Email seed templates with personalized `{name}` greetings.

---

### 3. UI Fixes, Icon Library & Layout Enhancements

- **`app/Support/LucideIcons.php`**:
  - Added missing SVG icons: `edit-3`, `save`, `tool`, `trash`, `message-square`, `key`, `minus`, `file`, `shield-alert`, `database`, `edit`, and `log`.
  - Added case-insensitive name resolution (`strtolower(trim($name))`) to prevent placeholder fallback boxes.
- **`resources/views/components/ui/switch.blade.php`**:
  - Fixed toggle animation across the entire application using `peer-checked:[&>span]:translate-x-[16px]` and smooth CSS transitions, ensuring the toggle knob slides and changes color smoothly on state change.
- **`resources/views/components/settings-nav.blade.php`**:
  - Enhanced navigation layout with horizontal scrollbar styling (`scrollbar-thin`) and full width responsiveness so the **System** tab is always visible and easily accessible.
- **`resources/views/pages/admin/templates/⚡sms-templates.blade.php` & `⚡email-templates.blade.php`**:
  - Updated category filter bars with clean responsive scrolling and flex wrapping so the **Promotional** tab is 100% visible and unclipped.
  - Restored edit button icon visibility across all template tables.
- **`resources/views/pages/admin/communications/⚡compose.blade.php`**:
  - Fixed individual user selection checkboxes by binding directly to Livewire `wire:model.live="selectedUserIds"`.
  - Added interactive selected recipient tags/pills with single-click remove buttons and "Select Visible" / "Clear All" helpers.
  - Added **Visual Editor** vs **HTML Source** mode switcher for email composition.
  - Rebuilt Live Preview card to render clean visual HTML email previews without raw markup code entities.

---

### 4. Automated Testing Suite

- **`tests/Feature/CommunicationsTest.php`**:
  - 12 comprehensive feature tests covering:
    - Page rendering for Delivery Logs and Compose pages.
    - Personalisation in SMS & Email services with `{name}` injection.
    - OTP greeting personalization in SMS & Email.
    - Failed delivery logging and error capture when gateways are disabled.
    - Single resend and bulk resend functionality.
    - Campaign bulk dispatching with per-recipient custom variable substitution.
    - Saved drafts creation, updating, loading, and soft deletion.
    - Individual user selection, removal, and array synchronization.
    - LucideIcons helper resolving newly registered icons.
    - Livewire component actions (filter, resend, delete, restore).
- Code formatted with Laravel Pint.
