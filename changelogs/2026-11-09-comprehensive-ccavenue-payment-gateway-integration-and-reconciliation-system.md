# Comprehensive CCAvenue Payment Gateway Integration, Gateway Surcharge Reconciliation, Interrupted Transaction Recovery & Refund Engine

**Date:** 2026-11-09  
**Type:** Feature Integration, Financial Architecture & Enterprise Security  
**Scope:** CCAvenue Gateway (AES-128-CBC), Surcharge Accounting, Dropped Transaction Sync, Offline Pre-Flight Checks, 6-Month Billing Penalty Fallback, Admin Manual Dispute Settlement & Refund Engine

---

## 1. Executive Summary

Based on the official **CCAvenue Web Integration Kit (PHP)**, **CCAvenue Integration Document**, and **CC Avenue API Implementation Guide** (located at `docs/CCAvenue/`), this release delivers a complete, production-grade, enterprise-hardened payment gateway integration tailored for Satyendranath Tagore Civil Services Study Centre (SNTCSSC).

### Core Problem Solved:
1. **Gateway Surcharges vs. Institutional Fee Demands**: When students pay using Credit Cards, Debit Cards, or Net Banking, payment gateways frequently levy transaction surcharges/taxes (e.g., student is charged ₹1,023.60 for a ₹1,000.00 tuition fee). In legacy setups, this discrepancy causes payments to be rejected as "Amount Mismatch" or mistakenly credits the excess processing surcharge to the tuition invoice. The new architecture accurately bifurcates `gateway_charged_amount`, `gateway_fee`, `gateway_tax`, and base `amount`—crediting the exact demanded institutional fee (₹1,000.00) in the student ledger while logging gateway costs. Conversely, any payment authorized for *less* than demanded is quarantined into an `under_review` state with `amount_mismatch = true` and administrative alerts.
2. **Dropped / Interrupted Transactions & Failure Recovery**: Users refreshing pages, hitting browser back buttons, losing internet connectivity mid-checkout, or encountering callback dropouts are now fully safeguarded through:
   - Client-side pre-flight internet stability checks (`navigator.onLine`) with interactive modal dialogs before initiating payments.
   - An automated background console sync worker (`payments:sync-ccavenue-pending`) scheduled every 30 minutes that queries CCAvenue's `orderStatusTracker` API.
   - A dedicated "Verify / Check Status" action on the student dashboard for any unconfirmed transaction, preventing duplicate charges.
   - Concurrency locks preventing students from initiating multiple payments against the same invoice within a 15-minute window.
3. **CCAvenue 6-Month Billing Penalty Protection**: Per official CCAvenue mandate, disabling the "Billing Information" step on the checkout screen requires that the merchant backend **must** always transmit billing country (`billing_country`), contact number (`billing_tel`), and email ID (`billing_email`) with every request. Failure to do so causes CCAvenue to forcefully re-enable the billing step for 6 months without notice. The gateway service now enforces this mandate with system-level fallback defaults (Address: Kolkata, Zip: 700106, City: Kolkata, State: West Bengal, Country: India) whenever student profiles are incomplete.
4. **Administrative Reconciliation & Refund Engine**: Finance administrators can inspect live transaction records, execute on-demand status synchronization via CCAvenue's Order Status Tracker API, manually override unconfirmed statuses, and issue full or partial refunds (via CCAvenue Refund API or manual voucher) with automated student ledger debiting and fee invoice balance reopening.

---

## 2. Key Architectural Changes & File Manifest

