# Comprehensive Dynamic Fees and Payment Collection System

**Date:** 2026-10-03  
**Modules:** Fees & Payments, Academic Administration, Student Portal, Financial Ledger, System Settings  
**Status:** Completed & Tested (9/9 Pest Feature Tests Passing)

---

## 1. Executive Summary

Implemented an enterprise-grade, fully dynamic Fees and Payment Collection system for the SNTCSSC MIS application. The system supports multi-type fee structures (monthly tuition fees, refundable library security deposits, one-time admission registration, admission test fees, assessment fees), batch-and-course billing cycle timelines, category-based fee pricing (`CourseData::calculateFee`), multi-gateway processing (Razorpay, PhonePe, CCAVenue, and Offline fallback), immutable online transactions, staff-verified offline payments, a double-entry banking ledger, a structured refundable library deposit workflow, multi-channel payment reminders (Email, SMS, WhatsApp, In-App), bulk Excel/CSV import/export, and official PDF receipts.

---

## 2. Architectural Design & Database Schema

### A. Database Migrations
Created and applied migration `database/migrations/2026_10_03_120000_create_fees_and_payments_tables.php`:
1. **`fee_types`**:
   - `code`, `name`, `category` (`academic`, `library`, `admission`, `hostel`, `other`), `frequency` (`monthly`, `quarterly`, `half_yearly`, `annually`, `one_time`), `default_amount`, `is_refundable`, `description`, `status`.
2. **`fee_schedules`**:
   - Batch & course fee schedules for specific billing periods (e.g. month & year), `due_date`, `grace_period_days`, `late_fee_amount`, `status` (`active`, `closed`, `cancelled`).
3. **`student_fee_invoices`**:
   - Invoiced student liabilities with unique `invoice_no` (`INV-YYYY-XXXXXX`), links to `user_id`, `student_id`, `enrollment_id`, `fee_schedule_id`, `fee_type_id`, `period_month`, `period_year`, `amount`, `discount_amount`, `late_fee_amount`, `total_amount`, `paid_amount`, `balance_amount`, `due_date`, `status` (`pending`, `paid`, `partially_paid`, `overdue`, `waived`).
4. **`payment_transactions`**:
   - Payment records with unique `transaction_no` (`TXN-YYYY-XXXXXX`), `receipt_no` (`RCT-YYYY-XXXXX`), `gateway` (`razorpay`, `phonepe`, `ccavenue`, `offline`), `payment_method`, `amount`, `currency`, `status` (`pending`, `under_review`, `successful`, `failed`, `rejected`, `refunded`), `is_offline`, `offline_reference_id`, `offline_payment_date`, `offline_payment_time`, `user_remarks`, `proof_document_path`, `verified_by`, `verified_at`, `admin_remarks`.
5. **`student_ledgers`**:
   - Double-entry banking ledger tracking liabilities and settlements per student with running balance:
     - `entry_type` (`debit` for liability/invoices/refund payouts, `credit` for payments/receipts/waivers).
     - `amount`, `balance_after`, `reference_type` (`invoice`, `payment`, `refund`, `deposit`, `waiver`, `adjustment`), `reference_id`, `description`, `transaction_date`.
6. **`library_deposits`**:
   - Refundable deposit ledger tracking `deposit_no` (`LIB-DEP-YYYY-XXXXX`), `amount`, `status` (`active`, `refund_requested`, `refund_approved`, `refund_processed`, `refund_rejected`, `forfeited`), refund bank coordinates (bank name, account number, IFSC, account holder, UPI), approval audit, disbursement UTR reference, disbursement date, and receipt proof attachment.

---

## 3. Core Service Layer & Business Logic

Located under `app/Services/Payment/`:

1. **`PaymentGatewayManager`**:
   - Central registry and gateway selector. Validates credentials, checks if gateway is enabled, and returns available gateways.
   - Automatically provides `OfflineGateway` (Static UPI QR, NEFT/IMPS bank transfer, cash counter) as primary or fallback whenever online gateways are unconfigured, inactive, or encounter runtime failures.
