# SNTCSSC MIS — Comprehensive Library Management System REST API Integration Guide
### Multi-Platform Enterprise Implementation Guide (Mobile App, Web Portal, Desktop Circulation Kiosk & Turnstiles)

---

## 1. Executive Summary & System Overview

The **Library Management System (LMS)** within the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS** delivers an enterprise-grade, future-ready RESTful API designed to power:
- **Native Mobile Apps (iOS & Android via Flutter / React Native / Swift / Kotlin):** Self-service catalog discovery, personal digital library card (Code 128 barcode + QR code), loan status tracking, push notifications for due dates, and hold reservations.
- **Web Portals (Student & Faculty Portals in React, Vue, or Blade):** Public catalog exploration, syllabus-mapped reading recommendations, loan renewal requests, fine ledger review, and clearance certificate tracking.
- **Desktop Circulation Desks & Self-Service Kiosks (Electron / C# .NET / Tauri):** High-speed barcode / RFID scanner accessioning, fast touch-based checkout/checkin, reading room day-use visitor tracking, and receipt printing.
- **Campus Turnstiles & Access Control Gates (C# / Python IoT):** Gatekeeper clearance checks, digital badge scanning, and security deposit enforcement.

### Key Architectural Standards & Features:
1. **Tiered Security Deposit Engine:** Dynamically validates borrower eligibility against paid security deposits (or authorized waivers) before permitting checkout of expensive civil services reference books.
2. **In-House Reading Room Day-Use Facility:** Dedicated lightweight workflow that issues books and periodicals for in-library reading without debiting security deposit limits, bound to daily closing time.
3. **RFID & Barcode Multi-Identifier Resolvers:** Endpoints natively accept standard integer IDs, sequential accession numbers (`ACC-2026-0001`), Code 128 barcodes, or RFID EPC tags without requiring separate routes.
4. **Automated Overdue Fines & Waivers:** Computes daily overdue fines according to institutional policy, with support for partial payments, payment references, and audited administrative waivers.
5. **Official No-Dues Clearance & Refund Integration:** Seamlessly verifies zero outstanding loans and zero unpaid fines to issue verifiable clearance certificates for security deposit refunds.
6. **Enterprise Resilience:** Strict DB transaction boundaries (`DB::transaction`), soft delete preservation across all models (`deleted_at`, `deleted_by`), and comprehensive audit logging (`AuditLogService::log()`).

---

## 2. System Architecture & Sequence Diagrams

```mermaid
graph TD
    subgraph Multi-Platform Clients
        Mobile[Student & Staff Mobile App\nFlutter / React Native]
        WebPortal[Student Web Portal\nReact / Vue]
        Kiosk[Desktop Circulation Kiosk\nElectron / C# .NET]
        Turnstile[IoT Turnstile / RFID Gate\nPython / C#]
    end

    subgraph Security & API Gateway
        GW[API Gateway / Route Dispatcher]
        AuthGuard{Authentication Guard\nauth.api_key / Sanctum}
        ScopeCheck{Scope Authorizer\nread:library\nwrite:library\ndownload:library_cards}
    end

    subgraph Service Layer
        LibCtrl[LibraryApiController]
        CircSvc[BookCirculationService]
        CardSvc[LibraryCardService]
        ClearSvc[LibraryClearanceService]
        PolicySvc[LibraryPolicyService]
        DepositVal[LibrarySecurityDepositValidator]
    end

    subgraph Persistence & Audit
        DB[(MySQL Database\nInnoDB Transactions)]
        Audit[(Audit Logs Trail)]
        PDF[Barryvdh DomPDF\nLibrary Cards & Labels]
    end

    Mobile -->|Bearer Token / X-API-Key| GW
    WebPortal -->|Sanctum / X-API-Key| GW
    Kiosk -->|X-API-Key (read:library, write:library)| GW
    Turnstile -->|X-API-Key (read:library)| GW

    GW --> AuthGuard
    AuthGuard --> ScopeCheck
    ScopeCheck --> LibCtrl

    LibCtrl --> CircSvc
    LibCtrl --> CardSvc
    LibCtrl --> ClearSvc
    LibCtrl --> PolicySvc
    LibCtrl --> DepositVal

    CircSvc --> DB
    CircSvc --> Audit
    CardSvc --> PDF
    ClearSvc --> DB
    ClearSvc --> Audit
```

### 2.1. Physical Circulation: Rapid Barcode Checkout Flow

```mermaid
sequenceDiagram
    autonumber
    actor Staff as Librarian / Self-Checkout Kiosk
    participant Kiosk as Desktop Kiosk App (Electron / C#)
    participant API as Library REST API
    participant Svc as BookCirculationService
    participant Val as LibrarySecurityDepositValidator
    participant DB as Database (MySQL)

    Staff->>Kiosk: Scans Member Barcode / Digital Card
    Kiosk->>API: GET /api/v1/library/members/{identifier}
    API-->>Kiosk: Member profile, active loan count, security deposit status

    Staff->>Kiosk: Scans Book Barcode / RFID Tag
    Kiosk->>API: GET /api/v1/library/copies/{barcode}
    API-->>Kiosk: Copy details, condition, availability

    Staff->>Kiosk: Confirms Checkout
    Kiosk->>API: POST /api/v1/library/issues (copy_identifier, member_identifier)
    API->>Svc: issueBook(copy, member)
    Svc->>Val: validateForIssue(member, copy)
    Val->>Val: Verify loan quota & security deposit sufficiency
    Val-->>Svc: Allowed: true
    Svc->>DB: DB::transaction: Lock copy, create BookIssue, mark copy 'issued'
    Svc->>DB: Record AuditLog & AppNotification
    DB-->>API: BookIssue Record (ISS-2026-00042)
    API-->>Kiosk: 201 Created { success: true, data: { issue_no, due_date } }
    Kiosk-->>Staff: Beep confirmation & Print Loan Slip
```

### 2.2. In-House Reading Room Session Flow (Day-Use Facility)

```mermaid
sequenceDiagram
    autonumber
    actor Visitor as Reading Room Aspirant
    participant Desk as Reading Room Tablet / Terminal
    participant API as Library REST API
    participant Svc as BookCirculationService
    participant DB as Database (MySQL)

    Visitor->>Desk: Presents Student ID or signs in at Reception Desk
    Desk->>API: POST /api/v1/library/in-house/issue
    Note over API: copy_identifiers, reader_name, seat_no: "Desk 14", due_time: "17:00"
    API->>Svc: issueInHouseReading(copies, readerDetails)
    Note over Svc: Bypasses Security Deposit Check!
    Svc->>DB: Create BookIssue (type: in_house, due_date: today, session_token)
    Svc->>DB: Mark copies as 'in_house_reading'
    API-->>Desk: 201 Created { session_token: "INH-20261029-X7K9", count: 2 }

    Note over Visitor,Desk: Reading Session Underway...

    Visitor->>Desk: Returns books before leaving reading hall
    Desk->>API: POST /api/v1/library/in-house/return (session_token or issue_id)
    API->>Svc: returnInHouseSession(sessionToken)
    Svc->>DB: Mark BookIssue 'returned', mark copies 'available'
    API-->>Desk: 200 OK { returned_count: 2 }
    Desk-->>Visitor: Session closed successfully
```

### 2.3. No-Dues Clearance & Deposit Refund Flow

```mermaid
sequenceDiagram
    autonumber
    actor Student as Graduating Aspirant
    participant Portal as Student Mobile / Web App
    participant API as Library REST API
    participant ClearSvc as LibraryClearanceService
    participant DB as Database

    Student->>Portal: Navigates to "Library Clearance & Deposit Refund"
    Portal->>API: GET /api/v1/library/clearance/check/{memberId}
    API->>ClearSvc: checkEligibility(userId)
    ClearSvc->>DB: Verify active loans count == 0 && unpaid fines == 0
    ClearSvc-->>API: { eligible: true, active_loans_count: 0, unpaid_fines: 0.00 }
    API-->>Portal: Eligible for instant clearance

    actor Librarian as Authorized Librarian
    Librarian->>API: POST /api/v1/library/clearance/issue (deposit_id, remarks)
    API->>ClearSvc: issueClearanceCertificate(deposit, librarianId)
    ClearSvc->>DB: Generate Clearance Certificate No (CLR-2026-0012)
    ClearSvc->>DB: Update LibraryDeposit (clearance_status: 'cleared')
    ClearSvc->>DB: Dispatch AppNotification to student
    ClearSvc->>DB: Record AuditLog
    API-->>Librarian: 200 OK { clearance_issued: true, certificate_no: "CLR-2026-0012" }
```

---

## 3. Authentication & API Headers

### Supported Authentication Methods:
1. **API Key Authentication (Recommended for Integrations, Mobile Apps & Kiosks):**
   ```http
   X-API-Key: sntc_live_38bf8c8e62d47...
   ```
   *or*
   ```http
   Authorization: Bearer sntc_live_38bf8c8e62d47...
   ```
2. **Sanctum Session / Bearer Token (For authenticated students on official mobile/web portals):**
   ```http
   Authorization: Bearer <sanctum_personal_access_token>
   ```

### Standard HTTP Request Headers:
```http
Accept: application/json
Content-Type: application/json
X-API-Key: sntc_live_...
```

### Standard Response Envelope:
```json
{
  "success": true,
  "data": { ... },
  "meta": {
    "current_page": 1,
    "per_page": 20,
    "total": 142,
    "last_page": 8
  }
}
```

### Standard Error Response Envelope:
```json
{
  "success": false,
  "error": {
    "code": "SECURITY_DEPOSIT_EXCEEDED",
    "message": "Total price of issued books (₹1,500.00) exceeds your active security deposit of ₹1,000.00.",
    "details": { ... }
  }
}
```

---

## 4. Endpoints & Scopes Matrix

| HTTP Method | Endpoint Path | Required Scope | Target Platforms | Description |
|:---|:---|:---|:---|:---|
| **GET** | `/api/v1/library/dashboard` | `read:library` | Web / Admin / Kiosks | High-level library KPIs, active loans, overdue counts, fine totals |
| **GET** | `/api/v1/library/statistics` | `read:library` | Web / Analytics | Category breakdown, reading telemetry, top borrowed books |
| **GET** | `/api/v1/library/books` | `read:library` | Mobile / Web / Desktop | Search and paginate books catalog with author/category filters |
| **POST** | `/api/v1/library/books` | `write:library` | Web / Admin | Create new book catalog entry with multi-author associations |
| **GET** | `/api/v1/library/books/{identifier}` | `read:library` | Mobile / Web / Desktop | Single book dossier by ID, slug, or ISBN |
| **PUT** | `/api/v1/library/books/{identifier}` | `write:library` | Web / Admin | Update book metadata, DDC classification, pricing, reference mode |
| **DELETE** | `/api/v1/library/books/{identifier}` | `write:library` | Web / Admin | Soft delete book catalog title (prevented if copies are checked out) |
| **GET** | `/api/v1/library/books/{identifier}/copies` | `read:library` | Desktop / Kiosk | List all physical accession copies of a book |
| **POST** | `/api/v1/library/books/{identifier}/copies` | `write:library` | Desktop / Kiosk | Accession new physical copy with barcode, condition, shelf location |
| **GET** | `/api/v1/library/copies/{identifier}` | `read:library` | Desktop Scanner / Kiosk | Fast lookup by barcode, accession number, RFID tag, or ID |
| **POST** | `/api/v1/library/copies/{identifier}/status`| `write:library` | Desktop / Kiosk | Quick update copy status/condition with accession history log |
| **DELETE** | `/api/v1/library/copies/{identifier}` | `write:library` | Web / Admin | Soft delete physical copy |
| **GET** | `/api/v1/library/issues` | `read:library` | Web / Mobile / Kiosks | Query circulation loans with search, status, and overdue filters |
| **POST** | `/api/v1/library/issues` | `write:library` | Desktop / Kiosks | Checkout book copy to member with deposit verification & bypass |
| **POST** | `/api/v1/library/issues/{id}/return` | `write:library` | Desktop / Kiosks | Checkin returned book, assess overdue fines, fulfill holds queue |
| **POST** | `/api/v1/library/issues/{id}/renew` | `write:library` | Desktop / Kiosks | Librarian direct renewal of active loan |
| **POST** | `/api/v1/library/issues/{id}/request-renewal`| Member Auth / Key | Mobile / Web | Student/member submits loan renewal request |
| **POST** | `/api/v1/library/issues/{id}/review-renewal` | `write:library` | Web / Admin | Librarian approves or rejects loan renewal request |
| **POST** | `/api/v1/library/in-house/issue` | `write:library` | Desktop / Tablets | Issue copies for in-house reading room study session |
| **POST** | `/api/v1/library/in-house/return` | `write:library` | Desktop / Tablets | Return in-house copies or close reading room session token |
| **GET** | `/api/v1/library/reservations` | `read:library` | Web / Mobile | Query book reservations and waiting list queue |
| **POST** | `/api/v1/library/reservations` | `write:library` | Web / Mobile | Place hold reservation on book for member |
| **POST** | `/api/v1/library/reservations/{id}/cancel` | `write:library` | Web / Mobile | Cancel reservation and release held copy |
| **GET** | `/api/v1/library/fines` | `read:library` | Web / Admin | List overdue fines with pending/paid status filters |
| **POST** | `/api/v1/library/fines/{id}/pay` | `write:library` | Desktop / Kiosks | Record fine payment (cash, UPI, card, online transaction) |
| **POST** | `/api/v1/library/fines/{id}/waive` | `write:library` | Web / Admin | Authorize fine waiver with reason and audit log |
| **GET** | `/api/v1/library/members` | `read:library` | Web / Admin | List registered library members with active loan counts |
| **GET** | `/api/v1/library/members/{identifier}` | `read:library` | Mobile / Desktop / Gate | Member profile dossier, security deposit, fines, and clearances |
| **GET** | `/api/v1/library/clearance/check/{identifier}` | `read:library` | Mobile / Web | Check member clearance eligibility (loans count == 0, fines == 0) |
| **POST** | `/api/v1/library/clearance/issue` | `write:library` | Web / Admin | Issue official Library Clearance (No Dues) Certificate |
| **GET** | `/api/v1/library/members/{id}/card/download` | `download:library_cards` | Mobile / Web | Stream official printable PDF library card |
| **GET** | `/api/v1/library/categories` | `read:library` | Mobile / Web | Catalog book categories with DDC classifications |
| **POST** | `/api/v1/library/categories` | `write:library` | Web / Admin | Create book category |
| **GET** | `/api/v1/library/authors` | `read:library` | Mobile / Web | Catalog authors with book counts |
| **POST** | `/api/v1/library/authors` | `write:library` | Web / Admin | Create author |
| **GET** | `/api/v1/library/publishers` | `read:library` | Mobile / Web | Catalog publishers with contact information |
| **POST** | `/api/v1/library/publishers` | `write:library` | Web / Admin | Create publisher |
| **GET** | `/api/v1/library/catalog` | Optional Auth | Public / Mobile / Web | Public book catalog search |
| **GET** | `/api/v1/library/catalog/{id}` | Optional Auth | Public / Mobile / Web | Public book details and availability count |
| **GET** | `/api/v1/library/my-account` | Student Auth | Mobile / Web Portal | Student's library profile, deposit amount, active loans |
| **GET** | `/api/v1/library/my-loans` | Student Auth | Mobile / Web Portal | Student's active borrowed books with remaining days |
| **GET** | `/api/v1/library/my-history` | Student Auth | Mobile / Web Portal | Student's past borrowing history |
| **GET** | `/api/v1/library/my-reservations` | Student Auth | Mobile / Web Portal | Student's active holds and queue positions |
| **POST** | `/api/v1/library/my-reservations` | Student Auth | Mobile / Web Portal | Student self-reserve a book from catalog |
| **POST** | `/api/v1/library/my-loans/{id}/request-renewal` | Student Auth | Mobile / Web Portal | Student submit loan renewal request |
| **GET** | `/api/v1/library/my-card` | Student Auth | Mobile / Web Portal | Student download personal digital library card PDF |

---

## 5. Endpoints Reference & Request/Response Samples

### 5.1. Rapid Barcode / RFID Copy Lookup
Designed specifically for high-speed hardware barcode scanners and RFID turnstiles. Returns copy details, condition, shelf location, and active loan status in under 20ms.

- **URL:** `GET /api/v1/library/copies/{identifier}`
- **Identifier:** Accession Number (`ACC-2026-00042`), Barcode (`BC-LIB-9912`), RFID EPC (`E280116060...`), or Database ID.

```json
{
  "success": true,
  "data": {
    "copy": {
      "id": 142,
      "book_id": 88,
      "accession_no": "ACC-2026-00042",
      "barcode": "ACC-2026-00042",
      "rfid_tag": "E28011606000000000000001",
      "copy_number": 2,
      "shelf_location": "Row 4, Rack B, Shelf 2",
      "book_condition": "good",
      "status": "available",
      "book": {
        "id": 88,
        "title": "Indian Polity (7th Edition)",
        "isbn": "978-9355325075",
        "price": "895.00",
        "authors": [
          { "id": 12, "name": "M. Laxmikanth" }
        ],
        "category": { "id": 4, "name": "Polity & Governance" }
      }
    },
    "barcode_svg_uri": "data:image/svg+xml;base64,PHN2Zy...",
    "current_loan": null
  }
}
```

---

### 5.2. Checkout Book to Member
Issues a physical copy to an authorized member with security deposit limits validation.

- **URL:** `POST /api/v1/library/issues`
- **Scope:** `write:library`
- **Request Body:**
```json
{
  "copy_identifier": "ACC-2026-00042",
  "member_identifier": "LIB-2026-0081",
  "loan_days": 15,
  "bypass_deposit": false,
  "bypass_reason": null,
  "notes": "Issued at Main Circulation Desk"
}
```

- **Success Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "id": 512,
    "issue_no": "ISS-2026-00042",
    "book_id": 88,
    "book_copy_id": 142,
    "library_member_id": 81,
    "user_id": 120,
    "issue_date": "2026-10-29",
    "due_date": "2026-11-13",
    "status": "issued",
    "issue_type": "circulation",
    "renewal_count": 0,
    "max_renewals_allowed": 2,
    "book": { "title": "Indian Polity (7th Edition)" },
    "book_copy": { "accession_no": "ACC-2026-00042" },
    "library_member": { "name": "Priyanka Sen", "member_number": "LIB-2026-0081" }
  }
}
```

---

### 5.3. Checkin / Return Book Copy
Closes an active loan, computes overdue fines if applicable, updates copy condition, and checks if another member has placed a hold reservation on this book.

- **URL:** `POST /api/v1/library/issues/{id}/return`
- **Scope:** `write:library`
- **Request Body:**
```json
{
  "return_condition": "good",
  "notes": "Returned in pristine condition at drop-box."
}
```

- **Success Response (200 OK):**
```json
{
  "success": true,
  "data": {
    "issue": {
      "id": 512,
      "issue_no": "ISS-2026-00042",
      "status": "returned",
      "return_date": "2026-10-29",
      "return_condition": "good"
    },
    "fine": null,
    "overdue_days": 0
  }
}
```

---

### 5.4. In-House Reading Room Facility (Day Use)
Allows non-deposit members or reading room visitors to consult reference books inside the library premises for the day. Bypasses security deposit check and automatically binds due time to library closing time (e.g. 17:00).

- **URL:** `POST /api/v1/library/in-house/issue`
- **Scope:** `write:library`
- **Request Body:**
```json
{
  "copy_identifiers": ["ACC-2026-00018", "ACC-2026-00094"],
  "member_identifier": "LIB-2026-0081",
  "reader_name": "Rohan Mukherjee",
  "reader_phone": "9830012345",
  "seat_no": "Table 04",
  "due_time": "17:30",
  "notes": "Consulting Gazetteers and Atlas"
}
```

- **Success Response (201 Created):**
```json
{
  "success": true,
  "data": {
    "session_token": "INH-20261029-A9X2K",
    "issued_count": 2,
    "issues": [
      {
        "id": 513,
        "issue_no": "ISS-2026-00043",
        "inhouse_session_token": "INH-20261029-A9X2K",
        "reader_name": "Rohan Mukherjee",
        "reading_room_seat_no": "Table 04",
        "due_time": "17:30",
        "book": { "title": "Oxford Atlas for Civil Services" },
        "book_copy": { "accession_no": "ACC-2026-00018" }
      }
    ]
  }
}
```

---

### 5.5. No-Dues Clearance Eligibility Check
Verifies whether a student can be granted a library clearance certificate and have their security deposit refunded.

- **URL:** `GET /api/v1/library/clearance/check/{identifier}`
- **Scope:** `read:library`
- **Response (Eligible):**
```json
{
  "success": true,
  "data": {
    "eligible": true,
    "active_loans_count": 0,
    "active_loans": [],
    "unpaid_fines": 0.00,
    "member": { "id": 81, "name": "Priyanka Sen" },
    "blocking_reason": null
  }
}
```

---

## 6. Multi-Platform Integration Recipes & SDK Boilerplate

### 6.1. Flutter (Dart) — Student Mobile App Client
Implements digital library badge display with live barcode, book catalog browsing, personal loan due date monitoring, and one-tap renewal requests.

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

class SntcsscLibraryClient {
  final String baseUrl;
  final String apiKey;

  SntcsscLibraryClient({
    this.baseUrl = 'https://mis.sntcssc.in/api/v1',
    required this.apiKey,
  });

  Map<String, String> get _headers => {
        'X-API-Key': apiKey,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      };

  /// 1. Student Personal Library Account
  Future<Map<String, dynamic>> getMyAccount() async {
    final response = await http.get(
      Uri.parse('$baseUrl/library/my-account'),
      headers: _headers,
    );
    final json = jsonDecode(response.body);
    if (json['success'] == true) {
      return json['data'];
    }
    throw Exception(json['error']['message'] ?? 'Failed to load library account');
  }

  /// 2. Active Loans with Days Remaining
  Future<List<dynamic>> getMyLoans() async {
    final response = await http.get(
      Uri.parse('$baseUrl/library/my-loans'),
      headers: _headers,
    );
    final json = jsonDecode(response.body);
    return json['data'] ?? [];
  }

  /// 3. Request Book Renewal
  Future<Map<String, dynamic>> requestRenewal(int issueId, {String? reason}) async {
    final response = await http.post(
      Uri.parse('$baseUrl/library/my-loans/$issueId/request-renewal'),
      headers: _headers,
      body: jsonEncode({'reason': reason ?? 'Exam preparation ongoing'}),
    );
    return jsonDecode(response.body);
  }

  /// 4. Book Catalog Search
  Future<List<dynamic>> searchCatalog({String? query, int? categoryId, int page = 1}) async {
    final queryParams = {
      if (query != null && query.isNotEmpty) 'search': query,
      if (categoryId != null) 'category_id': categoryId.toString(),
      'page': page.toString(),
    };
    final uri = Uri.parse('$baseUrl/library/catalog').replace(queryParameters: queryParams);
    final response = await http.get(uri, headers: _headers);
    final json = jsonDecode(response.body);
    return json['data'] ?? [];
  }

  /// 5. Reserve Book
  Future<Map<String, dynamic>> reserveBook(int bookId) async {
    final response = await http.post(
      Uri.parse('$baseUrl/library/my-reservations'),
      headers: _headers,
      body: jsonEncode({'book_id': bookId}),
    );
    return jsonDecode(response.body);
  }
}
```

