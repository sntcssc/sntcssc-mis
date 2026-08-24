# Enterprise-Grade Support Ticket System

## Overview
Implement a production-ready, enterprise-grade **Support Ticket & Helpdesk Management System** for the SNT CSSC MIS. The module will serve both administrative/support staff and end-users (students, applicants, faculty, staff), featuring automated ticket numbering, multi-category routing, SLA calculation and breach tracking, threaded conversations with internal staff notes vs public replies, canned responses/macros, file attachments, real-time metrics & Kanban/table views, satisfaction ratings, automated email/SMS notifications, soft deletes, database transactions, and comprehensive audit logs.

---

## Proposed Changes

### 1. Database Migrations & Schemas
Create relational migrations for support tickets, categories, messages, attachments, and canned responses:

#### [NEW] [create_ticket_system_tables.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_25_004500_create_ticket_system_tables.php)
- `ticket_categories`: `name`, `slug`, `description`, `icon`, `color_badge`, `default_priority`, `sla_response_hours`, `sla_resolution_hours`, `default_assigned_user_id`, `is_active`, `sort_order`, soft deletes, timestamps.
- `tickets`: `ticket_number` (`TICK-YYYYMM-XXXX`), `uuid`, `user_id`, `guest_name`, `guest_email`, `guest_phone`, `category_id`, `assigned_to_user_id`, `priority` (`low`, `medium`, `high`, `urgent`), `status` (`open`, `in_progress`, `pending_user`, `on_hold`, `resolved`, `closed`), `subject`, `description`, `source`, `last_reply_at`, `last_reply_by_user_id`, `resolved_at`, `closed_at`, `first_response_due_at`, `resolution_due_at`, `first_responded_at`, `is_sla_response_breached`, `is_sla_resolution_breached`, `satisfaction_rating` (1-5), `satisfaction_feedback`, `metadata` (JSON), soft deletes, deleted_by, timestamps.
- `ticket_messages`: `ticket_id`, `user_id`, `sender_name`, `sender_email`, `type` (`public_reply`, `internal_note`, `system_event`), `message`, `metadata` (JSON), soft deletes, timestamps.
- `ticket_attachments`: `ticket_id`, `ticket_message_id`, `uploaded_by_user_id`, `filename`, `original_filename`, `mime_type`, `size_bytes`, `disk`, `path`, soft deletes, timestamps.
- `ticket_canned_responses`: `title`, `shortcut`, `category_id`, `content`, `is_active`, `created_by`, soft deletes, timestamps.

---

### 2. Eloquent Models
Grouped in `app/Models/`:

#### [NEW] [TicketCategory.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/TicketCategory.php)
- Relationships (`tickets`, `defaultAssignee`, `cannedResponses`), scopes (`active`, `ordered`), priority helpers.

#### [NEW] [Ticket.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Ticket.php)
- Relationships (`user`, `assignedTo`, `category`, `messages`, `attachments`, `lastReplyBy`).
- Constants for `STATUS_*`, `PRIORITY_*`, `SOURCE_*`.
- Badge color getters, SLA progress calculation, formatted ticket identifier, and status helper methods.

#### [NEW] [TicketMessage.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/TicketMessage.php)
- Constants for `TYPE_PUBLIC_REPLY`, `TYPE_INTERNAL_NOTE`, `TYPE_SYSTEM_EVENT`.
- Relationship to `ticket`, `user`, and `attachments`.

#### [NEW] [TicketAttachment.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/TicketAttachment.php)
- File size formatting, URL resolution, file icon resolver, relationship to `ticket` and `message`.

#### [NEW] [TicketCannedResponse.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/TicketCannedResponse.php)
- Shortcut resolver, placeholder replacement (`{user_name}`, `{ticket_number}`, `{agent_name}`, `{app_name}`).

---

### 3. Business Logic, Policies & Services
Grouped in `app/Services/` and `app/Policies/`:

#### [NEW] [TicketService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/TicketService.php)
- `createTicket(...)`: Generates sequential ticket code, calculates SLA due timestamps, saves attachments, creates opening message, logs audit trail, sends notification email/SMS.
- `replyTicket(...)`: Adds public reply or internal staff note, updates response timestamps, transitions status (e.g. `pending_user` $\rightarrow$ `open`), dispatches notifications.
- `updateStatus(...)`: Status changes with milestone timestamps (`resolved_at`, `closed_at`), system timeline records, and notifications.
- `assignTicket(...)`: Assigns to agent/team, emits timeline entry, notifies agent.
- `rateTicket(...)`: Records customer satisfaction score and feedback.
- `checkSlaBreaches()`: Scans active tickets for overdue responses/resolutions and flags breach warnings.
- `bulkAction(...)`: Mass update status, assignment, priority, or deletion.

