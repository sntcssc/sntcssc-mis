# SNTCSSC MIS — Examination Management REST API Integration Guide
### Multi-Platform Implementation & Integration Guide (Invigilator Mobile Scanner, Student Portal, Examination Center Kiosks & Third-Party Evaluation Systems)

---

## 1. Executive Summary & Architecture Overview

The **Examination Management Module** of the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS** provides a comprehensive RESTful API surface to handle all stages of institutional civil services examinations, mock test series, and entrance evaluations. Whether developing a **Mobile Attendance Scanner App** for invigilators (Flutter / React Native), a **Student Mobile App / Web Portal** (React / Vue), a **Desktop Examination Center Kiosk** (Electron / C# .NET), or an **External Evaluation / OMR Integration**, this API delivers secure, decoupled, and real-time operational control.

### Key Capabilities:
1. **End-to-End Examination Lifecycle:** Create, schedule, configure registration windows, set category fees, and publish mock prelims or entrance tests.
2. **Venues & Classroom Seating Management:** Register examination centers across Kolkata and West Bengal districts, configure multi-floor classrooms/halls with capacities, and generate live visual seating plans.
3. **Automated Seat Allocation & Roll Numbering:** Map registered candidates to centers and rooms with sequential roll and seat numbers.
4. **Invigilator Mobile App & QR Attendance:** On exam day, invigilators scan candidate admit card QR tokens using a tablet/smartphone to verify authenticity and record attendance (`present`, `absent`, `disqualified`) in real time.
5. **Tamper-Proof Digital Admit Cards:** Generate and stream student admit cards complete with institutional seals, venue directions, reporting times, and encrypted verification QR payloads.
6. **Results & Merit Ranking Engine:** Bulk upload evaluated marks, generate merit ranks, and update qualifying statuses.

---

## 2. Architecture & Sequence Flows

```mermaid
graph TD
    subgraph Multi-Platform Clients
        InvigApp[Invigilator Mobile App\nFlutter / React Native QR Scanner]
        StudentApp[Student Mobile / Web Portal\nAdmit Card & Exam Schedules]
        CenterKiosk[Exam Center Display Kiosk\nElectron / Python Seating Board]
        AdminPanel[Admin Web Dashboard\nExamination Controller Panel]
    end

    subgraph Security Layer
        GW[API Gateway / Route Engine]
        AuthCheck{Auth Strategy\nAPI Key / Sanctum}
        ScopeCheck{Check Scopes\nread:examinations\nwrite:examinations\ndownload:admit_cards}
    end

    subgraph Core Services
        ExamCtrl[ExamApiController]
        ExamSvc[ExamService]
        AuditSvc[AuditLogService]
    end

    subgraph Storage & Database
        DB[(PostgreSQL / MySQL / SQLite)]
        Disk[(Encrypted Storage)]
    end

    InvigApp -->|X-API-Key / Bearer Token| GW
    StudentApp -->|Sanctum / X-API-Key| GW
    CenterKiosk -->|X-API-Key| GW
    AdminPanel -->|Session / API Key| GW

    GW --> AuthCheck
    AuthCheck --> ScopeCheck
    ScopeCheck --> ExamCtrl

    ExamCtrl --> ExamSvc
    ExamSvc --> DB
    ExamSvc --> AuditSvc
```

### 2.1. Exam Scheduling & Automated Seating Allocation Flow

```mermaid
sequenceDiagram
    autonumber
    actor Controller as Exam Controller / Admin
    participant App as Web / Desktop Console
    participant API as MIS Examination API (/api/v1/examinations)
    participant DB as Database

    Controller->>App: Creates Examination (CSCEE-2027) & Maps 3 Centers
    App->>API: POST /examinations {exam_code, exam_date, center_ids}
    API->>DB: Insert Exam & ExamCenterMappings
    API-->>App: 201 Created (exam_id: 1)

    Controller->>App: Triggers "Allocate Seats" for 200 Registered Candidates
    App->>API: POST /examinations/1/allocate-seats {center_id: 1, candidates: [...]}
    API->>DB: Assign sequential roll numbers (e.g. CSCEE-27-KOL-0001) & seats (Hall-A-S001)
    API->>DB: Generate unique 32-character admit_card_token for each candidate
    API-->>App: 200 OK (allocated_count: 200)

    Controller->>App: Views Seating Plan
    App->>API: GET /examinations/1/seating-plan
    API-->>App: 200 OK (Centers -> Rooms -> Allocated vs Vacant matrix)
```

### 2.2. Exam Day: Invigilator QR Verification & Attendance Flow

```mermaid
sequenceDiagram
    autonumber
    actor Candidate as Civil Services Aspirant
    actor Invigilator as Exam Hall Invigilator
    participant Mobile as Invigilator Mobile App (Flutter/RN)
    participant API as MIS Examination API
    participant DB as Database

    Candidate->>Invigilator: Presents Digital or Printed Admit Card
    Invigilator->>Mobile: Opens "Scan Hall Ticket" Camera Scanner
    Mobile->>Mobile: Decodes QR Code Payload (contains roll_number & admit_card_token)
    Mobile->>API: POST /examinations/1/attendance {roll_number, attendance_status: "present"}
    API->>DB: Verify candidate & update attendance_status="present", marked_at=NOW()
    API-->>Mobile: 200 OK (Candidate verified: Name, Roll No, Seat No, Photo Match)
    Mobile-->>Invigilator: Green Haptic Feedback & Display Assigned Seat
    Invigilator-->>Candidate: Directs student to designated desk
```

---

## 3. Authentication, Base URLs & Scopes Reference

### 3.1. Base URLs
- **Production Base:** `https://mis.sntcssc.in/api/v1`
- **Local / Staging Base:** `http://localhost:8000/api/v1`

### 3.2. Authentication Headers
Include the assigned secret API key in request headers:
```http
X-API-Key: sntc_live_YOUR_ASSIGNED_SECRET_KEY
```
or via standard Bearer token:
```http
Authorization: Bearer sntc_live_YOUR_ASSIGNED_SECRET_KEY
```

### 3.3. Examination Scopes Matrix
| Scope Name | Access Privilege | Permitted Operations |
|---|---|---|
| `read:examinations` | Read Access | View dashboard statistics, exam schedules, examination centers, seating plans, candidates list |
| `write:examinations` | Administrative Write | Create/update exams, register centers/rooms, bulk allocate seats, mark attendance, record marks |
| `download:admit_cards` | Admit Card Stream | Generate, download, and stream official admit cards and QR validation tokens |

---

## 4. Complete RESTful API Endpoints Reference

### 4.1. Dashboard & Examination Management

#### `GET /api/v1/examinations/dashboard`
Aggregated operational metrics for executive dashboards.
- **Required Scope:** `read:examinations`
- **Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "metrics": {
      "total_exams": 6,
      "upcoming_exams": 2,
      "total_centers": 8,
      "total_center_capacity": 1850,
      "total_candidates": 1420,
      "present_candidates": 1180,
      "absent_candidates": 45,
      "attendance_rate": 83.1
    },
    "recent_exams": [
      {
        "id": 1,
        "uuid": "3a1c8f42-4f18-472e-9a1b-e55d610b001a",
        "exam_code": "CSCEE-2027",
        "exam_name": "Civil Services Coaching Entrance Examination 2027",
        "exam_date": "2026-12-15",
        "status": "published",
        "candidates_count": 500,
        "centers_count": 3
      }
    ]
  }
}
```

---

#### `GET /api/v1/examinations`
Paginated directory of examinations.
- **Required Scope:** `read:examinations`
- **Query Parameters:**
  - `page` (integer, default: 1)
  - `per_page` (integer, default: 15, max: 100)
  - `status` (string, optional: `draft`, `published`, `registration_closed`, `completed`, `cancelled`)
  - `search` (string, optional): Search by exam name or exam code.
  - `upcoming` (boolean, optional): Return only exams with `exam_date >= today`.
- **Response `200 OK`:** Paginated array of `ExamResource`.

---

#### `POST /api/v1/examinations`
Schedule a new examination.
- **Required Scope:** `write:examinations`
- **Request Body (JSON):**
```json
{
  "exam_code": "CSCEE-2027",
  "exam_name": "Civil Services Coaching Entrance Examination 2027",
  "academic_year": "2026-2027",
  "conducting_body": "Satyendranath Tagore Civil Services Study Centre",
  "notification_date": "2026-10-15",
  "apply_start": "2026-10-20",
  "apply_end": "2026-11-20",
  "exam_date": "2026-12-15",
  "exam_time": "11:00 AM - 01:00 PM",
  "reporting_time": "10:00 AM",
  "duration_minutes": 120,
  "fee_general": 100.00,
  "fee_reserved": 50.00,
  "total_vacancies": 200,
  "status": "published",
  "instructions": "Two hours objective paper covering GS Paper I and CSAT.",
  "admit_card_instructions": "Must bring Government Photo ID along with printed Admit Card.",
  "admit_card_release_date": "2026-12-01",
  "center_ids": [1, 2, 3]
}
```
- **Response `201 Created`:** Full `ExamDetailResource`.

---

#### `GET /api/v1/examinations/{id}`
Retrieve detailed examination dossier.
- **Required Scope:** `read:examinations`
- **Response `200 OK`:** Full `ExamDetailResource`.

---

#### `PUT /api/v1/examinations/{id}`
Update an examination.
- **Required Scope:** `write:examinations`
- **Response `200 OK`:** Updated `ExamDetailResource`.

---

#### `DELETE /api/v1/examinations/{id}`
Archive or delete an examination schedule.
- **Required Scope:** `write:examinations`
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "Examination archived successfully."
}
```

