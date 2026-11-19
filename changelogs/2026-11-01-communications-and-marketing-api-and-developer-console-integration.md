# Changelog: Communications & Marketing Module REST API Suite & Developer Console Integration

**Date:** November 1, 2026  
**Module:** Communications & Marketing Module • Delivery Logs Telemetry • Marketing Campaigns Engine • Subscribers CRM • Message Templates (Email & SMS) • API Developer Console & Documentation Portal • Cross-Platform Integration Architecture  
**Status:** Enterprise Production Ready  

---

## 1. Executive Summary

This release introduces the complete **Communications & Marketing Module** RESTful API suite (**48 production endpoints**), establishes future-ready cross-platform integration capabilities across **Mobile Apps** (Flutter, React Native, Swift, Kotlin), **Web Applications** (React, Vue, Livewire, Next.js), and **Desktop Clients** (Electron, Windows .NET 8 WPF/MAUI), and updates the in-admin **API Developer Console & Docs** (`⚡docs.blade.php` and `⚡index.blade.php`).

All endpoints strictly adhere to enterprise-grade production standards:
- **Dual Authentication:** Seamlessly supports both `auth:sanctum` personal access tokens for native mobile and single-page apps and `auth.api_key` (`X-API-Key` headers) for backend services, marketing automation daemons, and kiosk terminals.
- **Atomic Database Transactions:** All data mutations (campaign dispatch, subscriber registration, bulk tagging, template updates, log resends, and purge operations) are wrapped within `DB::beginTransaction()`, `DB::commit()`, and `DB::rollBack()`.
- **Defensive Security & Privacy:** Strict validation on all incoming payloads (e.g. template codes enforce `/^[a-zA-Z0-9_\-\.]+$/`, preventing path traversal and script injection), scope enforcement (`read:communications`, `write:communications`, `manage:communications`), and defensive error handling.
- **Carrier DLT & Regulatory Compliance:** Built-in compliance with Indian TRAI Distributed Ledger Technology (DLT) regulations (`dlt_template_id`, registered `sender_id`, and phone normalization).
- **Structured Audit Trails:** Comprehensive audit logging on every action using `AuditLogService::log()`.
- **Non-Destructive Soft Deletions:** Eloquent `SoftDeletes` implemented across all models (`CommunicationLog`, `CommunicationCampaign`, `Subscriber`, `EmailTemplate`, `SmsTemplate`) with dedicated restore workflows.
- **Standardized Response Envelopes:** Uniform JSON envelopes across all responses (`success`, `data`, `meta`, `error` with distinct codes).

---

## 2. API Scope Expansion (`ApiKeyService.php`)

Added 3 institutional security scopes in `app/Services/ApiKey/ApiKeyService.php`:

| Scope Identifier | Category | Description |
| :--- | :--- | :--- |
| `read:communications` | Communications & Marketing | View communication logs, campaigns, marketing subscribers, SMS and Email templates, and dispatch statistics. |
| `write:communications` | Communications & Marketing | Compose single/bulk communications, manage newsletter/WhatsApp subscribers, create and update SMS/Email templates. |
| `manage:communications` | Communications & Marketing | Execute bulk campaigns, cancel queued communications, retry failed dispatches, manage subscribers export/import, delete templates, and purge communication logs. |

---

## 3. Comprehensive REST API Controller (`CommunicationApiController.php`)

Constructed `app/Http/Controllers/Api/CommunicationApiController.php` providing **48 production-ready endpoints**:

### Complete Route Endpoints (48 Routes)

1. **Analytics, Dashboard & Diagnostics (3 Endpoints):**
   - `GET /api/v1/communications/dashboard` (`read:communications`) — Operational telemetry: total dispatches, delivered count, failed count, pending count, success rates, channel breakdowns, campaign/subscriber metrics, and recent dispatches.
   - `GET /api/v1/communications/statistics` (`read:communications`) — Cross-channel volume trends (7 to 90 days), channel ratios, failure cause distributions, template usage, and subscriber source acquisition analytics.
   - `GET /api/v1/communications/export` (`read:communications`) — Export filtered delivery logs as structured JSON dataset.