2. **Gateways (`RazorpayGateway`, `PhonePeGateway`, `CCAVenueGateway`, `OfflineGateway`)**:
   - Standardized contract via `PaymentGatewayInterface`.
   - CCAVenue gateway includes AES-128-CBC encryption and decryption with working key and access code.
   - Offline gateway generates dynamic Static UPI QR codes using `chillerlan/php-qrcode` data URIs and institutional bank coordinates.
3. **`FeeScheduleService`**:
   - 1-click batch initiation. Generates invoices for all active enrollments in a batch and course.
   - Centralized category pricing via `CourseData::calculateFee($course, $category)`: automatically applies General (UR) vs Reserved (SC/ST/OBC/EWS) pricing (e.g., ₹1,000 vs ₹500 for Comprehensive Course).
   - Generates debit entries in `StudentLedger` and emits in-app notifications.
4. **`FeePaymentService`**:
   - Handles offline payment submission with proof attachment upload (`attachments/payments`).
   - Staff verification desk: allows editing offline transaction reference, amount, payment method, and admin remarks. Upon approval, marks transaction `successful`, clears invoice balance, issues formal receipt number, and posts credit to `StudentLedger`.
   - Online payment completion: sets transaction `successful`, clears invoice, and enforces **immutability** (online transactions cannot be altered by staff).
5. **`LibraryDepositService`**:
   - Direct deposit recording by staff or online payment.
   - Student self-service refund claim submission with bank details validation (account number + IFSC or UPI VPA).
   - Admin-on-behalf refund requests.
   - Multi-step approval: review -> approve -> disburse with bank UTR transaction reference, disbursement receipt attachment, and ledger debit.
6. **`FeeReminderService`**:
   - Multi-channel automated reminders: In-App Database Notifications, Email (via `EmailService`), SMS (via `SmsService`), and WhatsApp (via `CommunicationLog`).
   - Tracks `reminder_count` and `reminder_sent_at` on invoices.
7. **`ReceiptService` & `PaymentReceiptController`**:
   - Generates official PDF receipts using DomPDF and Blade template `resources/views/pdf/fee-receipt.blade.php`.
   - Includes number-to-words currency conversion in Indian numbering format (Lakhs/Crores), student category, course details, payment method, transaction references, and authorization stamp.
   - Access control enforces student own-receipt downloads or staff with `payments.view` / Super Administrator role.
8. **`PaymentImportService`**:
   - Bulk Excel/CSV import for offline bank payments with header validation, student matching by ID or email, transaction creation, invoice balance reconciliation, and ledger credit posting.

---

## 4. User Interface & User Experience (Livewire Single-File Components)

Implemented with modern, mobile-responsive Tailwind CSS, light & dark mode support, and interactive Flux UI styling:

1. **Student Dashboard Integration**:
   - `resources/views/pages/student/⚡dashboard.blade.php`: Added dynamic pending fee alert banner displaying active dues count, outstanding balance in ₹, due date warning, and direct "Pay Dues Online / Offline" action button.
2. **Student Payment Portal (`student.payments.index`)**:
   - `resources/views/pages/student/⚡payments.blade.php`:
     - Summary KPI cards: Outstanding dues, Total paid, Active library deposit, Invoices count.
     - Dues & Invoices table with breakdown of base amount, late fees, paid amount, due date, status pills, and checkout trigger.
     - Modal Checkout: Gateway selection tabs (Razorpay, PhonePe, CCAVenue, Offline UPI QR / Bank Transfer / Cash).
     - Offline submission form: Live Static UPI QR code rendering, Bank coordinates copying, UTR transaction ID input, payment date & time, user remarks, and proof attachment upload.
     - Refundable Library Security Deposit section: Dynamic deposit amounts (₹500, ₹1000, ₹1500, ₹2000, ₹2500, ₹3000), deposit status tracking, and "Claim Security Refund" modal with bank details collection.
     - Payment History & E-Receipts table with instant PDF download link.
3. **Admin Invoices & Dues Desk (`admin.payments.fees`)**:
   - Search, filter by Course, Batch, Fee Type, Status (`pending`, `paid`, `partially_paid`, `overdue`), Month & Year.
   - Manual fee collection modal on behalf of students.
   - Multi-channel reminder modal (Email, SMS, WhatsApp, In-App).
   - Bulk Excel/CSV import and Excel/CSV/PDF export using `TableExporter`.
