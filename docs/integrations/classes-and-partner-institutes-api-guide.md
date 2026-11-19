# Classes & Lecture Routine, Course Plans, Classrooms & Partner Coaching Institutes REST API — Multi-Platform Integration Guide

## 1. Executive Summary & Architecture Overview

The **Academic Operations & Lecture Routine Management System** provides enterprise-grade, RESTful API endpoints enabling cross-platform integrations across **Mobile Applications (iOS & Android)**, **Web Portals**, and **Desktop Applications**.

### Core Modules Covered
1. **Dynamic Classes & Routine Scheduling**: Multi-batch, multi-course, and section-wise lecture scheduler supporting regular, optional, extra, revision, and guest classes with offline/online/hybrid delivery modes (Zoom, Google Meet, Microsoft Teams, Cisco Webex, etc.). Includes class rescheduling, cancellation audit trails, lecture recording links, and student class history.
2. **Comprehensive Course Plans**: Abstract course syllabi maps (Annual, Monthly, Weekly, Custom) with downloadable official document softcopies.
3. **Classroom Master**: Physical hall allocation, buildings, floors, equipment facilities (Smart Board, Audio, AC, Wi-Fi, Generator backup), and course bindings.
4. **Faculty Master**: Faculty profile dossiers, teaching competencies, department categorization, CVs, photos, and linkage to affiliated coaching institutes.
5. **Partner Institutes for Coaching**: Comprehensive records of affiliated partner coaching institutes, MoU validity tenures, official representative contacts, and faculty linkages.

---

## 2. Authentication, Scopes & Headers

All API endpoints are hosted under `/api/v1` and authenticated using **API Keys** or **Sanctum Bearer Tokens**.

### Request Headers
| Header | Value | Description |
|---|---|---|
| `Authorization` | `Bearer sk_live_...` | API Key or Sanctum bearer token |
| `X-API-Key` | `sk_live_...` | Alternative API key header |
| `Accept` | `application/json` | Enforces JSON response envelope |
| `Content-Type` | `application/json` | For POST, PUT, PATCH request bodies |

### Required Permission Scopes
- `read:academics`: Required for reading classes, course plans, classrooms, faculty, and partner institutes.
- `write:academics`: Required for creating, updating, rescheduling, canceling, or deleting records.

---

## 3. API Endpoints Reference

### 3.1 Class Routine & Lecture Schedules

#### `GET /api/v1/classes`
Retrieve scheduled classes with multi-criteria filtering and pagination.

**Query Parameters:**
- `batch_id` (integer, optional): Filter by batch ID.
- `course_id` (integer, optional): Filter by course ID.
- `section_id` (integer, optional): Filter by section ID.
- `faculty_id` (integer, optional): Filter by assigned faculty ID.
- `class_type` (string, optional): `regular`, `optional`, `extra`, `revision`, `guest_lecture`.
- `delivery_mode` (string, optional): `offline`, `online`, `hybrid`.
- `status` (string, optional): `scheduled`, `in_progress`, `completed`, `rescheduled`, `cancelled`.
- `date_from` (date `YYYY-MM-DD`, optional): Start date range.
- `date_to` (date `YYYY-MM-DD`, optional): End date range.
- `today` (boolean, optional): Return only today's sessions (`today=1`).
- `upcoming` (boolean, optional): Return upcoming sessions (`upcoming=1`).
- `has_recording` (boolean, optional): Return sessions with recording URL or upload available.
- `search` (string, optional): Keyword search across subject, topics, lecture code, room, or teacher.
- `per_page` (integer, default `15`): Items per page.

**Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 101,
      "schedule_code": "CLS-2026-00101",
      "batch_id": 1,
      "course_id": 1,
      "section_id": 2,
      "class_type": "regular",
      "class_date": "2026-11-10",
      "start_time": "10:00:00",
      "end_time": "12:00:00",
      "formatted_time": "10:00 AM - 12:00 PM",
      "subject": "Modern Indian History",
      "topics": "Revolt of 1857 & Early Resistance",
      "lecture_number": "L-14",
      "delivery_mode": "online",
      "platform": "Zoom",
      "meeting_link": "https://zoom.us/j/1234567890",
      "meeting_password": "UPSC2026",
      "recording_url": "https://zoom.us/rec/share/sample-link",
      "recording_status": "available",
      "status": "scheduled",
      "faculties": [
        {
          "id": 5,
          "name": "Dr. Amit Roy",
          "faculty_code": "FAC-0005",
          "department": "History",
          "faculty_type": "regular"
        }
      ]
    }
  ],
  "meta": {
    "current_page": 1,
    "last_page": 5,
    "total": 68
  }
}
```

#### `POST /api/v1/classes`
Schedule a new single class session.

**Payload:**
```json
{
  "batch_id": 1,
  "course_id": 1,
  "section_id": 2,
  "class_type": "regular",
  "class_date": "2026-11-12",
  "start_time": "10:00:00",
  "end_time": "12:00:00",
  "subject": "Indian Polity & Constitution",
  "topics": "Fundamental Rights (Articles 14 to 18)",
  "lecture_number": "L-08",
  "delivery_mode": "online",
  "platform": "Google Meet",
  "meeting_link": "https://meet.google.com/abc-defg-hij",
  "faculty_ids": [5]
}
```

#### `POST /api/v1/classes/{id}/reschedule`
Reschedule an existing class to a new date/time with an audit reason.

**Payload:**
```json
{
  "class_date": "2026-11-14",
  "start_time": "14:00:00",
  "end_time": "16:00:00",
  "reschedule_reason": "Faculty attending State Academic Council meeting"
}
```

#### `POST /api/v1/classes/{id}/cancel`
Cancel a class session. Note: Canceled classes are recorded with cancellation reasons and automatically deducted from completed routine analytics.

**Payload:**
```json
{
  "cancel_reason": "Severe weather alert / Administrative closure"
}
```

#### `POST /api/v1/classes/{id}/recording`
Add cloud recording link or update credentials for students to watch on-demand.

**Payload:**
```json
{
  "recording_url": "https://us02web.zoom.us/rec/share/sample-access-link",
  "recording_password": "Passcode2026",
  "recording_notes": "PYQ analysis from 2018 to 2024 Mains questions",
  "recording_status": "available"
}
```

---

### 3.2 Student Class Schedule & History API

#### `GET /api/v1/classes/my-classes`
For authenticated students to view their batch and enrolled course classes.

**Query Parameters:**
- `today=1`: Filter today's classes.
- `upcoming=1`: Filter upcoming future sessions.
- `history=1`: Filter historical completed/past classes.
- `recordings_only=1`: Filter classes with uploaded recording links.

---

### 3.3 Classrooms Master API

#### `GET /api/v1/classrooms`
List physical lecture rooms and smart auditoriums.

**Query Parameters:**
- `search` (string, optional): Search by room name, code, or building.
- `building` (string, optional): Filter by building.
- `status` (string, optional): `active`, `inactive`, `maintenance`.
- `min_capacity` (integer, optional): Filter rooms with minimum seating capacity.

#### `POST /api/v1/classrooms`
Register a new classroom hall.

**Payload:**
```json
{
  "name": "Hall B - Smart Audio Visual Auditorium",
  "room_code": "CR-HALL-B",
  "building": "Main Academic Complex",
  "floor": "2nd Floor",
  "capacity": 120,
  "room_type": "smart_classroom",
  "status": "active",
  "facilities": [
    "Projector & Smart Board",
    "Air Conditioned",
    "High-speed Wi-Fi",
    "Audio System & Lapel Mic",
    "Generator Power Backup"
  ],
  "remarks": "Assigned for General Studies Foundation common lectures."
}
```

---

### 3.4 Course Plans & Syllabus Maps API

#### `GET /api/v1/classes/course-plans`
List course plans with batch, course, and section relations.

#### `POST /api/v1/classes/course-plans`
Publish a new course plan roadmap.

**Payload:**
```json
{
  "title": "Civil Services Prelims GS Foundation Comprehensive Annual Plan 2026-27",
  "batch_id": 1,
  "course_id": 1,
  "plan_type": "annual",
  "start_date": "2026-07-01",
  "end_date": "2027-04-30",
  "total_lectures_planned": 180,
  "topics_covered": "History, Geography, Polity, Economy, Environment, CSAT",
  "version": "1.0",
  "status": "published"
}
```

---

### 3.5 Faculty Master API

#### `GET /api/v1/classes/faculties`
List active faculty members with their linked partner institutes and employment types.

**Query Parameters:**
- `search` (string, optional): Search faculty name, department, or specialization.
- `partner_institute_id` (integer, optional): Filter by affiliated partner institute.
- `faculty_type` (string, optional): `regular`, `guest`, `visiting`, `contractual`, `adjunct`.

#### `POST /api/v1/classes/faculties`
Quick-add a faculty member inline.

**Payload:**
```json
{
  "name": "Prof. Sunita Mukherjee",
  "email": "sunita.mukherjee@sntcssc.org",
  "phone": "+919831122334",
  "designation": "Visiting Faculty",
  "department": "Geography",
  "partner_institute_id": 2,
  "faculty_type": "visiting",
  "specialization": "Geomorphology & Climatology",
  "subjects": ["Geography", "Disaster Management"]
}
```

---

### 3.6 Partner Institutes for Coaching API

#### `GET /api/v1/partner-institutes`
List all affiliated partner coaching institutes.

**Query Parameters:**
- `search` (string, optional): Search by institute name, code, or official.
- `status` (string, optional): `active`, `inactive`, `mou_expired`, `pending`.

**Response (200 OK):**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "code": "INST-2026-001",
      "name": "Apex IAS Academy",
      "website_url": "https://apexiademy.org",
      "email": "director@apexiademy.org",
      "mobile": "+919876543210",
      "start_date": "2026-01-01",
      "end_date": "2027-12-31",
      "status": "active",
      "status_label": "Active (MoU Valid)",
      "official_name": "Dr. S. K. Roy",
      "official_designation": "Director",
      "official_contact": "+919876543210",
      "official_email": "skroy@apexiademy.org",
      "faculties_count": 8
    }
  ]
}
```