---

### 6.2. Electron / Node.js — Desktop Circulation Desk & Kiosk
Implements high-speed USB/HID barcode reader input, accessioning, and thermal receipt printing on staff circulation desks.

```javascript
// circulation-desk-client.js
const axios = require('axios');

class LibraryDeskController {
  constructor(apiKey, baseUrl = 'https://mis.sntcssc.in/api/v1') {
    this.client = axios.create({
      baseURL: baseUrl,
      headers: {
        'X-API-Key': apiKey,
        'Accept': 'application/json',
        'Content-Type': 'application/json',
      },
      timeout: 8000,
    });
  }

  // 1. Fast barcode scanner scan handler
  async onBarcodeScanned(scannedString) {
    const trimmed = scannedString.trim();

    // Member Card scanned (e.g. LIB-2026-...)
    if (trimmed.startsWith('LIB-')) {
      const res = await this.client.get(`/library/members/${trimmed}`);
      return { type: 'member', data: res.data.data };
    }

    // Physical Book Copy scanned (e.g. ACC-...)
    const copyRes = await this.client.get(`/library/copies/${trimmed}`);
    return { type: 'copy', data: copyRes.data.data };
  }

  // 2. Fast Checkout
  async checkout(copyIdentifier, memberIdentifier, loanDays = 15) {
    try {
      const res = await this.client.post('/library/issues', {
        copy_identifier: copyIdentifier,
        member_identifier: memberIdentifier,
        loan_days: loanDays,
      });
      return { success: true, issue: res.data.data };
    } catch (err) {
      const msg = err.response?.data?.error?.message || err.message;
      return { success: false, error: msg };
    }
  }

  // 3. Fast Return
  async checkin(copyIdentifier, condition = 'good') {
    // First lookup copy to find active loan
    const copyRes = await this.client.get(`/library/copies/${copyIdentifier}`);
    const loan = copyRes.data.data.current_loan;
    if (!loan) {
      throw new Error(`Copy ${copyIdentifier} is not currently checked out.`);
    }

    const res = await this.client.post(`/library/issues/${loan.issue_id}/return`, {
      return_condition: condition,
    });
    return res.data.data;
  }
}

module.exports = LibraryDeskController;
```

