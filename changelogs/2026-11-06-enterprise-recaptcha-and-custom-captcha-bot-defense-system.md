# Enterprise Google reCAPTCHA & Custom CAPTCHA Service Bot Defense System

**Date:** 2026-11-06  
**Scope:** Security Architecture, Google reCAPTCHA Integration, Custom CAPTCHA Service Engine, Admin Panel Settings Hub, Real-time Telemetry, Verification Logs & Audit Trail, Public APIs, and Role-Based Access Control (RBAC).

---

## 1. Overview & Business Context

To safeguard the Satyendranath Tagore Civil Services Study Centre (SNT CSSC) Management Information System (MIS) against automated bot incursions, credential-stuffing attacks, brute-force password guessing, and automated form spam, a modular, enterprise-grade CAPTCHA and Bot Defense subsystem was designed and integrated.

The subsystem supports both **Google reCAPTCHA** (v2 Checkbox, v2 Invisible, and v3 Human Score evaluation) and a self-hosted **Custom CAPTCHA Service** (with five configurable challenge modes: numbers only, alphabets only, alphanumeric mix, alphanumeric with special characters, and dynamic mathematical problem solving).

All settings, verification workflows, telemetry metrics, and challenge audit logs are fully configurable in real-time from the Admin Panel Settings hub with role-based authorization, database transactions, soft-deletes, multi-language localization (English, Hindi, Bengali), and responsive dark/light mode interfaces.

---

## 2. Key Modules & Architectural Changes

### 2.1 Database & Normalized Schema
- **Migration:** `database/migrations/2026_11_06_010000_create_captcha_logs_table.php`
  - Created `captcha_logs` table storing complete verification audit trails:
    - `id`, `session_id`, `driver` (`google`, `custom`), `action` (`login`, `register`, `forgot_password`, `contact_us`, `api`, `test`)
    - `ip_address`, `user_agent`, `status` (`passed`, `failed`, `expired`, `blocked`)
    - `score` (decimal 3,2 for reCAPTCHA v3 confidence analysis), `error_code`, `response_time_ms`
    - `user_id` (foreign key to `users` with null on delete), `metadata` (JSON)
    - `deleted_at` (soft deletes for compliance and log retention audits)
    - Composite indexes on `[driver, status]`, `[action, created_at]`, and `[status, created_at]`.
- **Eloquent Model:** `App\Models\CaptchaLog`
  - Uses `Auditable`, `HasFactory`, and `SoftDeletes` traits.
  - Query scopes: `scopePassed()`, `scopeFailed()`, `scopeToday()`, `scopeDriver()`, `scopeAction()`.
  - Accessors: `status_badge_color` (badge formatting for Tailwind/Flux), `formatted_action`.
- **Factory:** `Database\Factories\CaptchaLogFactory` for Pest testing and test datasets.

### 2.2 Captcha Service & Driver Layer
- **Interface:** `App\Services\Captcha\Contracts\CaptchaDriverInterface`
  - Contracts for `getName()`, `render()`, `verify()`, and `generateChallenge()`.
- **Value Object:** `App\Services\Captcha\CaptchaResult`
  - Standardized immutable verification outcome with `isSuccess()`, `isFailure()`, `score`, `errorCode`, `message`, and `raw` payload.
- **Google reCAPTCHA Driver:** `App\Services\Captcha\Drivers\GoogleRecaptchaDriver`
  - Supports reCAPTCHA v2 Checkbox, v2 Invisible, and v3 Score-Based verification.
  - Dynamically reads `captcha.google_version`, `captcha.google_site_key`, encrypted `captcha.google_secret_key`, and `captcha.google_score_threshold`.
  - Supports endpoint switching between `www.google.com` and `www.recaptcha.net` (for regions or firewalls restricting direct google.com domains).
  - Includes a diagnostic handshake probe `testConnection()` for instant admin credentials verification.
- **Custom CAPTCHA Driver:** `App\Services\Captcha\Drivers\CustomCaptchaDriver`
  - Self-hosted challenge generator with 5 selectable modes:
    1. `numbers` (digits 0-9)
    2. `alphabets` (letters A-Z, a-z)
    3. `alphanumeric` (mix of numbers and letters)
    4. `special_characters` (alphanumeric + safe symbols `@#$%&*!?`)
    5. `math` (arithmetic questions: addition & subtraction, e.g. `14 + 8 = ?`)
  - Generates vector SVG with anti-OCR defense: random angular character rotation (-22° to +22°), Bézier noise curves, scatter noise dots, and multi-font baseline jitter.
  - Provides audio speech synthesis accessibility (`speechSynthesis` prompt readout) and 1-click challenge refresh.
  - Enforces single-use token lifecycle: encrypted payload cached with TTL, replay attack prevention, and maximum failed attempt limits (auto-invalidating brute-force bots).