#### `POST /api/v1/partner-institutes`
Register a new partner coaching institute.

**Payload:**
```json
{
  "name": "Chanakya Civil Services Forum",
  "code": "INST-CHANAKYA-2026",
  "description": "Collaborative partner for Mains answer writing evaluation and mock interview panels.",
  "website_url": "https://chanakyaias.org",
  "email": "contact@chanakyaias.org",
  "mobile": "+919830099887",
  "start_date": "2026-04-01",
  "end_date": "2028-03-31",
  "status": "active",
  "official_name": "Prof. Manas Banerjee",
  "official_designation": "Chief Academic Coordinator",
  "official_contact": "+919830099887",
  "official_email": "m.banerjee@chanakyaias.org"
}
```

#### `GET /api/v1/partner-institutes/{id}/faculties`
Retrieve all faculties affiliated with a specific partner institute.

---

## 4. Cross-Platform Client Implementations

### 4.1 Flutter / Dart (Mobile App)
```dart
import 'dart:convert';
import 'package:http/http.dart' as http;

class ClassRoutineService {
  final String baseUrl = 'https://mis.sntcssc.org/api/v1';
  final String apiKey;

  ClassRoutineService({required this.apiKey});

  Future<List<dynamic>> fetchTodayRoutine() async {
    final response = await http.get(
      Uri.parse('$baseUrl/classes?today=1'),
      headers: {
        'Accept': 'application/json',
        'Authorization': 'Bearer $apiKey',
      },
    );

    if (response.statusCode == 200) {
      final json = jsonDecode(response.body);
      return json['data'];
    } else {
      throw Exception('Failed to load routine: ${response.statusCode}');
    }
  }
}
```

### 4.2 React / Next.js / Vue (Web Frontend)
```typescript
import axios from 'axios';

const apiClient = axios.create({
  baseURL: 'https://mis.sntcssc.org/api/v1',
  headers: {
    Accept: 'application/json',
    Authorization: `Bearer ${process.env.SNTCSSC_API_KEY}`,
  },
});

export const getPartnerInstitutes = async () => {
  const { data } = await apiClient.get('/partner-institutes?active_only=1');
  return data.data;
};

export const scheduleLecture = async (payload: Record<string, any>) => {
  const { data } = await apiClient.post('/classes', payload);
  return data;
};
```

### 4.3 cURL Command Test
```bash
curl -X GET "https://mis.sntcssc.org/api/v1/classes?today=1" \
  -H "Accept: application/json" \
  -H "Authorization: Bearer sk_live_your_api_key_here"
```

---

## 5. Security & Best Practices
1. **API Key Isolation**: Restrict permissions per environment (`sandbox`, `production`) and assign only `read:academics` or `write:academics` scopes.
2. **Rate Limiting**: Standard API keys are limited to 60 requests per minute with automatic burst protection.
3. **Audit Trails**: All CRUD mutations automatically generate cryptographic `AuditLog` records linking IP address, user agent, and payload diffs.