---

### 6.3. C# (.NET 8/9) — RFID Campus Turnstile & Gatekeeper
Integrates high-frequency RFID gates with the SNTCSSC library backend to monitor entry permissions, overdue holds, and prevent unauthorized removal of cataloged volumes.

```csharp
using System;
using System.Net.Http;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

public class SntcsscLibraryGatekeeper
{
    private readonly HttpClient _httpClient;
    private const string BaseUrl = "https://mis.sntcssc.in/api/v1";

    public SntcsscLibraryGatekeeper(string apiKey)
    {
        _httpClient = new HttpClient();
        _httpClient.DefaultRequestHeaders.Add("X-API-Key", apiKey);
        _httpClient.DefaultRequestHeaders.Add("Accept", "application/json");
    }

    // 1. Verify Member at Gate Turnstile
    public async Task<bool> VerifyGateAccessAsync(string memberNumber)
    {
        var response = await _httpClient.GetAsync($"{BaseUrl}/library/members/{memberNumber}");
        if (!response.IsSuccessStatusCode) return false;

        var content = await response.Content.ReadAsStringAsync();
        using var doc = JsonDocument.Parse(content);
        var memberStatus = doc.RootElement.GetProperty("data").GetProperty("member").GetProperty("status").GetString();

        return memberStatus == "active";
    }

    // 2. RFID EAS Anti-Theft Tag Check at Library Exit Gate
    public async Task<bool> CheckBookDischargedAsync(string rfidTag)
    {
        var response = await _httpClient.GetAsync($"{BaseUrl}/library/copies/{rfidTag}");
        if (!response.IsSuccessStatusCode) return false;

        var content = await response.Content.ReadAsStringAsync();
        using var doc = JsonDocument.Parse(content);
        var copyStatus = doc.RootElement.GetProperty("data").GetProperty("copy").GetProperty("status").GetString();

        // If status is 'issued', the book has been properly checked out at desk.
        // If status is still 'available', alarm should trigger!
        return copyStatus == "issued";
    }
}
```