- **Service Hub:** `App\Services\Captcha\CaptchaService`
  - Master switch evaluation (`captcha.enabled`) with fine-grained per-form toggles (`captcha.on_login`, `captcha.on_register`, `captcha.on_forgot_password`, `captcha.on_contact`).
  - Request-scoped verification memoization preventing multi-step pipeline token invalidation.
  - Real-time statistics aggregation (`getStatistics()`) calculating today's challenge counts, pass/fail rates, bot block counts, latency, and driver breakdowns.

### 2.3 Middleware & Validation
- **Validation Rule:** `App\Rules\ValidCaptcha`
  - Usable in standard Laravel validator arrays: `['captcha' => ['required', new ValidCaptcha('action')]]`.
- **Middleware:** `App\Http\Middleware\ValidateCaptchaMiddleware`
  - Registered with alias `'captcha'` in `bootstrap/app.php` for declarative route protection: `->middleware('captcha:action')`.
- **Blade Component:** `<x-captcha action="..." />` in `resources/views/components/captcha.blade.php`.

### 2.4 MIS Module Integrations
1. **User Sign In (Password & OTP):**
   - Protected in `app/Providers/FortifyServiceProvider.php` inside `Fortify::authenticateUsing()`.
   - Protected in `app/Http/Controllers/Auth/OtpLoginController.php` for passwordless OTP requests.
   - Embedded `<x-captcha action="login" />` in `resources/views/pages/auth/login.blade.php`.
2. **Student & User Registration:**
   - Protected in `app/Actions/Fortify/CreateNewUser.php`.
   - Embedded `<x-captcha action="register" />` in `resources/views/pages/auth/register.blade.php`.
3. **Password Reset:**
   - Protected in `app/Http/Controllers/Auth/OtpPasswordResetController.php` for OTP password resets.
   - Protected on standard `password.email` route via `captcha:forgot_password` middleware in `routes/web.php`.
   - Embedded `<x-captcha action="forgot_password" />` in `resources/views/pages/auth/forgot-password.blade.php`.
4. **Public Inquiries & Contact Form:**
   - Protected in `app/Http/Controllers/Public` / `resources/views/pages/public/contact-us.blade.php` with Honeypot fallback.
   - Embedded `<x-captcha action="contact" />`.

### 2.5 Admin Panel Settings Hub (`admin.settings.captcha`)
- **Route:** `Route::livewire('system/settings/captcha', 'pages::admin.settings.captcha')->name('admin.settings.captcha')` protected by `can:settings.captcha`.
- **Component:** `resources/views/pages/admin/settings/⚡captcha.blade.php`
  - **Overview & Telemetry:** 4 `<x-ui.stat-card>` widgets displaying today's challenges, successful passes, blocked bot attempts, and average latency.
  - **Configuration & Rules Tab:**
    - Master toggle switch.
    - Provider radio selector (Google reCAPTCHA vs Custom CAPTCHA).
    - Protected form checkboxes (Login, Register, Forgot Password, Contact).
    - Google reCAPTCHA section (version selector, site key, masked secret key with reveal toggle, v3 score slider 0.1–0.9, domain selector, and "Test Google Connection" button).
    - Custom CAPTCHA section (content mode dropdown, length slider 3–8, noise lines slider, noise dots slider, distortion toggle, expiry minutes dropdown, max attempts dropdown, and live preview simulator).
  - **Interactive Tester & Simulator Tab:**
    - Allows administrators to test challenge solving live inside the dashboard with instant validation feedback.
  - **Verification Logs & Audit Trail Tab (Full CRUD):**
    - Searchable by IP address, session ID, user agent.
    - Filterable by status (`passed`, `failed`, `expired`, `blocked`), driver, and action.
    - Detail Modal displaying full request metadata, score, and error codes.
    - Soft delete and restore actions for individual log entries.
    - Bulk prune modal (older than 7, 30, 60, or 90 days) with database transactions and audit logging.
    - Export logs to Excel (`.xlsx`), CSV (`.csv`), and PDF (`.pdf`) via `TableExporter`.
- **Navigation:** Integrated into `resources/views/components/settings-nav.blade.php` under key `'captcha'` with icon `'shield-check'`.

### 2.6 Public RESTful API Endpoints
- `GET /api/v1/captcha/generate` — Returns dynamic challenge token, SVG base64 image data URI, speech text, and expiry timestamp (throttled 120/min).
- `POST /api/v1/captcha/verify` — Validates captcha tokens and user answers via JSON API.
- `GET /api/v1/captcha/status` — Returns public capability status (active driver, protected actions, supported versions).