---

### 4.2. Examination Centers & Room Venues

#### `GET /api/v1/examinations/centers`
List examination venues across districts.
- **Required Scope:** `read:examinations`
- **Query Parameters:** `city`, `district`, `is_active`, `per_page`.
- **Response `200 OK`:** Paginated array of `ExaminationCenterResource`.

---

#### `POST /api/v1/examinations/centers`
Register a new examination center.
- **Required Scope:** `write:examinations`
- **Request Body (JSON):**
```json
{
  "code": "KOL-SL-01",
  "name": "SNTCSSC Salt Lake Main Campus",
  "address": "Block FC, Sector III, Salt Lake City",
  "city": "Kolkata",
  "district": "North 24 Parganas",
  "state": "West Bengal",
  "pincode": "700106",
  "contact_person": "Prof. Debabrata Sen",
  "contact_phone": "+91 33 2337 4000",
  "contact_email": "saltlake.exams@sntcssc.in",
  "total_capacity": 120,
  "is_active": true
}
```
- **Response `201 Created`:** Created center object.

---

#### `POST /api/v1/examinations/centers/{id}/rooms`
Add a classroom or hall to an examination center.
- **Required Scope:** `write:examinations`
- **Request Body (JSON):**
```json
{
  "room_number": "Hall-A",
  "floor": "Ground Floor",
  "building_name": "Vidya Bhavan",
  "capacity": 60,
  "is_active": true
}
```
- **Response `201 Created`:** Created room object with updated total center capacity.