### 2.1 Database Schema Migration
- **Migration:** `database/migrations/2026_11_09_203000_add_ccavenue_gateway_fields_and_refunds_to_payment_transactions.php`
- **New Columns on `payment_transactions`:**
  - `gateway_charged_amount` (decimal:2, nullable): Total amount debited by CCAvenue from customer.
  - `gateway_fee` (decimal:2, default: 0.00): Gateway convenience fees and surcharges.
  - `gateway_tax` (decimal:2, default: 0.00): GST/service tax on gateway fee.
  - `gateway_bank_ref_no` (string:100, nullable): Bank authorization/reference number.
  - `gateway_tracking_id` (string:100, nullable, indexed): CCAvenue unique reference number.
  - `gateway_payment_mode` (string:50, nullable): e.g. Credit Card, Debit Card, Net Banking, UPI.
  - `gateway_card_name` (string:50, nullable): e.g. Visa, MasterCard, Rupay, HDFC Bank.
  - `amount_mismatch` (boolean, default: false, indexed): True if charged amount < demanded fee.
  - `amount_mismatch_notes` (text, nullable): Audit notes detailing discrepancy.
  - `refund_amount` (decimal:2, default: 0.00): Cumulative refunded amount.
  - `refund_reason` (text, nullable): Administrative or student reason for refund.
  - `refund_reference_no` (string:100, nullable, indexed): Gateway refund reference or internal voucher.
  - `refund_status` (string:30, nullable): `pending`, `processed`, `failed`.
  - `refunded_at` (datetime, nullable): Timestamp of refund execution.
  - `refunded_by` (foreignId to `users`, nullable): Administrator who authorized the refund.
  - `billing_country` (string:60, default: 'India'): Country sent to CCAvenue.
  - `billing_tel` (string:30, nullable): Customer telephone sent to CCAvenue.
  - `billing_email` (string:100, nullable): Customer email sent to CCAvenue.

### 2.2 Payment Gateway Service (`CCAVenueGateway.php`)
- **Location:** `app/Services/Payment/Gateways/CCAVenueGateway.php`
- **Encryption:** AES-128-CBC matching CCAvenue's official PHP Integration Kit (`pack('C*', 0x00...0x0f)` IV and `hex2bin(md5($workingKey))`).
- **`createOrder()`:** Injects mandatory customer contact and billing parameters with configurable fallbacks to guarantee 100% compliance with CCAvenue's bank validation mandate.
- **`verifyPayment()`:** Decrypts encrypted callback payload (`encResp`), parses order status (`Success`, `Aborted`, `Failure`), normalizes fees (`trans_fee`, `service_tax`), and checks for underpayment mismatches.
- **`queryOrderStatus()`:** Submits JSON command `orderStatusTracker` to `https://apitest.ccavenue.com/apis/servlet/DoWebTrans` (or live equivalent) to query real-time gateway status for abandoned or dropped sessions.
- **`refundOrder()`:** Submits JSON command `refundOrder` to issue online refunds directly into the student's originating card or bank account.

### 2.3 Financial Service (`FeePaymentService.php`)
- **Location:** `app/Services/Payment/FeePaymentService.php`
- **`completeOnlinePaymentWithCharges()`:** Settle transactions with surcharge breakdown; credits institutional base fee in double-entry student ledger (`StudentLedger::REF_PAYMENT`), marks invoice paid, generates official receipt (`REC-2026-XXXXXX`), and logs audit trail.
- **`handleAmountMismatch()`:** Sets status to `under_review`, flags `amount_mismatch = true`, logs discrepancy audit trail, and notifies student and accounts desk.
- **`updatePaymentStatus()`:** Allows administrators to manually reconcile unconfirmed payments (e.g. verified on CCAvenue portal) with database transactions and ledger settlement.
- **`refundPayment()`:** Issues full or partial refunds, debits student ledger with `StudentLedger::REF_REFUND` to balance liability, reopens invoice balance, and audits refund action.
- **`syncWithCCAvenue()`:** Connects to CCAvenue Order Status Tracker and updates local transaction status accordingly (`Shipped`/`Successful` -> paid, `Failure`/`Aborted` -> failed).

### 2.4 CCAvenue Web Controller & Routes
- **Controller:** `app/Http/Controllers/Payment/CCAVenueController.php`
- **Routes:**
  - `GET /payments/ccavenue/redirect/{transaction}` -> `payments.ccavenue.redirect`
  - `POST /payments/ccavenue/response` -> `payments.ccavenue.response`
  - `POST /payments/ccavenue/cancel` -> `payments.ccavenue.cancel`