### 2.7 Role-Based Access Control (RBAC) & Permissions
- Added permission `'settings.captcha'` ('Configure Google reCAPTCHA, custom CAPTCHA service, security rules, and bot defense logs') under `'System Settings'` module in `App\Services\RbacService`.
- Assigned to `Super Administrator` and `Administrator` roles by default.
- Seeded via `RbacService::seedDefaults()`.

### 2.8 Design System, Icons & Localization
- Added Lucide `'bot'` icon SVG definition in `App\Support\LucideIcons`.
- Added 81 new translation keys across:
  - `lang/en.json` (English)
  - `lang/hi.json` (Hindi)
  - `lang/bn.json` (Bengali)
- Production-grade UI matching MIS design guidelines (Tailwind CSS, dark mode support, reactive Livewire single-file component, interactive modals replacing browser alerts).

---

## 3. Verification & Automated Test Coverage

Three comprehensive Pest test suites were authored and validated with 100% passing results:
1. `tests/Feature/Captcha/CaptchaServiceTest.php` (10 tests, 40 assertions)
   - Default disabled state.
   - Per-action enabling logic.
   - Challenge generation and SVG formatting.
   - Character mode switching (numbers, alphabets, math).
   - Single-use token verification and replay protection.
   - Max attempt enforcement and token invalidation.
   - Google reCAPTCHA siteverify HTTP mocking and v3 score threshold evaluation.
   - Verification log recording and real-time statistics calculation.
2. `tests/Feature/Captcha/CaptchaAdminSettingsTest.php` (8 tests, 28 assertions)
   - Route authentication and `settings.captcha` permission gates.
   - Updating and persisting Google & Custom settings via Livewire.
   - Interactive Google handshake test modal.
   - Interactive challenge solver tester.
   - Searching, filtering, soft-deleting, and restoring verification logs.
   - Pruning old logs with audit trail recording.
3. `tests/Feature/Captcha/CaptchaFormProtectionTest.php` (9 tests, 41 assertions)
   - Public API generate, verify, and status endpoints.
   - Login form protection (denying requests without captcha, allowing with valid captcha).
   - Contact form protection (denying requests without captcha, recording on valid captcha).
   - Password login & OTP login dual form DOM rendering.
   - OTP login send endpoint captcha validation enforcement (`login.otp.send`).
   - OTP password reset send endpoint captcha validation enforcement (`password.otp.send`).
   - Forgot password page dual flow captcha rendering (OTP reset and email reset link).

**Test Execution Summary:**
- **Total Tests:** 27
- **Total Assertions:** 109
- **Pass Rate:** 100%
- **Code Style:** Formatted and verified with `vendor/bin/pint --format agent`.

---

## 4. OTP Authentication CAPTCHA Integration Fix

### Problem Statement
When users selected the **OTP Sign In** method instead of the standard **Password Login**, the security verification widget was missing from the rendered view. When the user entered their mobile or email and clicked "Send Login Code", the backend `OtpLoginController@sendOtp` rejected the request with:
> *"Please enter the security verification code."*
Because the CAPTCHA widget was only present inside the Password login form, the user had no way to view or solve the security verification challenge.

### Implemented Fixes
1. **Dual Login View Integration (`resources/views/pages/auth/login.blade.php`):**
   - Added `#otp-captcha-container` containing `<x-captcha action="login" :options="['input_name' => 'otp_captcha_answer', 'token_name' => 'otp_captcha_token']" />` right above the "Send Login Code" button.
   - Bound Enter key press event on the container to automatically invoke `sendOtp()`.
   - Updated `sendOtp()` to extract CAPTCHA inputs (`otp_captcha_token`, `otp_captcha_answer`, `captcha_token`, `captcha_answer`, `g-recaptcha-response`) and attach them to the JSON payload for `route('login.otp.send')`.
   - Added client-side empty challenge check that focuses the security code input with immediate user feedback.
   - Added automated challenge regeneration on failed verification attempts so users never encounter stale or expired tokens.
   - Added automatic transition back to the request step if "Resend Code" is clicked while on the verification step, prompting for a fresh security verification before dispatching another SMS or Email OTP.
   - Added on-demand Google reCAPTCHA initialization (`window.initRecaptchaOnDemand?.()`) on tab switching between Password and OTP modes.