---

#### `DELETE /api/v1/examinations/centers/{centerId}/rooms/{roomId}`
Remove an examination hall or room from a center. Blocked by database integrity if active candidate seat allocations are mapped to this room.
- **Required Scope:** `write:examinations`
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "Examination hall / room deleted successfully."
}
```

---

#### `POST /api/v1/examinations/verify-admit-card`
Fast verification endpoint for mobile invigilator scanner devices and entry gate barcode readers. Validates Admit Card token or Roll Number and returns candidate biodata and assigned hall/seat.
- **Required Scope:** `read:examinations`
- **Request Body (JSON):**
```json
{
  "token": "a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6"
}
```
- **Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "is_valid": true,
    "candidate": {
      "id": 101,
      "name": "Arunava Dasgupta",
      "roll_number": "CSCEE-27-KOL-0001",
      "seat_number": "Hall-A-S001",
      "attendance_status": "present"
    },
    "examination": {
      "exam_code": "CSCEE-2027",
      "exam_name": "Civil Services Coaching Entrance Examination 2027",
      "exam_date": "2026-12-15",
      "exam_time": "11:00 AM - 01:00 PM"
    },
    "venue": {
      "center_name": "SNTCSSC Salt Lake Main Campus",
      "room_number": "Hall-A",
      "floor": "Ground Floor"
    }
  }
}
```

---

### 4.3. Seating Arrangements, Attendance & Grading

#### `GET /api/v1/examinations/{id}/seating-plan`
Real-time visual room and seat allocation board.
- **Required Scope:** `read:examinations`
- **Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "exam": {
      "id": 1,
      "exam_code": "CSCEE-2027",
      "exam_name": "Civil Services Coaching Entrance Examination 2027",
      "exam_date": "2026-12-15",
      "exam_time": "11:00 AM - 01:00 PM",
      "reporting_time": "10:00 AM"
    },
    "summary": {
      "total_centers": 2,
      "total_capacity": 200,
      "total_assigned_seats": 180,
      "total_vacant_seats": 20
    },
    "centers": [
      {
        "id": 1,
        "name": "SNTCSSC Salt Lake Main Campus",
        "code": "KOL-SL-01",
        "city": "Kolkata",
        "total_capacity": 120,
        "assigned_candidates": 120,
        "vacant_seats": 0,
        "rooms": [
          {
            "id": 1,
            "room_number": "Hall-A",
            "floor": "Ground Floor",
            "capacity": 60,
            "assigned_candidates": 60,
            "vacant_seats": 0
          }
        ]
      }
    ]
  }
}
```

---

#### `POST /api/v1/examinations/{id}/allocate-seats`
Bulk seat assignment and roll number generation.
- **Required Scope:** `write:examinations`
- **Request Body (JSON):**
```json
{
  "center_id": 1,
  "room_id": 1,
  "candidates": [
    {
      "candidate_name": "Arunava Dasgupta",
      "candidate_email": "arunava.dasgupta@example.com",
      "candidate_phone": "+91 98301 22334",
      "student_id": 101,
      "roll_number": "CSCEE-27-KOL-0001",
      "seat_number": "Hall-A-S001"
    }
  ]
}
```
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "Allocated 1 candidate(s) successfully.",
  "data": {
    "exam_id": 1,
    "center_id": 1,
    "allocated_count": 1
  }
}
```