---

### 6.4. Python — Automated Inventory & Overdue Reminder Script
Enables library IT staff to script batch accession imports, generate inventory reconciliations, or synchronize with university union catalogs.

```python
import requests

BASE_URL = "https://mis.sntcssc.in/api/v1"
API_KEY = "sntc_live_YOUR_KEY"

session = requests.Session()
session.headers.update({
    "X-API-Key": API_KEY,
    "Accept": "application/json",
    "Content-Type": "application/json"
})

def fetch_overdue_loans():
    """Fetch all active library loans currently past due date."""
    response = session.get(f"{BASE_URL}/library/issues", params={"overdue_only": 1, "per_page": 50})
    issues = response.json().get("data", [])
    print(f"Total Overdue Loans: {len(issues)}")
    for issue in issues:
        print(f"Issue #{issue['issue_no']} | Book: {issue['book']['title']} | Member: {issue['library_member']['name']} | Due: {issue['due_date']}")

def record_fine_payment(fine_id: int, amount: float, method="upi", reference="UPI-REF-0982"):
    """Record fine payment for returned overdue book."""
    payload = {
        "amount": amount,
        "payment_method": method,
        "payment_reference": reference,
        "notes": "Paid at library reception kiosk"
    }
    res = session.post(f"{BASE_URL}/library/fines/{fine_id}/pay", json=payload)
    return res.json()

if __name__ == "__main__":
    fetch_overdue_loans()
```