2. **Forgot Password OTP Flow Parity (`resources/views/pages/auth/forgot-password.blade.php`):**
   - Added `#forgot-otp-captcha-container` with `<x-captcha action="forgot_password" :options="['input_name' => 'otp_captcha_answer', 'token_name' => 'otp_captcha_token']" />` in the OTP password reset section.
   - Updated `sendOtp()` in `forgot-password.blade.php` to extract and forward CAPTCHA values to `route('password.otp.send')`.
   - Added automatic challenge refresh on verification failure and smooth navigation back to the request step on resend.
3. **Backend Fallback Flexibility (`CustomCaptchaDriver.php`, `CaptchaService.php`, `GoogleRecaptchaDriver.php`):**
   - Configured all drivers and service layer verifiers to seamlessly inspect both standard names (`captcha_token`, `captcha_answer`) and OTP-scoped names (`otp_captcha_token`, `otp_captcha_answer`, `g-recaptcha-response`).
4. **Translations (`lang/en.json`, `lang/hi.json`, `lang/bn.json`):**
   - Added localized messages for `"Please complete security verification to resend code."` and `"Please complete the security verification."` in English, Hindi, and Bengali.

### 4.1 Alpine "Expression Error: Invalid or unexpected token" Resolution
- **Root Cause 1 (Quote Collision):** When complex JavaScript methods were placed directly inside the HTML `x-data="{ ... }"` attribute, selector double quotes (`querySelector('button[aria-label="..."]')` and `querySelector('input[name="..."]')`) prematurely closed the HTML attribute string `x-data="..."`, causing the HTML parser to corrupt the JavaScript payload and Alpine to throw `Expression Error: Invalid or unexpected token`.
- **Root Cause 2 (`@checked` Component Attribute Conflict):** In `resources/views/pages/auth/login.blade.php`, `<x-ui.checkbox ... @checked(old('remember'))/>` was used. Blade component compilers treat attribute prefixes starting with `@` as component attributes/event listeners (`'@checked' => true`). When forwarded by `<x-ui.checkbox>` into the DOM via `{{ $attributes }}`, it rendered literally as `@checked="1"` on `<input type="checkbox">`. Alpine.js intercepted `@checked="1"` as an event listener directive and attempted to evaluate the expression, throwing `Expression: "@checked" Invalid or unexpected token`.
- **Architectural Solution:**
  - Extracted the Alpine component objects into dedicated, clean JavaScript controller functions: `loginAuthHandler()` and `forgotPasswordHandler()`.
  - Updated the view root elements to cleanly instantiate via `x-data="loginAuthHandler()"` and `x-data="forgotPasswordHandler()"`.
  - Added dedicated utility classes (`.captcha-refresh-btn`, `.captcha-token-input`, `.captcha-answer-input`) to eliminate nested quote-escaping in DOM selector queries.
  - Refactored `resources/views/components/ui/checkbox.blade.php` to accept `'checked' => false` as an explicit component prop and compile native HTML `@checked($checked)`.
  - Updated `resources/views/pages/auth/login.blade.php` to pass `:checked="(bool) old('remember')"` instead of `@checked(...)`.
  - Cleared compiled views cache and verified zero syntax errors and zero `@checked` Alpine directive collisions across the application.

### 4.2 Non-Production / Dev Environment Testing Autofill
- **Implementation:**
  - In `CustomCaptchaDriver.php`, when `! app()->isProduction()` (i.e. `local`, `testing`, `development`), the challenge's expected answer is safely passed to the widget view and prefilled into `<input name="captcha_answer" value="...">` as well as bound to Alpine state.
  - A subtle `Dev Autofill` badge (`डेव ऑटोफिल` / `ডেভ অটোফিল`) renders next to the title to notify developers and testers without confusing users in production.
  - In `CaptchaApiController@generate`, non-production environments return `autofill_answer` so that clicking the refresh challenge button also automatically updates the prefilled answer. In production/live environments, `expected_answer` is strictly stripped and never sent over the wire.

### 4.3 Contact Page Livewire CAPTCHA Verification Fix
- **Problem Statement:** In `resources/views/pages/public/contact-us.blade.php`, submitting the contact inquiry failed CAPTCHA verification with *"Security verification failed. Please try again."* even with a valid challenge.
- **Root Causes:**
  1. Livewire submits component updates via JSON without forwarding plain un-modeled HTML input elements. `request()->input('captcha_token')` and `request()->input('captcha_answer')` were always null during the Livewire action.
  2. The action name passed to `verifyRequest` was mismatched as `'contact_us'` while the settings toggle was configured under `captcha.on_contact`.
  3. Livewire component re-renders (e.g. changing the department dropdown) re-rendered the CAPTCHA widget, regenerating the challenge and desynchronizing the user's entered code.
