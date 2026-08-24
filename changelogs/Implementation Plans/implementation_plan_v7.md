# Implementation Plan: Dynamic Pages Management & Contact Submissions System

Implement an enterprise-grade **Application Pages Management** system with full multilingual support, SEO-friendly slugs, a rich text editor with live preview, pre-seeded institutional policy pages with translations, a public **Contact Us** page with interactive forms and embedded Google map, and an **Admin Contact Submissions & Master Data Management** module (supporting search, filter, sort, export, import, responses, and soft delete).

---

## User Review Required

> [!IMPORTANT]
> - **Languages Supported**: The dynamic pages and translations will hook directly into the application's active languages (`en` - English, `hi` - Hindi, `bn` - Bengali) configured in `Language::activeCached()` and switch reactively with the language switcher.
> - **Rich Text Editor**: A modern Quill-powered rich text editor with live side-by-side / tab preview, heading, list, formatting, quote, and code tools will be integrated seamlessly without external paid API keys.
> - **Public Routes**: Pages will be accessible via `/pages/{slug}` as well as direct aliases for standard routes.
> - **Seeded Pages**: Pre-seeded with UPSC / Civil Services coaching & institutional content in all 3 languages (English, Hindi, Bengali):
>   1. About Us (`about-us`)
>   2. Privacy Policy (`privacy-policy`)
>   3. Terms and Conditions (`terms-and-conditions`)
>   4. Refund and Cancellation Policy (`refund-and-cancellation-policy`)
>   5. Legal Disclaimer (`legal-disclaimer`)
>   6. Copyright Policy (`copyright-policy`)
>   7. Hyperlink Policy (`hyperlink-policy`)

---

## Proposed Changes

### 1. Database Schema & Eloquent Models

#### [NEW] [Migration: `create_pages_and_translations_tables.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_24_000004_create_pages_and_translations_tables.php)
- `pages` table:
  - `id` (bigint unsigned)
  - `slug` (string, unique, indexed)
  - `sort_order` (integer, default 0)
  - `status` (string: `published`, `draft`, `inactive`, indexed)
  - `is_system` (boolean, default false - protect core policy pages from accidental slug rename)
  - `view_count` (unsigned bigint, default 0)
  - `created_by`, `updated_by`, `deleted_by` (foreign keys to users, nullable)
  - `created_at`, `updated_at`, `deleted_at` (soft deletes)
- `page_translations` table:
  - `id` (bigint unsigned)
  - `page_id` (foreign key to pages, cascade on delete)
  - `locale` (string 10, e.g. `en`, `hi`, `bn`, indexed)
  - `title` (string)
  - `meta_title` (string, nullable)
  - `meta_description` (text, nullable)
  - `meta_keywords` (string, nullable)
  - `content` (longText)
  - `created_at`, `updated_at`, `deleted_at` (soft deletes)
  - Unique composite index: `['page_id', 'locale']`

#### [NEW] [Migration: `create_contact_management_tables.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/migrations/2026_08_24_000005_create_contact_management_tables.php)
- `contact_departments` table:
  - `id`, `name`, `code` (unique), `email` (nullable), `description` (nullable), `sort_order`, `is_active`, soft deletes, timestamps.
- `contact_subjects` table:
  - `id`, `department_id` (foreign key nullable), `name`, `code` (nullable), `sort_order`, `is_active`, soft deletes, timestamps.
- `contact_submissions` table:
  - `id`, `reference_no` (unique tracking ID e.g. `SNT-REQ-2026-XXXXX`), `name`, `email`, `mobile`, `whatsapp` (nullable), `department_id` (foreign key nullable), `subject_id` (foreign key nullable), `custom_subject` (nullable), `message` (text), `ip_address` (nullable), `user_agent` (nullable), `status` (`new`, `in_progress`, `replied`, `resolved`, `closed`), `priority` (`low`, `medium`, `high`, `urgent`), `admin_notes` (text nullable), `replied_at` (timestamp nullable), `replied_by` (foreign key nullable), soft deletes, timestamps.

#### [NEW] Models:
- [`Page.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Page.php) (with scopes `published`, `ordered`, relationships `translations`, `creator`, `editor`, and helper methods `translate()`, `title`, `content`, etc.)
- [`PageTranslation.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/PageTranslation.php)
- [`ContactDepartment.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ContactDepartment.php)
- [`ContactSubject.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ContactSubject.php)
- [`ContactSubmission.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/ContactSubmission.php)

---

### 2. Database Seeders

#### [NEW] [`PageSeeder.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/PageSeeder.php)
- Idempotently seed and update the 7 required standard institutional pages with comprehensive, realistic, high-quality content in **English (`en`)**, **Hindi (`hi`)**, and **Bengali (`bn`)**:
  1. About Us (`about-us`)
  2. Privacy Policy (`privacy-policy`)
  3. Terms and Conditions (`terms-and-conditions`)
  4. Refund and Cancellation Policy (`refund-and-cancellation-policy`)
  5. Legal Disclaimer (`legal-disclaimer`)
  6. Copyright Policy (`copyright-policy`)
  7. Hyperlink Policy (`hyperlink-policy`)

#### [NEW] [`ContactMasterSeeder.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/ContactMasterSeeder.php)
- Seed initial standard departments (Admissions & Counseling, Academic & Faculty, Fee & Accounts, Examination & Test Series, Technical Support, General Inquiries) and initial subjects with links.