#### [NEW] [TicketPolicy.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Policies/TicketPolicy.php)
- Authorizes user access: normal users can only view and reply to their own tickets; support agents/administrators can manage and view internal notes based on permissions.

#### [MODIFY] [RbacService.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Services/RbacService.php)
- Add `Support & Helpdesk` permissions: `tickets.view`, `tickets.create`, `tickets.reply`, `tickets.internal_notes`, `tickets.assign`, `tickets.manage`, `tickets.categories`, `tickets.canned_responses`.

---

### 4. Background Commands, Cron & Seeders

#### [NEW] [CheckTicketSlaBreachesCommand.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Console/Commands/CheckTicketSlaBreachesCommand.php)
- Artisan command `php artisan app:tickets:check-sla` to inspect SLA response/resolution breaches and trigger notifications.

#### [MODIFY] [CronJobSeeder.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/CronJobSeeder.php)
- Register `app:tickets:check-sla` running every 15 minutes.

#### [MODIFY] [MessageTemplateSeeder.php](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/MessageTemplateSeeder.php)
- Seed email and SMS templates: `ticket_created_user`, `ticket_created_staff`, `ticket_replied_user`, `ticket_replied_staff`, `ticket_status_changed`, `ticket_sla_warning`.

---

### 5. Livewire Components & User Interfaces

#### Admin / Helpdesk Control Center:
- **[NEW] [`resources/views/pages/admin/tickets/⚡index.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/tickets/%E2%9A%A1index.blade.php)**:
  - Metric summary cards (Open Tickets, In Progress, Pending Response, SLA At Risk, Resolved Today).
  - Dual view modes: Interactive **Table View** and drag-and-drop / click **Kanban Board** by status.
  - Filters: search, category, priority, status, assignee, date range.
  - Bulk actions bar (assign, change status, priority, export, delete).
- **[NEW] [`resources/views/pages/admin/tickets/⚡show.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/tickets/%E2%9A%A1show.blade.php)**:
  - Complete agent workspace with threaded message stream.
  - **Public Reply** vs **Internal Note** (amber highlight, staff-only).
  - Canned Response / Macro selector for one-click template insertion.
  - Attachment preview and uploader.
  - Sidebar with User Dossier (enrolled courses, past tickets, contact info), SLA timer, category/priority/status pickers.
- **[NEW] [`resources/views/pages/admin/tickets/⚡categories.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/tickets/%E2%9A%A1categories.blade.php)**:
  - Category and SLA management with response/resolution hour rules and default assignees.
- **[NEW] [`resources/views/pages/admin/tickets/⚡canned-responses.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/tickets/%E2%9A%A1canned-responses.blade.php)**:
  - Canned response templates with macro placeholder variables.

#### User / Student Portal:
- **[NEW] [`resources/views/pages/portal/tickets/⚡index.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/tickets/%E2%9A%A1index.blade.php)**:
  - User "My Support Tickets" page with status tabs, search, and "New Ticket" action.
- **[NEW] [`resources/views/pages/portal/tickets/⚡create.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/tickets/%E2%9A%A1create.blade.php)**:
  - User ticket submission form with category selection cards, priority selector, rich description, and attachment upload.
- **[NEW] [`resources/views/pages/portal/tickets/⚡show.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/portal/tickets/%E2%9A%A1show.blade.php)**:
  - User ticket thread view, reply box with file upload, status timeline, and satisfaction rating widget.

---

### 6. Navigation, Routes & Multi-Language Localization
- Update `routes/web.php` with admin support routes (`support/tickets`, `support/ticket-categories`, `support/canned-responses`) and portal routes (`tickets`, `tickets/create`, `tickets/{ticket:ticket_number}`).
- Update `resources/views/layouts/app/sidebar.blade.php` to expand `SUPPORT & HELPDESK` menu.
- Add comprehensive translations to `lang/en.json`, `lang/hi.json`, `lang/bn.json`.

---

## Verification Plan

### Automated Tests
Create `tests/Feature/Admin/SupportTicketManagementTest.php` and `tests/Feature/Portal/UserTicketPortalTest.php`:
1. User can submit a new support ticket with attachments and receive ticket number.
2. Staff can view tickets, filter by category/priority/status, and search.
3. Staff can add public replies and internal notes (internal notes hidden from user).
4. Status transitions update timestamps and trigger notifications.
5. SLA calculation and breach detection flag overdue tickets.
6. Canned responses insert formatted text with placeholder replacement.
7. User can rate resolved ticket (1-5 stars and feedback).
8. Soft delete and audit logs record all actions.
9. Authorization policies prevent unauthorized ticket access.

Execute tests using:
```bash
php artisan test --compact tests/Feature/Admin/SupportTicketManagementTest.php
php artisan test --compact
vendor/bin/pint --format agent
```

### Manual Verification
- Verify Livewire UI responsiveness, dark/light theme switching, and modal interactions.