- **Implemented Fixes:**
  - Refactored `submit(?string $captcha_token = null, ?string $captcha_answer = null, ?string $recaptcha_response = null)` to accept the CAPTCHA parameters directly from the form submission.
  - In `contact-us.blade.php`, updated `<form x-data x-on:submit.prevent="$wire.submit(new FormData($el).get('captcha_token'), new FormData($el).get('captcha_answer'), new FormData($el).get('g-recaptcha-response'))">`.
  - Added `wire:ignore` to the CAPTCHA container so dropdown selections and field updates do not destroy or re-roll the active challenge.
  - Added Livewire event dispatch `'captcha-refresh'` that automatically clicks `.captcha-refresh-btn` on failed verification attempts.
  - Aligned action name to `'contact'`.
  - Added Lucide icons `'toggle-right'` and `'toggle-left'` to `App\Support\LucideIcons`.
  - Added translations for `"Dev Autofill"` in English, Hindi, and Bengali.

---

## 5. Future-Ready Multi-Platform API Suite & Developer Console Integration

### 5.1 Comprehensive Cross-Platform API Suite
To ensure the CAPTCHA and Bot Defense subsystem is future-ready for headless microservices, mobile apps (Flutter, React Native, Swift iOS, Kotlin Android), and desktop clients (Electron, .NET 8 WPF/MAUI), a full suite of standardized RESTful endpoints was implemented under `/api/v1/captcha`:

1. **Client Specifications Bootstrap (`GET /api/v1/captcha/specs`):**
   - Returns complete runtime bot defense specifications including active driver (`custom` vs `google`), supported drivers list, public Google reCAPTCHA site key, domain endpoint, custom CAPTCHA settings (character mode, length, case sensitivity, timeout), developer autofill state, and protected actions matrix.
   - Enables frontends to dynamically determine whether and how to render security challenges without hardcoded client configs.
2. **Dynamic Challenge Generation (`GET /api/v1/captcha/generate`):**
   - Returns single-use UUID token, anti-OCR SVG vector image (Data URI and raw SVG), phonetic speech prompt, audio endpoint URL, expiry timestamp, and dev autofill answer.
3. **Headless Verification Endpoint (`POST /api/v1/captcha/verify`):**
   - Supports both JSON body payloads (`token`, `answer`, `g-recaptcha-response`) and HTTP headers (`X-Captcha-Token`, `X-Captcha-Answer`, `X-Recaptcha-Response`).
   - Evicts challenge immediately upon verification (replay protection) and records `CaptchaLog` telemetry.
4. **Atomic Refresh Endpoint (`POST /api/v1/captcha/refresh`):**
   - Atomically revokes the previous challenge token from Redis/Cache and issues a fresh challenge in a single round-trip, optimizing mobile bandwidth and reducing UI flicker.
5. **Acoustic Audio Readout Streaming (`GET /api/v1/captcha/audio/{token}`):**
   - Implemented `App\Services\Captcha\CaptchaAudioGenerator` in pure PHP to synthesize 16-bit PCM Mono WAV audio streams (RIFF/WAVE header, 11,025 Hz sampling rate, harmonic musical overtone frequencies, and smooth raised-cosine attack/decay envelopes).
   - Complies with WCAG 2.2 AA accessibility guidelines and enables native audio playback in mobile and desktop media players without requiring browser Web Speech APIs. Also supports `?format=json` for Base64 audio payloads.
6. **Administrative Diagnostics (`GET /api/v1/captcha/diagnostics`):**
   - Executes real-time driver self-tests with latency tracking in ms, probes cache store read/write health, computes real-time pass/fail rates, and returns recent verification logs.
7. **Administrative Verification Logs Pruning (`POST /api/v1/captcha/logs/prune`):**
   - Enables administrative purging of logs older than configurable days within a database transaction, supporting both soft-delete and force-delete with `AuditLogService` logging.

### 5.2 Scopes Catalog Expansion (`ApiKeyService.php`)
Added a dedicated category **`Bot Defense & CAPTCHA`** to `ApiKeyService::getAvailableScopes()`:
- `read:captcha`: Read CAPTCHA specifications, configuration, verification logs, and telemetry metrics.
- `verify:captcha`: Submit and verify CAPTCHA challenges via headless API or external clients.
- `manage:captcha`: Administrative management: update CAPTCHA driver settings, prune logs, and run diagnostics.