2. **Direct Composing & Quick Dispatch Services (3 Endpoints):**
   - `POST /api/v1/communications/send-email` (`write:communications`) — Dispatch an individual or templated Email (immediate or queued) with dynamic variables and options.
   - `POST /api/v1/communications/send-sms` (`write:communications`) — Dispatch an individual or templated SMS (immediate or queued) with DLT template ID and sender ID.
   - `POST /api/v1/communications/send-bulk` (`manage:communications`) — Dispatch multi-channel broadcast messaging across an audience cohort, role, or custom list.

3. **Communication Delivery Logs Management (7 Endpoints):**
   - `GET /api/v1/communications/logs` (`read:communications`) — Paginated catalog of delivery logs with multi-attribute filtering (channel, status, type, dates, search, user, campaign).
   - `GET /api/v1/communications/logs/{id}` (`read:communications`) — Detailed single delivery log record with recipient metadata and error tracing.
   - `POST /api/v1/communications/logs/{id}/resend` (`manage:communications`) — Resend an individual failed or past communication log item with resend counter increment.
   - `POST /api/v1/communications/logs/bulk-resend` (`manage:communications`) — Batch resend multiple communication logs by array of log IDs.
   - `DELETE /api/v1/communications/logs/{id}` (`manage:communications`) — Soft delete a communication delivery log record with audit logging.
   - `POST /api/v1/communications/logs/{id}/restore` (`manage:communications`) — Restore a soft deleted communication log record back to active logs.
   - `POST /api/v1/communications/logs/purge` (`manage:communications`) — Purge or archive communication logs older than specified retention days.

4. **Marketing Campaigns Management (9 Endpoints):**
   - `GET /api/v1/communications/campaigns` (`read:communications`) — Paginated catalog of marketing and notification campaigns with status and audience filters.
   - `POST /api/v1/communications/campaigns` (`write:communications`) — Create a new marketing campaign in draft or scheduled state with audience configuration.
   - `GET /api/v1/communications/campaigns/{id}` (`read:communications`) — Single campaign details, audience criteria, and real-time delivery telemetry.
   - `PUT /api/v1/communications/campaigns/{id}` (`write:communications`) — Update campaign parameters (permitted only for draft or scheduled campaigns).
   - `DELETE /api/v1/communications/campaigns/{id}` (`manage:communications`) — Safely soft delete a campaign record with audit trail.
   - `POST /api/v1/communications/campaigns/{id}/restore` (`manage:communications`) — Restore a soft deleted marketing campaign.
   - `POST /api/v1/communications/campaigns/{id}/dispatch` (`manage:communications`) — Trigger immediate execution or queue background asynchronous processing for a campaign.
   - `POST /api/v1/communications/campaigns/{id}/cancel` (`manage:communications`) — Cancel an active, queued, or scheduled campaign.
   - `GET /api/v1/communications/campaigns/{id}/logs` (`read:communications`) — Paginated list of delivery logs generated by a specific campaign.