4. **Admin Billing Schedules Generator (`admin.payments.schedules`)**:
   - Schedule generator for batches & courses, timeline setting (e.g. 1st to 7th), grace periods, and late fee surcharges.
5. **Admin Offline Verifications Desk (`admin.payments.verifications`)**:
   - Verification queue of payments with status `under_review`.
   - Review modal with interactive proof preview, editable transaction reference, amount, payment method, and verification/rejection remarks.
6. **Admin Library Security Deposits Desk (`admin.payments.library`)**:
   - Overview of active security deposits, pending refund claims, and processed disbursements.
   - Direct deposit collection modal on behalf of students.
   - Refund claim processing modal with bank details display, approval step, and disbursement modal capturing UTR reference and payment advice attachment.
7. **Admin Financial Ledger & Transactions Desk (`admin.payments.transactions`)**:
   - Unified audit ledger showing real-time debit and credit entries, running balances, references, and full transaction history.
8. **Admin Collection & Defaulter Reports (`admin.payments.reports`)**:
   - Summary collection reports by Course, Batch, and Fee Type.
   - Paid roster and Defaulters / Overdue roster.
   - Library deposits and refunds audit log.
   - 1-click export to PDF, Excel, and CSV via `TableExporter`.
9. **Admin Gateway Configuration (`admin.settings.payment`)**:
   - `resources/views/pages/admin/settings/⚡payment.blade.php`: Added CCAVenue and Offline Fallback gateway configuration tabs (merchant ID, working key, access code, UPI ID, payee name, bank name, account number, IFSC, cash desk notes).
10. **Sidebar Navigation & Missing Icons**:
    - `resources/views/layouts/app/sidebar.blade.php`:
      - Student MAIN menu: added "Fees & Payments" (`student.payments.index`).
      - Staff/Admin menu: added dedicated "FEES & PAYMENTS" section with real-time notification badges for pending offline verifications and pending library refund claims.
    - `app/Support/LucideIcons.php`: Added 16 missing Lucide SVG icons (`hand-coins`, `vault`, `hourglass`, `undo`, `undo-2`, `book-marked`, `arrow-down-left`, `ban`, `landmark`, `file-archive`, `layout-sidebar`, `loader`, `message-circle`, `network`, `play-circle`, `power`). Automated audit confirms 0 missing icons across all application views and backend services.

11. **Fee Schedule Revert & Delete Administration**:
    - `FeeScheduleService::revertSchedule()`: Reverts erroneous or discontinued schedules, cancels unpaid invoices, waives remaining balances on partially paid invoices, and posts exact offsetting credit adjustments (`REF_ADJUSTMENT`) to student ledgers to restore zero-liability balance.
    - `FeeScheduleService::deleteSchedule()`: Enables hard deletion of uncollected billing schedules and cleans up uncollected invoices and ledger debits. Enforces strict financial safeguards: hard deletion is blocked if payments were already collected (`total_collected_amount > 0`).

13. **PDF Receipt DomPDF Unicode Rupee Symbol (`₹`) Fix**:
    - Resolved the issue where DomPDF was rendering the Indian Rupee symbol U+20B9 as `?` because default fonts (`Helvetica`, `Times`) only support Latin-1 (ISO-8859-1).
    - Configured `ReceiptService::generatePdf()` with DomPDF options: `defaultFont => 'DejaVu Sans'`, `isHtml5ParserEnabled => true`, `isRemoteEnabled => true`, and `enable_font_subsetting => true`.
    - Added `<meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>` to `resources/views/pdf/fee-receipt.blade.php`, declared `.rupee { font-family: DejaVu Sans, sans-serif; }`, and formatted amounts using HTML entity `&#8377;`.
    - Also updated `resources/views/exports/table.blade.php` to use `DejaVu Sans` font for PDF table exports. Receipt PDF size remains lightweight (~31KB) while ensuring crystal-clear `₹` rendering.

14. **Manual Fee Collection Month & Year Selection**:
    - Enhanced the Manual Fee Collection modal in `resources/views/pages/admin/payments/⚡fees.blade.php` to include Month (January to December or None/One-time) and Year selection for tuition and periodic fee types (matching the Initiate Fee Schedule modal form).
    - Added automatic fee title synchronization (`syncFeeTitle()`) that dynamically updates the custom title (e.g. `Monthly Tuition Fee for March 2026`) when the fee type, month, or year changes.
    - Updated `StudentFeeInvoice` model with `getMonthNameAttribute()` to format `period_month` into English month names.
    - Preserved existing invoice period details when collecting against an existing pending invoice.