### 5.3 Developer Console & Documentation Integration (`⚡docs.blade.php`)
- Integrated all 8 Bot Defense endpoints into `endpointsList()` under category `'captcha'`.
- Added interactive presets in `selectPreset()` for `/api/v1/captcha/specs`, `/api/v1/captcha/generate`, `/api/v1/captcha/verify`, `/api/v1/captcha/refresh`, `/api/v1/captcha/audio/{token}`, `/api/v1/captcha/diagnostics`, and `/api/v1/captcha/logs/prune`.
- Added quick-filter pill button in the Interactive Sandbox filter bar.
- Added API table category filter tab `Bot Defense & CAPTCHA (8)`.
- Added the "Enterprise CAPTCHA & Bot Defense System Guide" Card linking to `docs/integrations/captcha-and-bot-defense-api.md`.
- Implemented `openGuide('captcha')` in the interactive console guide viewer modal, rendering multi-platform documentation and code snippets directly inside the admin panel.

### 5.4 Multi-Platform Integration Guide (`docs/integrations/captcha-and-bot-defense-api.md`)
- Authored a comprehensive, production-grade integration guide covering:
  - System architecture with Mermaid diagrams.
  - Complete RESTful API reference table.
  - Step-by-step implementation code for Flutter (Android/iOS), React Native, Desktop (.NET 8 WPF/MAUI), and Headless Microservices (cURL / Python).
  - WCAG 2.2 AA acoustic audio playback specifications.
  - Security considerations: replay prevention, secret shielding, and multi-domain CDN failover.

### 5.5 Localization & Icons
- Added translations for `"Bot Defense & CAPTCHA"`, `"Enterprise CAPTCHA & Bot Defense System Guide"`, and related strings in `lang/en.json`, `lang/hi.json`, and `lang/bn.json`.
- Verified `<x-icon name="shield-check" />` in `App\Support\LucideIcons`.

### 5.6 Automated Feature Verification
- Created `tests/Feature/Captcha/CaptchaApiIntegrationTest.php` with 11 comprehensive Pest tests:
  - Client specs endpoint structure and driver metadata.
  - SVG challenge generation with audio URL and dev autofill.
  - JSON payload verification and single-use token invalidation.
  - Header-based headless verification (`X-Captcha-Token`, `X-Captcha-Answer`).
  - 422 Unprocessable Content error handling for invalid answers.
  - Atomic refresh with old token invalidation.
  - 16-bit PCM WAV audio streaming and JSON Base64 formats.
  - 404 handling for expired or missing audio tokens.
  - Administrative diagnostics and latency telemetry.
  - Database transaction-wrapped log pruning with audit logging.
  - Developer Console Livewire interaction (preset selection, sandbox execution, and in-console guide modal rendering).
- Total CAPTCHA test suite: **39 passing tests**, **243 assertions**. Pint code formatting: **100% clean**.

---

## 6. Global Search & Command Palette Integration

### 6.1 Navigation Catalog Expansion (`GlobalSearchService.php`)
Integrated the CAPTCHA and Bot Defense module into the unified system navigation catalog:
- **`nav_settings_captcha`** (Category: `System & Settings`):
  - Title: *reCAPTCHA & Bot Defense Settings*
  - Permission: `settings.captcha` (Admin only)
  - Keywords: `captcha`, `recaptcha`, `google recaptcha`, `bot defense`, `custom captcha`, `security verification`, `svg captcha`, `audio captcha`, `math captcha`, `bot mitigation`, `waf`
  - URL: `route('admin.settings.captcha')`
- **`nav_captcha_logs`** (Category: `System & Settings`):
  - Title: *CAPTCHA Verification Logs & Telemetry*
  - Permission: `settings.captcha` (Admin only)
  - Keywords: `captcha logs`, `bot logs`, `verification logs`, `recaptcha telemetry`, `security logs`, `blocked bots`, `bot telemetry`
  - URL: `route('admin.settings.captcha', ['tab' => 'logs'])`
- **`nav_captcha_api_docs`** (Category: `API & Integrations`):
  - Title: *CAPTCHA & Bot Defense API Integration Docs*
  - Permission: `api_keys.view` (Admin only)
  - Keywords: `captcha api`, `bot defense api`, `recaptcha api`, `mobile captcha`, `developer docs`, `specs`, `audio api`, `bot defense`
  - URL: `route('admin.api-keys.docs')`

### 6.2 Quick Actions Command Palette
Added 2 dedicated instantaneous quick actions to `GlobalSearchService::quickActionsCatalog()`:
1. **`action_test_captcha`**:
   - Title: *Test CAPTCHA & Run Diagnostics*
   - Description: Launch interactive simulator, test Google connection and challenge verification.
   - Badge: `Simulator`
   - Direct Link: `route('admin.settings.captcha', ['tab' => 'tester'])`