5. **Marketing Subscribers CRM (10 Endpoints):**
   - `GET /api/v1/communications/subscribers` (`read:communications`) — Paginated directory of newsletter & WhatsApp marketing subscribers with tag & status filters.
   - `POST /api/v1/communications/subscribers` (`write:communications`) — Subscribe or reactivate a marketing contact with channel preference and tag segmentation.
   - `GET /api/v1/communications/subscribers/{identifier}` (`read:communications`) — Single subscriber profile dossier by numeric ID, UUID, email, or phone.
   - `PUT /api/v1/communications/subscribers/{identifier}` (`write:communications`) — Update subscriber details, contact channels, status, tags, and metadata.
   - `DELETE /api/v1/communications/subscribers/{identifier}` (`manage:communications`) — Soft delete a subscriber record with audit trail.
   - `POST /api/v1/communications/subscribers/{identifier}/restore` (`manage:communications`) — Restore a soft deleted subscriber back to active audience directory.
   - `POST /api/v1/communications/subscribers/{identifier}/unsubscribe` (`write:communications`) — Mark subscriber as unsubscribed with explicit reason.
   - `POST /api/v1/communications/subscribers/bulk-action` (`manage:communications`) — Perform bulk actions (activate, unsubscribe, add_tag, remove_tag, delete) across subscriber IDs.
   - `GET /api/v1/communications/subscribers/export` (`read:communications`) — Export filtered marketing subscribers directory as JSON dataset.
   - `POST /api/v1/communications/subscribers/import` (`manage:communications`) — Import subscriber contacts in bulk with automatic deduplication.

6. **Public Lead Capture & One-Click Unsubscribe (2 Endpoints):**
   - `POST /api/v1/communications/public/subscribe` (Public, `throttle:30,1`) — Public lead subscription endpoint for landing pages, mobile onboarding, and web footers.
   - `POST /api/v1/communications/public/unsubscribe` (Public, `throttle:30,1`) — Public one-click unsubscribe endpoint via token or verified email/phone.

7. **Email Message Templates Management (7 Endpoints):**
   - `GET /api/v1/communications/templates/email` (`read:communications`) — Catalog of Email message templates with category, search, and status filters.
   - `POST /api/v1/communications/templates/email` (`manage:communications`) — Create a new Email message template with subject, body HTML, and placeholder variables.
   - `GET /api/v1/communications/templates/email/{identifier}` (`read:communications`) — Retrieve single Email message template by unique code or numeric ID.
   - `PUT /api/v1/communications/templates/email/{identifier}` (`manage:communications`) — Update Email message template subject, HTML body, variables, or status.
   - `DELETE /api/v1/communications/templates/email/{identifier}` (`manage:communications`) — Soft delete an Email template with audit record.
   - `POST /api/v1/communications/templates/email/{identifier}/restore` (`manage:communications`) — Restore a soft deleted Email template.
   - `POST /api/v1/communications/templates/email/{identifier}/preview` (`read:communications`) — Generate dynamic rendered preview of Email template with sample or custom test variables.

8. **SMS Message Templates Management (7 Endpoints):**
   - `GET /api/v1/communications/templates/sms` (`read:communications`) — Catalog of SMS message templates with category, sender ID, and status filters.
   - `POST /api/v1/communications/templates/sms` (`manage:communications`) — Create a new SMS message template with DLT registration ID and sender ID.
   - `GET /api/v1/communications/templates/sms/{identifier}` (`read:communications`) — Retrieve single SMS message template by unique code or numeric ID.
   - `PUT /api/v1/communications/templates/sms/{identifier}` (`manage:communications`) — Update SMS template body, sender ID, DLT registration ID, or status.
   - `DELETE /api/v1/communications/templates/sms/{identifier}` (`manage:communications`) — Soft delete an SMS template with audit record.
   - `POST /api/v1/communications/templates/sms/{identifier}/restore` (`manage:communications`) — Restore a soft deleted SMS template.
   - `POST /api/v1/communications/templates/sms/{identifier}/preview` (`read:communications`) — Generate dynamic rendered preview of SMS template with character count and segment calculation.

---

## 4. Route Architecture & Precedence (`routes/api.php`)

- Registered public lead endpoints under `/api/v1/communications/public/*` with `throttle:30,1` rate-limiting.
- Registered authenticated endpoints under `/api/v1/communications/*` protected by `auth.api_key` and granular scopes (`read:communications`, `write:communications`, `manage:communications`).
- Enforced route order where static/specific sub-paths (`/dashboard`, `/statistics`, `/export`, `/bulk-resend`, `/purge`, `/send-email`, `/send-sms`, `/send-bulk`) precede parameterized paths (`/{id}`, `/{identifier}`).