---

#### `GET /api/v1/examinations/{id}/candidates`
Search and list scheduled candidates.
- **Required Scope:** `read:examinations`
- **Query Parameters:** `search`, `attendance_status`, `center_id`, `per_page`.
- **Response `200 OK`:** Paginated array of `ExamCandidateResource`.

---

#### `POST /api/v1/examinations/{id}/attendance`
Record candidate attendance on exam day (supports tablet/invigilator scanner).
- **Required Scope:** `write:examinations`
- **Request Body (JSON):**
```json
{
  "roll_number": "CSCEE-27-KOL-0001",
  "attendance_status": "present",
  "remarks": "Verified with original EPIC card."
}
```
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "Attendance recorded successfully.",
  "data": {
    "id": 1,
    "roll_number": "CSCEE-27-KOL-0001",
    "candidate_name": "Arunava Dasgupta",
    "attendance_status": "present",
    "attendance_marked_at": "2026-12-15T10:15:30.000000Z",
    "seat_number": "Hall-A-S001"
  }
}
```

---

#### `POST /api/v1/examinations/{id}/results`
Record marks and qualification ranks.
- **Required Scope:** `write:examinations`
- **Request Body (JSON):**
```json
{
  "results": [
    {
      "roll_number": "CSCEE-27-KOL-0001",
      "marks_obtained": 142.50,
      "rank": 1,
      "is_qualified": true,
      "remarks": "Selected for Batch A Coaching"
    }
  ]
}
```
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "Results updated for 1 candidate(s)."
}
```

---

### 4.4. Student Self-Service & Digital Admit Card

#### `GET /api/v1/examinations/my-exams`
Retrieve upcoming examinations for the authenticated student.
- **Required Scope:** `read:examinations` or logged in Bearer student session.
- **Response `200 OK`:** Array of `ExamCandidateResource`.

---

#### `GET /api/v1/examinations/{id}/admit-card`
Fetch complete official admit card dataset with QR code verification payload.
- **Required Scope:** `download:admit_cards` (or matching candidate token).
- **Query Parameters:** `roll_number` or `token`.
- **Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "institute": {
      "name": "Satyendranath Tagore Civil Services Study Centre",
      "authority": "Government of West Bengal",
      "address": "NSOU Campus, Salt Lake City, Kolkata - 700064"
    },
    "candidate": {
      "name": "Arunava Dasgupta",
      "roll_number": "CSCEE-27-KOL-0001",
      "email": "arunava.dasgupta@example.com",
      "phone": "+91 98301 22334",
      "seat_number": "Hall-A-S001",
      "admit_token": "a1b2c3d4e5f67890123456789abcdef0",
      "verification_qr_payload": "{\"exam_code\":\"CSCEE-2027\",\"roll_number\":\"CSCEE-27-KOL-0001\",\"token\":\"a1b2c3d4e5f67890123456789abcdef0\"}"
    },
    "examination": {
      "exam_code": "CSCEE-2027",
      "exam_name": "Civil Services Coaching Entrance Examination 2027",
      "exam_date": "Monday, December 15, 2026",
      "exam_time": "11:00 AM - 01:00 PM",
      "reporting_time": "10:00 AM",
      "duration_minutes": 120
    },
    "venue": {
      "center_name": "SNTCSSC Salt Lake Main Campus",
      "center_code": "KOL-SL-01",
      "address": "Block FC, Sector III, Salt Lake City, Kolkata - 700106",
      "room_number": "Hall-A",
      "floor": "Ground Floor"
    },
    "instructions": [
      "Candidates must carry this Admit Card along with an original Photo Identity Proof.",
      "Electronic gadgets, mobile phones, and calculators are strictly prohibited.",
      "Candidates must report at designated venue by reporting time."
    ]
  }
}
```

---

## 5. Multi-Platform SDK Integration Recipes

### 5.1. Flutter (Dart) — Invigilator QR Scanner App

```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