2. **`action_configure_bot_defense`**:
   - Title: *Configure Bot Defense Providers*
   - Description: Switch between Google reCAPTCHA and Custom SVG Captcha service.
   - Badge: `Settings`
   - Direct Link: `route('admin.settings.captcha')`

### 6.3 Real-Time Live Entity Search (`CaptchaLog`)
Integrated real-time entity search in `GlobalSearchService::search()` under Section N:
- Allows administrators with `settings.captcha` or `Super Administrator` privileges to query verification logs by **IP Address**, **Session ID**, **Action**, **Error Code**, **Driver**, and **Status**.
- Automatically surfaces recent matching logs with status badges (`Passed`, `Failed`, `Blocked`, `Expired`), score, relative timestamp, and links directly to `admin.settings.captcha` with the IP pre-filtered.

### 6.4 Settings Component Deep-Linking (`⚡captcha.blade.php`)
- Updated `mount()` in `resources/views/pages/admin/settings/⚡captcha.blade.php` to parse `tab` and `search` query parameters.
- When an admin selects a search result from `Ctrl+K`, the component automatically switches to the `logs` tab and applies the search term immediately to the DataTable query.

### 6.5 Automated Feature Tests
- Added 2 new tests in `tests/Feature/GlobalSearchAndFullscreenTest.php` asserting that `navigationCatalog()` and `quickActionsCatalog()` contain the CAPTCHA entries, and that searching for `"captcha"` or an IP address matches both navigation items and `CaptchaLog` entities.
- All 15 tests in `GlobalSearchAndFullscreenTest.php` passing.

---

## 7. Localization, Missing Icons Resolution, and Enterprise Security Hardening

### 7.1 Missing Lucide Icons Resolution (`App\Support\LucideIcons`)
- **Icon Infrastructure Audit:** Ran an automated system-wide scan across all 259 unique Lucide icon references in `resources/views/` and `app/Services/GlobalSearchService.php`.
- **Missing Icon Fixed:**
  - Added official Lucide SVG path data for **`file-code`** (`<path d='m10 12.5-2 2.5 2 2.5'/><path d='m14 12.5 2 2.5-2 2.5'/><path d='M14 2v4a2 2 0 0 0 2 2h4'/><path d='M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7Z'/>`), resolving the missing icon for code & API docs in Global Search navigation.
  - Verified 100% icon availability across all views (0 missing icons remaining).

### 7.2 Complete Tri-Lingual Localization (`lang/en.json`, `lang/hi.json`, `lang/bn.json`)
- **Exhaustive String Extraction:** Scanned all Blade views, controllers, rules, and services associated with the module for `__()` and `@lang()` calls.
- **Added 55 Missing Translations:** Implemented high-quality, culturally natural, and grammatically accurate translations in English, Hindi, and Bengali:
  - Metric badges: `:count verification log entries pruned.`, `:rate% overall pass rate today`, `:total total challenges all-time`.
  - Filter and table labels: `All actions`, `All drivers`, `Audio readout`, `Audio text`, `Average verification latency`, `Blocked`, `Domain`, `Driver`, `Error Code`, `Record ID`, `Response Time (ms)`, `Reveal`, `Verification Status`.
  - Form placeholders & hints: `Answer the question`, `Enter exact characters`, `Enter security code`, `Enter the code above…`, `Search by IP, session, user agent…`.
  - Action feedback: `Security code verified successfully.`, `Security verification payload corrupted. Please refresh.`, `Verification log entry restored.`, `Verification log entry soft deleted.`, `Failed to delete log entry.`, `Failed to prune logs.`, `Failed to restore log entry.`, `Failed to save settings:`.
  - Retention dialogs: `Older than 7 days`, `Older than 30 days (Recommended)`, `Older than 90 days`, `Select a retention window. Verification audit logs older than the specified days will be permanently archived.`.
  - Endpoints & settings options: `www.google.com (Default Global Endpoint)`, `www.recaptcha.net (Accessible worldwide / China CDN)`, `Remaining calls`, `Wait seconds on 429`.
  - Global Search navigation: `CAPTCHA & Bot Defense API Integration Docs`, `reCAPTCHA & Bot Defense Settings`, `CAPTCHA Verification Logs & Telemetry`, `Test CAPTCHA & Run Diagnostics`, `Configure Bot Defense & CAPTCHA`, `Bot Defense & CAPTCHA`.
- Automated test confirmed **0 missing strings** across all supported languages.

### 7.3 Enterprise Security & Integrity Hardening
1. **Timing Attack Protection (`CustomCaptchaDriver.php`):**
   - Replaced loose string equality (`===`) with PHP's constant-time comparison `hash_equals($expectedNormalized, $userNormalized)` to eliminate side-channel timing attacks during answer verification.