---

## 5. Developer Console & In-Admin Documentation Integration

### `resources/views/pages/admin/api-keys/⚡docs.blade.php`:
- Added `'communications'` to presets category filters (`$sandboxCategoryFilter`).
- Added interactive preset buttons for key operations (`GET /communications/dashboard`, `GET /communications/statistics`, `POST /communications/send-email`, `POST /communications/send-sms`, `POST /communications/send-bulk`, `GET /communications/logs`, `POST /communications/logs/1/resend`, `GET /communications/campaigns`, `POST /communications/campaigns`, `POST /communications/campaigns/1/dispatch`, `GET /communications/subscribers`, `POST /communications/subscribers`, `POST /communications/public/subscribe`, `GET /communications/templates/email`, `GET /communications/templates/sms`, `GET /communications/export`).
- Added all 48 endpoints under category `'communications'` in the endpoints matrix catalog.
- Added interactive documentation viewer via `openGuide('communications')` linking directly to the comprehensive integration markdown guide.
- Added dedicated Communications & Marketing Guide Card in the Guides grid.

### `resources/views/pages/admin/api-keys/⚡index.blade.php`:
- Added quick test routes for Communications & Marketing in `$quickTestEndpoints`.
- Added `<optgroup label="{{ __('Communications & Marketing') }}">` in the interactive API key sandbox runner.

---

## 6. Enterprise Multi-Platform Integration Guide (`docs/integrations/communications-and-marketing-api.md`)

Authored comprehensive integration guide covering:
- Executive summary & architecture overview.
- Mermaid system architecture and campaign dispatch sequence diagrams.
- Dual authentication & security scopes reference.
- Complete 48 endpoint HTTP request/response specifications.
- Production SDK implementations:
  - **Flutter (Dart):** `CommunicationsApiClient`
  - **React Native (TypeScript):** `MobileCommunicationsService`
  - **C# .NET MAUI / WPF:** `CommunicationsApiClient`
- Enterprise hardening (atomicity, transactions, audit trails, DLT validation, queuing).

---

## 7. Multilingual Translations & UI Polish

- Added 12 translation keys in `lang/en.json`, `lang/bn.json`, and `lang/hi.json`:
  - `Communications & Marketing`
  - `Broadcast • Campaigns • CRM`
  - `Communications & Marketing Integration Guide`
  - `Realtime Telemetry & Channels`
  - `Volume Trends & Delivery Rates`
  - `Delivery Logs Directory`
  - `Marketing Campaigns Catalog`
  - `Newsletter & WhatsApp Audience`
  - `Export Delivery Dataset`
  - `Email Templates`
  - `SMS Templates`
  - Complete guide overview descriptions.
- Confirmed Lucide icon compatibility (`megaphone`, `book-open`).

---

## 8. Automated Testing & Verification Suite (`CommunicationsApiTest.php`)

Constructed comprehensive Pest test suite `tests/Feature/CommunicationsApiTest.php` with **13 test cases** and **136 assertions** passing at 100%:
- Authentication & Scope Enforcement (401 on missing credentials, 403 on unauthorized scopes).
- Dual Authentication via Sanctum bearer token.
- Dashboard telemetry, statistical distributions, and JSON export.
- Direct Email & SMS composing and delivery log generation.
- Bulk broadcast dispatch & campaign initiation.
- Communication logs CRUD, resend, bulk-resend, soft delete, restore, and purge.
- Marketing campaigns complete lifecycle (create, show, update, cancel, dispatch, delete, restore, logs).
- Marketing subscribers directory query, creation, update, administrative unsubscribe, bulk actions, and export/import.
- Public lead capture and one-click token-based unsubscribe.
- Email and SMS message templates CRUD and dynamic placeholder previews.
- Soft deletion and restoration verification across all entities with audit trail checks.