- **CSRF Exemption:** Added `'payments/ccavenue/*'` and `'api/v1/payments/ccavenue/*'` to `$middleware->validateCsrfTokens(except: [...])` in `bootstrap/app.php` to receive cross-site POST callbacks from CCAvenue.
- **Redirect View:** `resources/views/payments/ccavenue-redirect.blade.php`: Auto-submitting secure POST checkout form with PCI-DSS 256-bit encryption indicator and client-side offline check.

### 2.5 Automated Sync Worker & Scheduler
- **Artisan Command:** `app/Console/Commands/SyncPendingCCAVenueTransactions.php` (`payments:sync-ccavenue-pending`)
- **Schedule:** Registered in `routes/console.php` to run every 30 minutes without overlapping (`->everyThirtyMinutes()->withoutOverlapping()`). Reconciles pending transactions older than 10 minutes.

### 2.6 Admin Payment Settings
- **Location:** `resources/views/pages/admin/settings/⚡payment.blade.php`
- **Features:**
  - Checkout page "Billing Information Step" toggle (`payment.ccavenue_billing_page_enabled`).
  - Prominent red alert banner citing the 6-month penalty rule.
  - Configurable default fallback billing address fields (Address: Salt Lake Sector V, Zip: 700106, City: Kolkata, State: West Bengal, Country: India, Phone: 9876543210, Email: accounts@sntcssc.in).
  - Working Key AES-128 Encryption Self-Test Diagnostic Tool.

### 2.7 Admin Transactions Management
- **Location:** `resources/views/pages/admin/payments/⚡transactions.blade.php`
- **Features:**
  - Transactions Register with status filters (`successful`, `under_review`, `amount_mismatch`, `pending`, `failed`, `refunded`).
  - Transaction detail inspection modal displaying raw decrypted gateway JSON payload, fee breakdowns, and IP/user agent.
  - One-click "Live Sync" button querying CCAvenue Order Status Tracker API.
  - Manual Status Update modal with reference details and admin audit remarks.
  - Interactive Refund modal supporting both online CCAvenue API refund and manual voucher recording.

### 2.8 Student Payments Portal & Library Deposit
- **Locations:**
  - `resources/views/pages/student/⚡payments.blade.php`
  - `resources/views/pages/student/⚡library-deposit.blade.php`
- **Features:**
  - Real-time offline detection banner (`navigator.onLine`) notifying students when connection drops.
  - Interactive modal dialog preventing checkout submission while offline.
  - Pending Gateway Verification card for dropped/interrupted transactions with "Verify / Check Status" and "Cancel Attempt" buttons.
  - Concurrency checks preventing double payment against the same invoice or active attempt within 15 minutes.
  - Transaction history badges displaying cleared, under review, amount mismatch, and refunded states.

### 2.9 Omnichannel Headless REST API Endpoints (`PaymentApiController.php` & `routes/api.php`)
- **Controller:** `app/Http/Controllers/Api/PaymentApiController.php`
- **Endpoints Registered:**
  - `POST /api/v1/payments/ccavenue/initiate`: Omnichannel checkout initiation for Mobile (WebView/Flutter), Web SPA, or Desktop POS clients with encrypted request and mandatory billing defaults.
  - `POST /api/v1/payments/ccavenue/callback`: Decrypts and reconciles AES-128-CBC gateway responses, automatically bifurcating gateway fees or flagging underpayment mismatches.
  - `POST /api/v1/payments/ccavenue/sync-status`: Real-time order status query against CCAvenue Order Status Tracker API for dropped or pending transactions.
  - `GET /api/v1/payments/gateways/ccavenue/config`: Returns public gateway configuration, test/live mode, 6-month penalty warning, and fallback billing address defaults.
  - `POST /api/v1/payments/gateways/ccavenue/test-encryption`: Diagnostic AES-128-CBC encryption and decryption test using configured merchant keys.
  - `POST /api/v1/payments/transactions/{identifier}/refund`: Programmatic full/partial refund with student ledger debit and invoice balance reopening (requires `payments.refund` permission).
  - `POST /api/v1/payments/transactions/{identifier}/reconcile`: Administrative manual reconciliation and audit status override with tracking ID and bank reference (requires `payments.reconcile` permission).

