# Changelog: Enterprise-Grade Support Ticket & Helpdesk Management System

**Date:** August 25, 2026  
**Status:** Completed & Fully Tested (283/283 Tests Passing)  
**Scope:** Helpdesk Control Center, Kanban Board & Table Views, Public Replies & Internal Staff Notes, SLA Tracking & Breach Escalation, Dynamic Canned Macros, Customer Dossier, Multi-File Attachments, CSAT 5-Star Satisfaction Ratings, Audit Trails, and Student Self-Service Portal.

---

## 1. Executive Summary

Implemented an enterprise-grade, production-ready **Support Ticket & Helpdesk Management System** for the SNT CSSC MIS platform. The system caters to both staff support teams (administrators, faculty, admission desk, accounts officers) and students/candidates through a modern, mobile-responsive Livewire interface supporting light and dark modes.

Key architectural features include automated SLA target calculation with priority weight multipliers, background cron breach monitoring, dual communication threads (public replies vs. confidential staff notes), canned macro response insertion with token interpolation, satisfaction review widgets, and granular RBAC permissions.

---

## 2. Key Architecture & Features Implemented

### A. Database Structure & Eloquent Models
- **`ticket_categories`**: Support departments (`Technical Support`, `Admissions & Enrollments`, `Courses & Academic Queries`, `Fee & Payment Verification`, `General Inquiries`), SLA response/resolution hours, default priority, default assignee, badge color, and icon.
- **`tickets`**: Auto-generated ticket code (`TICK-YYYYMM-XXXX`), UUID, submitter (`user_id` / guest contact), category, assignee, priority (`low`, `medium`, `high`, `urgent`), status (`open`, `in_progress`, `pending_user`, `on_hold`, `resolved`, `closed`), timestamps (`first_responded_at`, `resolved_at`, `closed_at`), SLA targets & breach flags (`is_sla_response_breached`, `is_sla_resolution_breached`), and CSAT ratings (1–5 stars + feedback review).
- **`ticket_messages`**: Timeline items distinguishing `public_reply` (sent to user), `internal_note` (staff-only confidential), and `system_event` (automatic status/assignment audit history).
- **`ticket_attachments`**: Secure multi-file attachments (PDF, DOCX, ZIP, JPG, PNG, WEBP) with mime-type safety checks and disk management.
- **`ticket_canned_responses`**: Macro templates with shortcut triggers (e.g., `/ack`, `/fee-info`, `/resolved`) and runtime interpolation (`{user_name}`, `{ticket_number}`, `{agent_name}`, `{app_name}`).

### B. Core Service & SLA Engine (`App\Services\TicketService`)
- **Atomic Operations**: All state changes, replies, notes, assignments, and file uploads executed within DB transactions.
- **SLA Engine**: Calculates `first_response_due_at` and `resolution_due_at` based on category targets modulated by priority multipliers (e.g. Urgent = 0.25x / 4x faster).
- **Automated Lifecycle Transitions**:
  - Submitter reply transitions status from `pending_user` / `resolved` to `open`.
  - Staff first public response sets `first_responded_at` and checks SLA adherence.
- **Audit Logging**: Logs all actions (`ticket_created`, `ticket_replied`, `ticket_internal_note`, `ticket_status_updated`, `ticket_assigned`, `ticket_priority_updated`, `ticket_rated`, `ticket_deleted`, `ticket_sla_breach`) with old/new values in `audit_logs`.
- **Omnichannel Alerts**: Email and SMS notifications for new tickets, assignee alerts, customer replies, status changes, and SLA breach warnings.

### C. Helpdesk Control Center (`pages::admin.tickets` & `pages::admin.tickets.show`)
- **Metrics Overview**: Real-time counters for Open, In Progress, SLA Breaches, Resolved Today, and Average CSAT Rating.
- **Dual View Modes**: Switch seamlessly between **Table View** and **Kanban Board View** by status columns.
- **Agent Workspace**:
  - Split conversation thread with distinct styling for Public Replies (blue) and Internal Staff Notes (amber lock highlight).
  - Quick macro insertion dropdown with instant template interpolation.
  - Multi-file attachment dropzone.
  - Workflow quick actions: Set In Progress, Await Customer, Resolve, Reopen.
  - Right sidebar containing live SLA countdowns, Customer Dossier (contact, registered account status, enrolled courses), and satisfaction rating score.
- **Categories & SLAs Manager (`pages::admin.tickets.categories`)**: CRUD for departments and SLA targets.
- **Canned Macros Manager (`pages::admin.tickets.canned-responses`)**: CRUD for response macros and quick shortcut codes.

### D. Student / User Support Portal (`pages::portal.tickets`, `create`, `show`)
- **My Tickets Dashboard**: Filter by active/in-progress vs resolved/closed with real-time status and priority badges.
- **Ticket Submission**: Step-by-step form with category selection cards, priority selector, markdown details, and multi-file uploads.
- **Conversation Thread**: Clean, chronological message stream showing public responses and attached documents (internal notes are strictly shielded).
- **Interactive CSAT Widget**: 5-star rating selector and feedback textarea upon resolution, with one-click "Reopen Ticket" support.

---

## 3. RBAC Permissions Added
Added `Support & Helpdesk` module with:
- `tickets.view`: View support tickets list and conversations.
- `tickets.create`: Open new support tickets on behalf of users.
- `tickets.reply`: Post public responses to support tickets.
- `tickets.internal_notes`: Post internal staff-only notes on tickets.
- `tickets.assign`: Assign and reassign ticket owners and agents.
- `tickets.manage`: Update ticket statuses, priorities, SLAs, and delete tickets.
- `tickets.categories`: Manage support ticket categories and SLA rules.
- `tickets.canned_responses`: Manage canned responses and response macros.

---

## 4. UI/UX Layout Refinements & Icon Enhancements
- **Lucide Icons**: Registered `life-buoy`, `message-square-quote`, `help-circle`, `paperclip`, `plus-circle`, `play`, `columns`, and `edit-2` in `App\Support\LucideIcons`.
- **Sidebar Active State Fix**: Resolved parent active route conflicts by explicitly scoping active state for Support Tickets (`admin.tickets.index`, `admin.tickets.show`) separately from `admin.tickets.categories*` and `admin.tickets.canned-responses*`.
- **Communication Delivery Logs Modal**: Enhanced responsiveness, fixed viewport bounds with `max-h-[88vh]`, backdrop click dismiss, escape key listener, and sandboxed isolated containment for complex email payload HTML and monospace SMS previews.
- **Modal Layout Spacing**: Increased max width to `max-w-xl`, improved input label spacing, clean responsive grid, and interactive clickable placeholder tokens (`{user_name}`, `{ticket_number}`, `{agent_name}`, `{app_name}`) in macro creators.

---

## 5. Verification & Testing

- **Pest Feature Tests**:
  - `tests/Feature/Admin/SupportTicketManagementTest.php` (6 tests, 21 assertions)
  - `tests/Feature/Portal/UserTicketPortalTest.php` (4 tests, 17 assertions)
- **Full Test Suite Run**: **283 / 283 tests passed (1,028 assertions)** with 0 failures or errors.
- **Code Style**: Formatted 100% compliant with Laravel Pint (`vendor/bin/pint --format agent`).