---

### 6.5. cURL Terminal Commands
Essential command-line snippets for rapid verification and smoke testing.

```bash
# 1. Executive Dashboard
curl -X GET "https://mis.sntcssc.in/api/v1/library/dashboard" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 2. Fast Barcode Lookup
curl -X GET "https://mis.sntcssc.in/api/v1/library/copies/ACC-2026-00042" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 3. Checkout Book to Member
curl -X POST "https://mis.sntcssc.in/api/v1/library/issues" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "copy_identifier": "ACC-2026-00042",
    "member_identifier": "LIB-2026-0081",
    "loan_days": 15
  }'

# 4. Return Book
curl -X POST "https://mis.sntcssc.in/api/v1/library/issues/512/return" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{"return_condition": "good"}'

# 5. In-House Reading Room Checkout
curl -X POST "https://mis.sntcssc.in/api/v1/library/in-house/issue" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Content-Type: application/json" \
  -d '{
    "copy_identifiers": ["ACC-2026-00018"],
    "reader_name": "Siddharth Roy",
    "seat_no": "Table 02"
  }'

# 6. Check No-Dues Clearance Eligibility
curl -X GET "https://mis.sntcssc.in/api/v1/library/clearance/check/LIB-2026-0081" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  -H "Accept: application/json"

# 7. Download Printable PDF Library Card
curl -X GET "https://mis.sntcssc.in/api/v1/library/members/LIB-2026-0081/card/download" \
  -H "X-API-Key: sntc_live_YOUR_KEY" \
  --output library_card.pdf
```

---

## 7. Security, Exception Handling & Best Practices

1. **Transactional Integrity:** Every book issue, return, status transition, fine payment, and clearance certificate generation is strictly enclosed in a database transaction (`DB::transaction`). If any step fails (e.g. deposit limit breach, copy already issued), all state mutations are cleanly rolled back.
2. **Soft Deletion & Auditability:** Books, copies, issues, reservations, and fines are protected by soft deletes. Deleted records retain `deleted_by` user attribution and are excluded from active circulation queries.
3. **Optimistic Locking:** When issuing or returning books, `BookCopy::lockForUpdate()` is utilized to eliminate race conditions between multiple desk terminals checking out the same copy simultaneously.
4. **Offline Mobile Caching:** Mobile clients should store book catalog records in local SQLite / Hive storage and query `/api/v1/library/catalog?updated_since=...` to minimize cellular data overhead.
5. **Physical Barcode Standards:** Accession barcodes adhere to standard **Code 128**, readable by all standard USB / Bluetooth 1D/2D optical barcode guns and smartphone camera lenses without special drivers.