### 2.10 Multi-Platform Integration Guide & Developer Console (`docs/integrations/` & `⚡docs.blade.php`)
- **Documentation Guide:** `docs/integrations/ccavenue-payment-gateway-api.md`:
  - Architecture overview & sequence flow (Mermaid diagram).
  - Flutter / React Native Mobile App integration blueprint with headless WebView code snippet.
  - Desktop POS Kiosk (C# .NET / Electron) integration blueprint.
  - Complete REST API Reference with parameters table, curl snippets, and sample JSON request/responses.
  - Security & Production checklist.
- **Developer Console Integration:** `resources/views/pages/admin/api-keys/⚡docs.blade.php`:
  - Registered `openGuide('ccavenue')` modal viewer that loads and renders the markdown guide directly in the admin panel.
  - Expanded `endpointsList()` with all 7 new endpoints under the `payments` scope (updating category count to 50 endpoints).
  - Integrated interactive sandbox tester presets auto-filling sample JSON payloads upon clicking CCAvenue and Refund endpoints.
  - Added dedicated **CCAvenue Payment Gateway & Surcharge API Guide Card** in the Developer Console guides grid.

### 2.11 Interactive Modals for Interrupted Transactions & Gateway Diagnostics
Replaced all browser native alerts and unconfirmed button actions with custom, accessible, responsive interactive modal dialogs across student and admin portals:
- **Student Fees & Payments Portal (`⚡payments.blade.php`):**
  - **Verify / Check Status Interactive Modal:** Displays active pending transaction metadata, provides a live "Query Gateway Tracker Now" button with spinner state, queries CCAvenue Order Status Tracker in real-time, displays formatted gateway diagnostic responses, and on confirmation immediately renders a "Download Receipt (PDF)" action.
  - **Cancel Pending Payment Attempt Confirmation Modal:** Prominently asks *"Has money been deducted from your bank?"* with an amber alert box explaining that if bank/UPI debits occurred, cancelling will close the session. Provides a one-click *"Check Status Instead"* pivot button alongside the destructive *"Yes, Cancel Attempt"* action to eliminate accidental double deductions.
  - **Amount Mismatch / Under Review Informative Modal:** Clickable info pill explaining why a transaction is flagged under review, clarifying gateway surcharge adjustments or banking holds, and assuring students that duplicate payments are unnecessary.
- **Student Library Security Deposit Portal (`⚡library-deposit.blade.php`):**
  - Integrated a dedicated "Pending Gateway Verification" alert card for interrupted security deposit attempts.
  - Added matching Verify Transaction Status and Cancel Pending Deposit Attempt confirmation modals with UTR bank debit warnings.
- **Admin Transactions Management Portal (`⚡transactions.blade.php`):**
  - **CCAvenue Order Status Tracker & Reconciliation Modal:** Accessible via table row action button. Allows administrators to query CCAvenue's official status tracker API directly from the MIS UI, inspect real-time gateway response payloads, and automatically settle student ledgers and receipts upon verified completion.

### 2.12 Tri-Lingual Localization Updates (`lang/en.json`, `lang/bn.json`, `lang/hi.json`)
Added complete, context-aware translations in English, Bengali, and Hindi for all 35 newly introduced UI strings (modal titles, critical bank debit warnings, status tracker prompts, under-review explanations, and button labels).

### 2.13 Bug Fix: Library Security Deposit Portal SQL Exception & Student Accessibility Fix
- **Root Cause of SQLSTATE[42S22] (1054 Unknown column 'payment_category'):** In `resources/views/pages/student/⚡library-deposit.blade.php`, the query evaluating pending gateway transactions attempted to filter by `$q->orWhere('payment_category', 'library')`. However, the `payment_transactions` database table does not contain a `payment_category` column (categories belong to the related `fee_types` model). This triggered a fatal 500 `QueryException` whenever a student visited `/student/library-deposit`.
- **Database Query Resolution:** Refactored the query to inspect valid schema relationships:
  - Fetched all library fee type IDs via `FeeType::where('code', 'library_security')->orWhere('category', 'library')->pluck('id')`.
  - Conditioned transaction queries on `$q->whereIn('fee_type_id', $libraryFeeTypeIds)->orWhereHas('libraryDeposit')`.
- **Student Portal Accessibility Enhancements:**
  - Added `'student.library-deposit.*'` and `'*/student/library-deposit*'` to `EnsureStudentProfileComplete.php` route exclusions to ensure students with pending profile sections are never blocked from accessing the security deposit portal.
  - Added a top-level fallback redirect in `routes/web.php` for `student/{path?}` that routes students to their team-scoped URL (`/{team}/student/{path}`) if accessed without a team prefix.
  - Verified that impersonated students and authorized students have full access without permission locks.

---

## 3. Verification & Automated Test Suite

A comprehensive Pest feature test suite was authored and executed:

### 3.1 Web & Core Integration Suite (`CCAVenueIntegrationTest.php`):
1. `ccavenue encryption and decryption works with AES-128-CBC and PKCS5 padding` -> **PASSED**
2. `ccavenue createOrder enforces mandatory billing info and fallbacks to protect against 6-month penalty rule` -> **PASSED**
3. `ccavenue handles gateway fees and surcharge differences without failing valid payments` -> **PASSED**
4. `ccavenue flags amount mismatch when gateway charged amount is less than demanded` -> **PASSED**
5. `admin can reconcile and manually update unconfirmed payment status with ledger settlement` -> **PASSED**
6. `admin can refund an unintentional duplicate payment with ledger debit adjustment` -> **PASSED**
7. `ccavenue response controller processes successful encrypted gateway callback` -> **PASSED**
8. `duplicate payment prevention stops user from initiating second payment on pending or paid invoices` -> **PASSED**
9. `payments:sync-ccavenue-pending artisan command checks and reconciles dropped transactions` -> **PASSED**

### 3.2 Omnichannel REST API Suite (`CCAVenueApiTest.php`):
1. `can retrieve ccavenue public config and billing policies via api` -> **PASSED**
2. `can test ccavenue aes encryption handshake via api` -> **PASSED**
3. `can initiate ccavenue checkout for student invoice via api` -> **PASSED**
4. `can process successful ccavenue callback via api with surcharge recording` -> **PASSED**
5. `detects amount mismatch on underpayment callback via api` -> **PASSED**
6. `handles aborted or cancelled payment callback via api` -> **PASSED**
7. `can sync transaction status with ccavenue tracker via api` -> **PASSED**
8. `can refund transaction via api with role permission` -> **PASSED**
9. `unauthorized user cannot refund transaction via api` -> **PASSED**
10. `can reconcile transaction status via api` -> **PASSED**
11. `developer console docs component can open ccavenue integration guide` -> **PASSED**

### Overall Test Suite Results:
- `tests/Feature/CCAVenueApiTest.php`: **11 passed, 44 assertions** (100% pass rate).
- `tests/Feature/CCAVenueIntegrationTest.php`: **9 passed, 71 assertions** (100% pass rate).
- `tests/Feature/PaymentAndFeeCollectionTest.php` & `tests/Feature/PaymentApiTest.php`: **51 passed, 401 assertions** (100% pass rate).
- `tests/Feature/ApiKeyManagementTest.php`: **22 passed, 114 assertions** (100% pass rate).
- **Total Payment & Integration Assertions Verified:** **630 assertions passing with 0 failures**.
- **Code Quality:** All modified PHP files styled and formatted with `vendor/bin/pint --format agent`.

