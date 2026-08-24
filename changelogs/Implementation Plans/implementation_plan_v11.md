# Enterprise-Grade Newsletter & WhatsApp Subscribers Management System and Delete Confirmation Modals

## User Review Required

> [!IMPORTANT]
> - **Public Subscription Widget**: A modern, mobile-friendly subscription widget will be added to the public welcome/landing page allowing visitors to subscribe to Email newsletters and WhatsApp updates with anti-spam protections (honeypot + rate-limiting) and automatic welcome notifications.
> - **Unified Delete Confirmation Modals**: Every delete action across the new features and existing management interfaces (Ticket categories, Canned responses, Support tickets, Subscribers, Backup archives) will be transitioned to modern, accessible `<x-ui.modal>` confirmation dialogs instead of raw browser `wire:confirm` alerts.

---

## Proposed Changes

### 1. Database Architecture & Eloquent Models

#### [NEW] `database/migrations/2026_08_25_013000_create_subscribers_table.php`
- `subscribers` table:
  - `id`, `uuid`
  - `type` (`email`, `whatsapp`, `both`)
  - `email` (nullable, string, index)
  - `phone` (nullable, string, index - E.164 format)
  - `country_code` (default `+91`)
  - `name` (nullable, string)
  - `status` (`active`, `unsubscribed`, `bounced`, `pending_verification`)
  - `source` (`welcome_page`, `footer`, `admin_manual`, `import`, `course_inquiry`, `checkout`)
  - `tags` (JSON array: e.g. `['upsc', 'general', 'admissions_2026', 'current_affairs']`)
  - `ip_address`, `user_agent`
  - `subscribed_at`, `unsubscribed_at`, `unsubscribe_token`
  - `metadata` (JSON)
  - `created_by`, `updated_by`, `deleted_by`
  - `created_at`, `updated_at`, `deleted_at` (SoftDeletes)

#### [NEW] `app/Models/Subscriber.php`
- Model with `Auditable`, `HasFactory`, `SoftDeletes`.
- Scopes: `scopeActive()`, `scopeEmail()`, `scopeWhatsapp()`, `scopeUnsubscribed()`, `scopeTagged($tag)`.
- Helper methods: `isEmail()`, `isWhatsapp()`, `formattedPhone()`, `statusBadgeColor()`, `unsubscribeUrl()`.

---

### 2. Core Service & Public Actions

#### [NEW] `app/Services/SubscriberService.php`
- `subscribe(array $data, ?User $actor = null)`: Subscribes user with DB transaction, welcome email/SMS dispatch, duplicate deduplication/reactivation, and audit logging.
- `unsubscribe(string $token, ?string $reason = null)`: 1-click unsubscribe for CAN-SPAM / GDPR compliance with audit trail.
- `updateSubscriber(Subscriber $subscriber, array $data, ?User $actor = null)`: Updates metadata, tags, and contact details with DB transaction.
- `deleteSubscriber(Subscriber $subscriber, ?User $actor = null, bool $force = false)`: Soft delete or force delete with audit logs.
- `bulkAction(string $action, array $ids, mixed $value = null, ?User $actor = null)`: Mass status update, tagging, soft delete, restore, or export.
- `importCsv(UploadedFile $file, ?User $actor = null)`: Fast bulk CSV importer with deduplication.

---

### 3. Public Welcome Page Subscription Component & Unsubscribe Controller

#### [NEW] `app/Livewire/Public/NewsletterSubscribe.php` or `resources/views/livewire/public/newsletter-subscribe.blade.php`
- Interactive card on landing page with tabbed or unified Email + WhatsApp collection.
- Honeypot anti-spam protection (`honeypot` hidden field) + Livewire validation.
- Success confirmation banner with real-time UI state updates.

#### [NEW] `app/Http/Controllers/Public/UnsubscribeController.php`
- Public 1-click unsubscribe landing page with optional feedback survey.

---

### 4. Admin Management Panel & Navigation

#### [NEW] `resources/views/pages/admin/⚡subscribers.blade.php`
- **Dashboard Metrics**: Total Subscribers, Active Emails, Active WhatsApp Numbers, Churn Rate, New This Month.
- **Filter Tabs**: `All`, `Email List`, `WhatsApp List`, `Active`, `Unsubscribed`, `Trash (Deleted)`.
- **Search & Filter Bar**: Search by email/phone/name, filter by source, tag, date range.
- **Table & Card Views**: Contact badge, channel icons (Email/WhatsApp), tags badges, subscription source, verified status.
- **Modals**:
  - `subscriber-create-modal`: Add Email / WhatsApp subscriber with tags & source.
  - `subscriber-edit-modal`: Edit contact details, status, tags.
  - `subscriber-delete-modal`: Dedicated confirmation modal showing record details and soft-delete explanation.
  - `subscriber-import-modal`: CSV file upload for mass list import with column mapping.
  - `subscriber-bulk-modal`: Bulk status change, bulk tagging, bulk deletion with confirmation modal.
- **Export**: Instant CSV/Excel export.

#### [MODIFY] Sidebar Navigation & Routes
- Update `routes/web.php` to register `admin.subscribers.index` and `unsubscribe`.
- Update `resources/views/layouts/app/sidebar.blade.php` under `MARKETING & SUBSCRIBERS` / `COMMUNICATIONS`.

---

### 5. Transition All Delete Triggers to Modern Confirmation Modals
- **Support Ticket Categories** (`admin.tickets.categories`): Replace inline delete with `<x-ui.modal name="delete-category-modal">`.
- **Canned Responses / Macros** (`admin.tickets.canned-responses`): Replace inline delete with `<x-ui.modal name="delete-macro-modal">`.
- **Support Tickets Desk** (`admin.tickets`): Replace bulk/individual delete with `<x-ui.modal name="delete-ticket-modal">`.
- **Communication Logs** (`admin.communications.logs`): Replace inline delete with `<x-ui.modal name="delete-log-modal">`.

---

### 6. RBAC & Multi-Language Localization
- Update `RbacService.php` with `subscribers.view`, `subscribers.manage`, `subscribers.export`, `subscribers.import`.
- Update `lang/en.json`, `lang/hi.json`, `lang/bn.json` with all subscriber and delete modal strings.

---

## Verification Plan

### Automated Tests
- `php artisan test --compact tests/Feature/Admin/SubscriberManagementTest.php`
- `php artisan test --compact tests/Feature/Public/NewsletterSubscriptionTest.php`
- `php artisan test --compact` (Full test suite 283+ tests)

### Code Formatting
- `vendor/bin/pint --format agent`

### Documentation
- `changelogs/2026-08-25-newsletter-and-whatsapp-subscribers-management.md`
