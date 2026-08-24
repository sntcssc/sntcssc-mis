# Walkthrough: Enterprise Communications Hub & Delivery Monitoring

We have implemented an enterprise-grade communications subsystem featuring **dynamic personalization (`{name}`)**, **real-time delivery logs & monitoring dashboard with one-click resend**, and an **individual/bulk SMS & email composer with saved drafts and live preview**.

---

## Key Features Implemented

### 1. Dynamic User Personalisation (`{name}`) Across All Dispatches
- **SMS & Email Services**: All outbound SMS and Email templates automatically support and inject `{name}` along with `{email}`, `{phone}`, `{app_name}`, `{date}`, and `{time}`.
- **OTP Codes**: Passwordless login, registration, and password reset OTP messages now greet the recipient personally by name (e.g. `Dear Rohan Sen, your SNT CSSC verification OTP is 123456...`).
- **Bulk & Individual Messages**: Every recipient in a broadcast receives a uniquely personalized message replacing placeholder tags with their specific user record data.

### 2. Communications Delivery Logs & Monitoring Dashboard
- **Location**: Admin Panel &rarr; Communications &rarr; [Delivery Logs](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/communications/%E2%9A%A1logs.blade.php) (`{current_team}/communications/logs`)
- **Key Capabilities**:
  - **Metrics**: Real-time counter cards for Total Messages, Success Rate %, Failed Deliveries, SMS Dispatches, and Email Dispatches.
  - **Status & Failure Reason Tracking**: Every log records whether delivery was `sent`/`delivered` or `failed`, with exact gateway error messages (e.g. gateway disabled, 2factor error, invalid format, timeout).
  - **One-Click Resend**: Admins can click the **Resend** button on any message to re-attempt delivery with live spinner feedback.
  - **Bulk Actions**: Select multiple logs to **Bulk Resend** or **Bulk Delete**.
  - **Details Modal**: Inspect the full rendered HTML email or SMS phone bubble preview, injected variables JSON, client IP, gateway driver, timestamps, and retry counters.
  - **Soft Deletes & Trash Management**: Full trash view with one-click restore and permanent delete options.

### 3. Individual & Bulk Composer with Saved Drafts
- **Location**: Admin Panel &rarr; Communications &rarr; [Send & Drafts](file:///c:/Users/nilan/Downloads/sntcssc-mis/resources/views/pages/admin/communications/%E2%9A%A1compose.blade.php) (`{current_team}/communications/compose`)
- **Key Capabilities**:
  - **Multi-Channel**: Dispatch via Email, SMS, or Both.
  - **Audience Targeting**:
    - *Individual*: Autocomplete search picker across users with avatar and email/phone info.
    - *Role-Based*: Target Owner, Admin, or Member groups.
    - *All Users*: One-click system broadcast.
    - *Custom List*: Paste comma or newline separated email addresses or mobile numbers.
  - **Template Pre-filling**: Pre-populate subject and body from registered SMS/Email templates.
  - **Personalisation Toolbar**: Insert `{name}`, `{email}`, `{phone}`, `{app_name}`, `{date}`, `{time}` with a single click.
  - **Live Recipient Preview**: Side-by-side simulated rendered view for HTML emails and SMS phone view with sample user data.
  - **Saved Drafts**: Save unfinished campaigns as drafts, edit anytime, duplicate, or dispatch directly.
  - **Broadcast History**: Track past campaigns with sent/failed ratios and direct links to filtered logs.

---

## Verification Results

### Automated Tests
Ran the full Pest test suite covering the new features:
```bash
php artisan test --compact tests/Feature/CommunicationsTest.php
```
- **10/10 feature tests passed** (45 assertions).

Full application test run:
```bash
php artisan test --compact
```
- **217/217 tests passed** (670 assertions).

### Code Formatting
Formatted with Laravel Pint according to standards:
```bash
vendor/bin/pint --format agent
```

---

## Changelog File
A changelog has been saved at:
- [2026-08-24-communications-hub-personalisation-logs-and-bulk-messaging.md](file:///c:/Users/nilan/Downloads/sntcssc-mis/changelogs/2026-08-24-communications-hub-personalisation-logs-and-bulk-messaging.md)