#### [MODIFY] [`DatabaseSeeder.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/DatabaseSeeder.php)
- Add `PageSeeder` and `ContactMasterSeeder` to `run()`.

---

### 3. UI Components & Rich Text Editor

#### [NEW] [`resources/views/components/ui/rich-text-editor.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/ui/rich-text-editor.blade.php)
- Built-in, zero-dependency Quill-based rich text editor component with toolbar options (H1/H2/H3, bold, italic, underline, strike, blockquote, code block, numbered/bullet list, link, clean formatting, text alignment).
- Livewire two-way data sync via `wire:model` or Alpine event bridge.
- Dark mode adaptive styling, placeholder support, character count, and clean HTML sanitization.

---

### 4. Admin Management Modules

#### [NEW] [`resources/views/pages/admin/⚡pages.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1pages.blade.php)
- Route: `/{current_team}/content/pages` (`admin.pages.index`)
- Full interactive data table with search, status filters, sort, sample/DB pagination.
- Create / Edit modal with multi-language tabs (English, Hindi, Bengali), auto-slug generation, SEO fields (Meta Title, Meta Description, Meta Keywords), Live Rich Text Editor, live Google SERP preview card, and real-time live preview tab.
- Soft delete, restore, force delete, status quick-toggle, view live link.
- Enterprise DB transaction wrapper, error handling, audit logging.

#### [NEW] [`resources/views/pages/admin/⚡contacts.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1contacts.blade.php)
- Route: `/{current_team}/support/contacts` (`admin.contacts.index`)
- Submissions tab: Search by ref#, name, email, phone, message; filter by status, priority, department, date range, trash; sort; bulk status update; export (CSV/Excel via `TableExport`); import (via `TableImport`).
- Details / Response modal: Full submission details, communication history, response recorder, internal notes, quick direct call / email / WhatsApp buttons.
- Departments & Subjects management tabs: Add, edit, reorder, soft delete departments and subjects.

---

### 5. Public Views & Routing

#### [NEW] [`resources/views/pages/public/page-view.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/public/page-view.blade.php)
- Clean, typography-styled public layout with dynamic SEO meta tags, breadcrumbs, language switcher, last updated date, reading time, responsive sidebar/toc, and share buttons.
- Automatically displays the active language's translation with fallback to default locale.

#### [NEW] [`resources/views/pages/public/contact-us.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/public/contact-us.blade.php)
- Public Contact Us page with:
  - Livewire Contact Form: Full Name, Email, Mobile, WhatsApp, Department & Subject dropdowns, Message, Honeypot spam trap, submission confirmation with tracking Reference ID.
  - Institution Contact Info Card: Address, Phone, Mobile, Email, Office Timing & Days (from `Setting` model).
  - Embedded responsive Google Map.

#### [MODIFY] [`routes/web.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/routes/web.php)
- Register public routes:
  - `/contact` & `/contact-us` -> Contact Us page
  - `/pages/{slug}` -> Dynamic Page View
  - Direct helper routes: `/about-us`, `/privacy-policy`, `/terms-and-conditions`, `/refund-and-cancellation-policy`, `/legal-disclaimer`, `/copyright-policy`, `/hyperlink-policy`
- Register Admin routes in `{current_team}` group:
  - `content/pages` -> `admin.pages.index`
  - `support/contacts` -> `admin.contacts.index`

#### [MODIFY] [`resources/views/welcome.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/welcome.blade.php)
- Update header and footer with organized link sections:
  - Header: Add links to "About Us", "Contact Us".
  - Footer: Multi-column links (About Us, Privacy Policy, Terms & Conditions, Refund Policy, Legal Disclaimer, Copyright, Hyperlink Policy, Contact Us with campus info & quick contact links).

#### [MODIFY] [`resources/views/layouts/app/sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php)
- Add "PAGES & CMS" and "SUPPORT & INQUIRIES" navigation sections in the admin sidebar.

---

### 6. Changelog Documentation

#### [NEW] [`changelogs/application-pages-and-contact-management.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/application-pages-and-contact-management.md)
- Complete structured changelog detailing all implemented features, database schema, models, UI components, seeders, routes, and security features.

---

## Verification Plan

### Automated Tests
- Create comprehensive Pest tests:
  - `tests/Feature/PagesManagementTest.php` (tests page creation, multilingual translations, editing, SEO tags, live rendering in `en`/`hi`/`bn`, soft deletion, restoration).
  - `tests/Feature/ContactSubmissionsTest.php` (tests contact form submission, validation, honeypot/rate limiting, admin listing, filter, export, status updates, department/subject CRUD).
- Run:
  ```powershell
  php artisan test --filter=PagesManagementTest
  php artisan test --filter=ContactSubmissionsTest
  php artisan test --compact
  ```
- Run formatting:
  ```powershell
  vendor/bin/pint --dirty --format agent
  ```

### Manual Verification
- Verify dynamic public pages render correctly in light & dark mode.
- Verify language switcher switches page text between English, Hindi, and Bengali.
- Verify contact form submits and creates contact submissions in DB with reference IDs.
- Verify admin panel pages editor works with rich text editor and live preview.
- Verify contact submissions can be filtered, sorted, replied to, and exported.