class SntcsscExamInvigilatorClient {
  final String baseUrl;
  final String apiKey;

  SntcsscExamInvigilatorClient({
    this.baseUrl = 'https://mis.sntcssc.in/api/v1',
    required this.apiKey,
  });

  // Process Scanned QR Code and Mark Attendance in 1 Click
  Future<Map<String, dynamic>> verifyAndMarkAttendance({
    required int examId,
    required String qrPayloadRaw,
  }) async {
    // 1. Decode QR payload
    final qrData = jsonDecode(qrPayloadRaw);
    final String rollNumber = qrData['roll_number'];

    // 2. Submit to MIS API
    final response = await http.post(
      Uri.parse('$baseUrl/examinations/$examId/attendance'),
      headers: {
        'X-API-Key': apiKey,
        'Content-Type': 'application/json',
        'Accept': 'application/json',
      },
      body: jsonEncode({
        'roll_number': rollNumber,
        'attendance_status': 'present',
        'remarks': 'Verified via Mobile QR Scanner',
      }),
    );

    if (response.statusCode == 200) {
      final result = jsonDecode(response.body);
      return result['data']; // Contains seat_number, room, and candidate_name
    }
    throw Exception('Attendance marking failed: ${response.body}');
  }
}
```

---

### 5.2. React Native (TypeScript) — Student Admit Card Viewer

```typescript
const BASE_URL = 'https://mis.sntcssc.in/api/v1';

export async function fetchStudentAdmitCard(examId: number, rollNumber: string, apiKey: string) {
  const response = await fetch(`${BASE_URL}/examinations/${examId}/admit-card?roll_number=${rollNumber}`, {
    method: 'GET',
    headers: {
      'X-API-Key': apiKey,
      'Accept': 'application/json',
    },
  });

  const payload = await response.json();
  if (payload.success) {
    return payload.data;
  }
  throw new Error('Failed to retrieve Admit Card data');
}
```

---

### 5.3. Desktop Examination Kiosk (Electron / Node.js)

```javascript
const axios = require('axios');

const examClient = axios.create({
  baseURL: 'https://mis.sntcssc.in/api/v1',
  headers: {
    'X-API-Key': process.env.SNTCSSC_API_KEY,
    'Accept': 'application/json',
  },
});

// Display Real-Time Visual Seating Board on Lobby TV Screens
async function getLiveSeatingMatrix(examId) {
  const { data } = await examClient.get(`/examinations/${examId}/seating-plan`);
  return data.data;
}

module.exports = { getLiveSeatingMatrix };
```

---

### 5.4. Python OMR Result Uploader

```python
import requests

BASE_URL = "https://mis.sntcssc.in/api/v1"
API_KEY = "sntc_live_YOUR_KEY"

# Bulk upload scored OMR marks
def upload_exam_scores(exam_id, results_list):
    res = requests.post(
        f"{BASE_URL}/examinations/{exam_id}/results",
        headers={"X-API-Key": API_KEY, "Accept": "application/json"},
        json={"results": results_list}
    )
    return res.json()
```

---

## 6. Invigilator QR Code Security & Verification Protocol

1. **Payload Structure:**
   The printed / mobile admit card QR code encapsulates:
   ```json
   {
     "exam_code": "CSCEE-2027",
     "roll_number": "CSCEE-27-KOL-0001",
     "token": "a1b2c3d4e5f67890123456789abcdef0",
     "timestamp": 1793308800
   }
   ```
2. **Offline Tolerance:**
   If mobile connectivity is temporarily interrupted inside an examination center, the mobile invigilator app caches scans locally in SQLite and synchronizes them automatically via `POST /examinations/{id}/attendance` as soon as connection is re-established.

---

## 7. Error Handling & Status Matrix

| HTTP Status | Error Code | Remedy |
|---|---|---|
| `401 Unauthorized` | `API_KEY_MISSING` | Supply `X-API-Key` or Bearer header. |
| `403 Forbidden` | `INSUFFICIENT_SCOPE` | Key lacks `read:examinations` or `write:examinations`. |
| `404 Not Found` | `MODEL_NOT_FOUND` | Examination ID or roll number not found. |
| `422 Unprocessable` | `VALIDATION_FAILED` | Check parameter rules (e.g. invalid date or status). |
| `429 Too Many Req` | `RATE_LIMIT_EXCEEDED` | Slow down scanner batch requests. |