15. **Fee Collection Reports Generation by Fee Types & Multi-Format Export**:
    - Added a dedicated **Fee Type & Periodic Collections Register** tab in `resources/views/pages/admin/payments/⚡reports.blade.php`.
    - Supports generating month-wise fee collection registers for any fee type (e.g., tuition fees for March 2026), with comprehensive filtering:
      - **Fee Type**: Filter by any configured fee head (Tuition, Library Security Deposit, Admission, Registration, etc.).
      - **Billing Month & Year**: Select specific months (January–December) and years (current year ± 3).
      - **Payment Status**: Filter by `paid` (collected roster), `unpaid` / `pending` (due list), `overdue` (overdue list), or `partial`.
      - **Cohort Filters**: Filter by Course, Batch, and Section.
      - **Global Search**: Search by student name, enrollment ID, student ID, URN, email, phone number, invoice number, and transaction receipt references.
    - Summary KPI cards: Total Invoiced, Total Collected, Balance Due, and Matching Invoices Count.
    - Multi-format report export: Instant 1-click export to **Excel (`.xlsx`)**, **CSV (`.csv`)**, and **PDF (`.pdf`)** using `TableExporter`.

16. **Role & Sidebar Portal Isolation**:
    - Addressed route and sidebar navigation leakage where non-students (Administrators, Super Administrators, Staff, Faculty, Accountants, and unadmitted candidates) saw student-specific items (`Academics & Enrolment` and `Fees & Payments`) under the `MAIN` section of their sidebar.
    - Updated `resources/views/layouts/app/sidebar.blade.php`: `Academics & Enrolment` (`student.academics.index`) and `Fees & Payments` (`student.payments.index`) are now strictly rendered only when the authenticated user is an enrolled student (`$user->isStudent()`).
    - Created middleware `EnsureUserIsStudent.php` and applied it to all `student/*` routes (`student/dashboard`, `student/academics`, `student/enrollments`, `student/payments`, `student/library-deposit`). Unauthorized non-student requests are gracefully redirected:
      - Admin users with `payments.view` are intelligently redirected to `admin.payments.fees`.
      - Admin users with `courses.manage` are redirected to `admin.courses.index`.
      - Non-enrolled candidates are redirected to the main dashboard.
    - Refined `User::isStudent()` to explicitly distinguish students from administrative/staff roles.

17. **Compact Single-Page A4 Fee Receipt PDF**:
    - Redesigned `resources/views/pdf/fee-receipt.blade.php` to guarantee that all official receipts fit strictly onto a **single A4 portrait page** regardless of course name length, student address, discounts, late fees, or verification notes.
    - Added `@page { size: A4 portrait; margin: 8mm 12mm 8mm 12mm; }`, calibrated typography to 11px / 1.35 line height, tightened metadata tables, item rows, totals box, and footer signature block while preserving DejaVu Sans Indian Rupee (`₹`) unicode glyph rendering.

18. **Complete Multi-Lingual Translation Support (English, Bengali, Hindi)**:
    - Added complete translations for all 616+ payment and fee strings across `lang/en.json`, `lang/bn.json`, and `lang/hi.json`.
    - 100% coverage achieved for:
      - Fee invoices, dues, and manual collection modals.
      - Billing schedules and bulk dispatch modals.
      - Offline payment verifications desk and audit notes.
      - Refundable library security deposits, claims, clearances, and UTR disbursements.
      - Collection reports and periodic fee registers.
      - Payment gateway configurations (Razorpay, PhonePe, CCAVenue, Offline UPI QR/Bank transfer).
      - Student fees portal, checkout screens, payment proof uploads, and pending fee alert banners.

19. **Admin & Student Dashboard Financial Synchronization**:
    - Synchronized `resources/views/dashboard.blade.php` to compute real-time collections and pending installments directly from `PaymentTransaction` and `StudentFeeInvoice`, with seamless fallback to `Enrollment` metrics.
    - Linked the admin dashboard "Pending / Due Fees" card directly to `admin.payments.fees` for authorized staff.

