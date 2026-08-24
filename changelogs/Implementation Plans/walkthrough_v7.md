# Walkthrough: Dynamic Pages Management & Contact Helpdesk System

We have successfully implemented the **Application Pages Management** system and **Contact Us & Inquiries Subsystem** with multi-language translations, a rich text editor with live preview, pre-seeded institutional policy pages, public responsive views, and admin management with soft deletes and auditing.

---

## 🚀 What Was Built

### 1. Multilingual Application Pages Management
- **Database Schema**:
  - `pages`: `slug`, `status` (`published`/`draft`/`inactive`), `sort_order`, `is_system`, `view_count`, `created_by`, `updated_by`, `deleted_by`, soft deletes.
  - `page_translations`: `page_id`, `locale`, `title`, `meta_title`, `meta_description`, `meta_keywords`, `content`, soft deletes, composite unique index on `['page_id', 'locale']`.
- **Models**:
  - [`Page`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/Page.php): Scopes (`published`, `ordered`), auto-translation accessor with language fallback to default system locale, reading time calculator, audit logs.
  - [`PageTranslation`](file:///c:/Users/nilan/Downloads/sntcssc-mis/app/Models/PageTranslation.php): Localized content per active language.
- **Admin UI** ([`pages::admin.pages`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1pages.blade.php)):
  - Full server-side search, status filter, sortable columns, and pagination.
  - Multi-language tabs (English, Hindi, Bengali) with per-language title, meta tags, and rich content.
  - **Free-to-use Rich Text Editor** ([`rich-text-editor.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/components/ui/rich-text-editor.blade.php)): Headings, bold, italic, underline, strikethrough, color/background, lists, quotes, code, links, clean formatting.
  - **Google SERP Snippet Preview**: Real-time card showing how title, URL slug, and meta description will look in Google/Bing search results.
  - **Live Preview Tab**: Instant rendered preview of page typography in any language before saving.
  - Soft delete, restore, force delete (non-system pages), status toggle.

---

### 2. Pre-Seeded Institutional Policy Pages
The seeder [`PageSeeder`](file:///c:/Users/nilan/Downloads/sntcssc-mis/database/seeders/PageSeeder.php) created and localized 7 core institutional pages in English (`en`), Hindi (`hi`), and Bengali (`bn`):
1. **About Us** (`/about-us` or `/pages/about-us`)
2. **Privacy Policy** (`/privacy-policy` or `/pages/privacy-policy`)
3. **Terms and Conditions** (`/terms-and-conditions` or `/pages/terms-and-conditions`)
4. **Refund and Cancellation Policy** (`/refund-and-cancellation-policy` or `/pages/refund-and-cancellation-policy`)
5. **Legal Disclaimer** (`/legal-disclaimer` or `/pages/legal-disclaimer`)
6. **Copyright Policy** (`/copyright-policy` or `/pages/copyright-policy`)
7. **Hyperlink Policy** (`/hyperlink-policy` or `/pages/hyperlink-policy`)

---

### 3. Public Dynamic Page Viewer
- **Component**: [`pages::public.page-view`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/public/page-view.blade.php)
- **Layout**: [`layouts/public.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/public.blade.php)
- **Features**:
  - Reactive language switching with `<x-locale-switcher/>`.
  - Injects dynamic SEO metadata (`<title>`, `<meta name="description">`, `<meta name="keywords">`, OpenGraph, Twitter card).
  - Breadcrumbs, reading time badge, last updated badge, policy sidebar drawer, helpdesk contact card.
  - View count increment.

---

### 4. Public Contact Us Page & Inquiries Form
- **Component**: [`pages::public.contact-us`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/public/contact-us.blade.php)
- **Routes**: `/contact` and `/contact-us`
- **Features**:
  - Interactive Livewire Contact Form: Name, Email, Mobile, WhatsApp, Department dropdown (from DB), Subject dropdown (from DB), Custom Subject, Message.
  - Invisible anti-spam honeypot.
  - Unique tracking reference number generation (`SNT-REQ-2026-XXXXXX`).
  - Campus info card with phone, mobile, email, timings, days from settings.
  - Responsive embedded Google Map.

---

### 5. Admin Contact Submissions & Master Data Management
- **Component**: [`pages::admin.contacts`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/%E2%9A%A1contacts.blade.php)
- **Features**:
  - **Submissions Tab**: Real-time KPI stats, search, department/status/priority filters, sort, view & respond modal with direct email/call/WhatsApp links, CSV/Excel export, CSV import, soft delete, and restore.
  - **Departments Tab**: Full CRUD with code, routing email, sort order, and active toggle.
  - **Subjects Tab**: Full CRUD linked to parent departments with code, sort order, and active toggle.

---

### 6. Navigation & Layouts
- **Welcome Page** ([`welcome.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/welcome.blade.php)):
  - Header links to Home, About Us, Contact Us.
  - Multi-column footer with campus address, quick links, all 6 legal policy pages, helpdesk email/phone, and submit inquiry CTA.
- **Admin Sidebar** ([`sidebar.blade.php`](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/layouts/app/sidebar.blade.php)):
  - Added **CONTENT & CMS** (`Pages Management`) and **SUPPORT & HELPDESK** (`Contact Inquiries`).

---

## 🧪 Verification & Test Results

All new and affected feature tests passed:

```powershell
php artisan test --filter=PagesManagementTest
# PASS: 6 passed (87 assertions)

php artisan test --filter=ContactSubmissionsTest
# PASS: 6 passed (38 assertions)

php artisan test --filter=AdminPagesTest
# PASS: 19 passed (22 assertions)
```

Laravel Pint formatting completed cleanly:
```powershell
vendor/bin/pint --format agent
```

Changelog generated at:
[`changelogs/application-pages-and-contact-management.md`](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/application-pages-and-contact-management.md)