2. **Strict Challenge Token Format Validation (`CustomCaptchaDriver.php`, `CaptchaApiController.php`):**
   - Added regex and length bounds enforcement (`^[a-zA-Z0-9\-_]{1,64}$`) on incoming challenge and audio tokens before executing cache lookups, preventing cache stampedes, injection attacks, and backend memory exhaustion.
3. **Audio Synthesis Resource Bounding (`CaptchaAudioGenerator.php`):**
   - Enforced maximum string length clamp (`mb_substr(trim($text), 0, 50)`) on input text to prevent CPU/memory exhaustion during mathematical wave synthesis.
4. **Secret Key Shielding & Dev Autofill Protection (`CaptchaApiController.php`):**
   - Hardened `generate()` and `refresh()` to unconditionally `unset($challenge['expected_answer'])` before dispatching responses, ensuring `expected_answer` is never leaked over the wire.
   - Restricted `autofill_answer` exclusively to non-production environments (`local`, `testing`, `development`).
5. **Cross-Site Scripting (XSS) Prevention (`GoogleRecaptchaDriver.php`, `CustomCaptchaDriver.php`):**
   - Added strict `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` escaping on dynamic site keys, action names, input names, and placeholder strings rendered into script and widget containers.
6. **Acoustic Audio Playback Enhancements (`CustomCaptchaDriver.php`):**
   - Upgraded widget audio playback in Alpine.js to stream authentic 16-bit PCM WAV audio from `/api/v1/captcha/audio/{token}` directly with an automatic fallback to browser SpeechSynthesis, preventing DOM inspection of plaintext challenge answers.

### 7.4 Verification Summary
- **Tests Executed:** `tests/Feature/Captcha/` + `tests/Feature/GlobalSearchAndFullscreenTest.php`
- **Results:** **54 passed tests**, **324 assertions**, **0 failures**.
- **Code Style:** Formatted cleanly with `Laravel Pint` (0 style violations).

---

## 8. Understandable Acoustic Voice Readout & Speech Synthesis Upgrade

### 8.1 Root Cause of Unintelligible Audio Sound
1. **Tone Pitch vs. Human Speech (Sine Beeps):**
   - The initial `CaptchaAudioGenerator.php` implementation synthesized pure mathematical sine waves mapping characters to musical notes (e.g. `'0'` -> 261 Hz, `'A'` -> 277 Hz, `'7'` -> 523 Hz, `'Z'` -> 830 Hz).
   - While valid audio waveforms, musical sine wave beeps (like DTMF telephone touch tones) do not possess human vocal formant frequencies, consonants, plosives, or vowels. As a result, a human listener hearing the tones heard musical beeps rather than intelligible spoken words.
2. **Audio Element Playback Override:**
   - In the frontend widget, the audio button was invoking the backend sine-wave generator via `new Audio('/api/v1/captcha/audio/' + token).play()` rather than speaking with native browser Text-to-Speech (TTS).

### 8.2 Architectural Resolution
1. **Pre-Rendered Studio Human Voice Assets (`resources/audio/captcha/en/`):**
   - Generated 16-bit 16,000 Hz Mono PCM WAV clips for:
     - Digits: `0` through `20` (`zero`, `one`, `two`, ... `nineteen`, `twenty`).
     - English Alphabet: `a` through `z` (`A`, `B`, `C`, ... `Z`).
     - Mathematical & Acoustic Words: `plus`, `minus`, `equals`, `what`, `is`, `question`, and `intro` (*"Security code."*).
2. **Concatenative Voice Synthesizer (`App\Services\Captcha\CaptchaAudioGenerator`):**
   - Upgraded `generateWav()` to read and concatenate studio voice clips with 160ms natural silence gaps between spoken characters.
   - For mathematical challenges (e.g. `"What is 5 plus 3?"`), it reads out each word naturally: *"What... is... five... plus... three?"*.
   - For alphanumeric challenges (e.g. `"7 B 9 K X"`), it begins with the gentle spoken intro *"Security code."* followed by distinct pronunciation of each character: *"Seven... B... Nine... K... X"*.
   - Includes graceful fallback to tone synthesis if voice assets are absent.
3. **Dual-Layer Frontend Playback (`CustomCaptchaDriver.php`):**
   - Updated `speakCaptcha()` in the widget to prioritize the browser's native Web Speech API (`window.speechSynthesis`) with adjusted rate (`0.82`) and `en-US` articulation for instant, zero-latency, studio-quality speech.
   - Seamlessly falls back to streaming the backend PCM WAV audio `/api/v1/captcha/audio/{token}` if the client browser or device does not have TTS enabled.