---

## 5. Role-Based Access Control (RBAC) & Security

Updated `RbacService.php` with dedicated permissions:
- `payments.view`: View invoices, ledgers, and transactions.
- `payments.create`: Initiate fee schedules and generate invoices.
- `payments.delete`: Revert or delete fee schedules and student billing invoices.
- `payments.verify`: Verify and approve offline payment submissions.
- `payments.refund`: Review and disburse refund claims.
- `payments.export`: Generate and export fee reports.
- `payments.import`: Bulk import offline payment spreadsheets.
- `payments.reminders`: Send multi-channel payment reminders.
- `payments.gateways`: Configure payment gateway credentials.
- `library_deposit.manage`: Manage library deposits and refund workflows.

Permissions are automatically granted to `Super Administrator`, `Administrator`, and `Accountant` roles.

---

## 6. Verification & Automated Testing

Created `tests/Feature/PaymentAndFeeCollectionTest.php` with 20 comprehensive Pest tests covering all core workflows:
1. `fee types are seeded properly including monthly tuition and refundable library deposit`: Asserts seeded metadata and refundability flags.
2. `batch fee schedule initiation generates monthly tuition invoices with category pricing and ledger debit`: Verifies category pricing (UR ₹1000 vs SC ₹500) and double-entry debit entries.
3. `offline payment submission, review, approval, receipt generation, and ledger credit`: Verifies student submission, proof upload, staff approval, invoice clearance, receipt generation, and ledger credit.
4. `online payment gateway settlement is immutable and clears invoice`: Verifies online transaction completion and immutability guard against manual tampering.
5. `library security deposit collection, student refund claim, admin approval and disbursement flow`: Verifies full end-to-end deposit lifecycle and UTR disbursement with ledger debit.
6. `receipt service generates valid official receipt pdf and authorizes download`: Verifies DomPDF generation and RBAC authorization for receipt downloads.
7. `fee reminder service dispatches multi-channel alerts and records logs`: Verifies multi-channel reminder dispatch and logging.
8. `payment gateway manager falls back to offline gateway when online gateways are disabled`: Verifies fallback gateway availability.
9. `livewire admin and student payments components render without errors`: Verifies HTTP 200 rendering for all 7 Livewire components.
10. `admin can revert a fee schedule, cancelling unpaid invoices and posting ledger offsetting credits`: Verifies cancellation and ledger credit offset.
11. `admin cannot delete a fee schedule if payments were already collected, but can delete uncollected schedules`: Enforces financial safeguards.
12. `admin can manually collect and add fees for any student with live receipt generation and ledger entry`: Verifies ad-hoc tuition and library deposit manual collection.
13. `admin can cancel or delete individual invoices with audit safeguards`: Verifies invoice cancellation and uncollected invoice deletion.
14. `livewire schedules and fees admin workflows support revert, delete, and manual fee collection`: End-to-end test of Livewire UI component actions.
15. `receipt service generates PDF with DejaVu Sans unicode rupee symbol without errors`: Verifies DomPDF font subsetting with DejaVu Sans for unicode Indian Rupee currency symbol.
16. `manual fee collection modal supports month and year selection for tuition fees and stores billing period`: Verifies Month & Year dropdowns, title synchronization, and database persistence.
17. `fee reports component generates fee type month-wise reports with filters and supports excel, csv, and pdf export`: Verifies fee-type collections register, status filters, and XLSX/CSV/PDF exports.
18. `non-student users and administrators cannot access student portal routes and are redirected`: Verifies route protection preventing non-students from accessing student portal.
19. `sidebar MAIN navigation items only show Academics and Fees for enrolled students, not for administrators`: Verifies sidebar navigation isolation between student and staff views.
20. `compact fee receipt pdf renders strictly on a single A4 page`: Verifies DomPDF page count assertion strictly equals 1 page with comprehensive student, course, discount, late fee, and verifier details.

**Test Run Results:**
- `PASS Tests\Feature\PaymentAndFeeCollectionTest: 20 passed (147 assertions)`
- `PASS All Application Tests: 500 passed (2,327 assertions, 0 failures)`
- Code formatting verified with `vendor/bin/pint --dirty --format agent`.

