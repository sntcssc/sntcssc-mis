# SNTCSSC MIS — Dynamic Study Material Management System REST API Integration Guide
### Multi-Platform Implementation & Integration Guide (Mobile App, Web Portal, Desktop Client & Third-Party CMS)

---

## 1. Executive Summary & Architecture Overview

The **Dynamic Study Material Management System** of the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS** delivers a high-performance, decoupled, and future-ready RESTful API. Designed to empower native **Mobile Applications** (Flutter / React Native / iOS Swift / Android Kotlin), **Web Applications & Student Portals** (React / Vue / Angular), **Desktop Offline Readers** (Electron / C# .NET / Tauri), and **External Public Lead Capture Portals**, this API unifies study material publication, granular access control, real-time client telemetry, dynamic category management, and encrypted token-based binary streaming.

### Key Capabilities:
1. **Dynamic Material Types Catalog:** Fully extensible hierarchy (Lecture Handouts, Previous Year Question Papers, Model Answers, Current Affairs Compendiums, PIB Sandhan, E-Books, Video Recordings, Audio Podcasts) with real-time material counts and visibility controls.
2. **Multi-Tiered Access & Entitlement Engine:** Enforces access at Course, Batch, Selective Student, and Public Share levels with tokenized expiration and password challenge capabilities.
3. **Multi-Platform Binary Delivery:** Chunked streaming and signed single-use download tokens (`/download/{token}`) that prevent exposure of direct cloud or server file paths while maximizing download speeds on low-bandwidth mobile connections.
4. **Client View & Interaction Telemetry:** Captures platform (`mobile`, `desktop`, `web`), device user-agent, timestamps, and IP addresses to audit reading engagement.
5. **Public Lead Capture with Smart OTP Bypass:** Allows prospective civil services aspirants on public landing pages or WordPress websites to access free Current Affairs and PIB compilations with automated OTP validation and returning subscriber bypass.
6. **Mobile Offline Sync Architecture:** Standardized timestamps (`created_at`, `updated_at`, `class_date`) and content hashes to facilitate local SQLite / Hive caching on mobile devices.

---

## 2. System Architecture & Sequence Flows

```mermaid
graph TD
    subgraph Multi-Platform Clients
        Mobile[Mobile App\nFlutter / React Native / Swift]
        WebPortal[Student Portal / Web App\nReact / Vue / Blade]
        Desktop[Desktop Offline App\nElectron / C# .NET]
        PublicSite[Public Landing Page / CMS\nWordPress / Jamstack]
    end

    subgraph Security & Gateway
        GW[API Gateway / Route Engine]
        KeyAuth{API Key / Bearer Auth}
        ScopeGate{Scope Verification\nread:materials\nwrite:materials\ndownload:materials}
    end

    subgraph Core Services
        MatCtrl[StudyMaterialApiController]
        ExtCtrl[ExternalStudyMaterialApiController]
        MatSvc[StudyMaterialService]
        TypeSvc[StudyMaterialTypeService]
        FileSvc[FileUploadService]
        CacheEngine[(Redis / Database Cache\nSigned Tokens)]
    end

    subgraph Storage & Database
        DB[(PostgreSQL / MySQL / SQLite)]
        Disk[(Encrypted File Storage\nLocal / S3)]
    end

    Mobile -->|Bearer Token / X-API-Key| GW
    WebPortal -->|Sanctum / X-API-Key| GW
    Desktop -->|X-API-Key| GW
    PublicSite -->|X-API-Key| GW

    GW --> KeyAuth
    KeyAuth --> ScopeGate
    ScopeGate --> MatCtrl
    ScopeGate --> ExtCtrl

    MatCtrl --> MatSvc
    MatCtrl --> TypeSvc
    MatCtrl --> CacheEngine
    MatSvc --> FileSvc
    FileSvc --> Disk
    MatSvc --> DB
```

### 2.1. Mobile Client Offline Sync & Material Download Flow

```mermaid
sequenceDiagram
    autonumber
    actor Student as Enrolled Aspirant
    participant Mobile as Mobile App (Flutter/RN)
    participant API as MIS Study Material API
    participant Cache as Redis/Cache Layer
    participant Storage as File Server Storage

    Student->>Mobile: Opens "Study Materials" Tab
    Mobile->>API: GET /api/v1/study-materials/my-materials (Bearer Token)
    API-->>Mobile: List of accessible materials (enrolled course/batches)
    Mobile->>Mobile: Compare updated_at against local SQLite database
    Note over Mobile: Highlight new & updated materials for student

    Student->>Mobile: Taps "Download PDF Handout"
    Mobile->>API: GET /api/v1/study-materials/{id}/download-token
    API->>Cache: Generate & store single-use signed token (30-min TTL)
    API-->>Mobile: 200 OK { token, download_url, expires_in_seconds }
    
    Mobile->>API: GET /api/v1/study-materials/download/{token}
    API->>Cache: Validate token & resolve material file
    API->>Storage: Stream binary file stream (application/pdf)
    API->>API: Increment download_count & record StudyMaterialAccessLog
    API-->>Mobile: Binary payload (chunked stream)
    Mobile->>Mobile: Save to device Encrypted Sandbox Storage
    Mobile-->>Student: Ready for instant offline reading
```

### 2.2. Administrative Publication & File Attachment Flow

```mermaid
sequenceDiagram
    autonumber
    actor Faculty as Faculty / Administrator
    participant Portal as Web / Desktop Admin
    participant API as MIS Study Material API
    participant DB as Database
    participant Disk as File Storage

    Faculty->>Portal: Fills Material Form & Selects Batches
    Portal->>API: POST /api/v1/study-materials (Header: X-API-Key with write:materials)
    API->>DB: Create StudyMaterial & attach batch/course relations
    API-->>Portal: 201 Created (material_id, uuid)

    Faculty->>Portal: Attaches Lecture Notes (PDF) & Supplementary Slide (PPTX)
    Portal->>API: POST /api/v1/study-materials/{id}/files (multipart/form-data)
    API->>Disk: Store sanitized binary files with unique hash prefix
    API->>DB: Create StudyMaterialFile records with mime type and byte size
    API-->>Portal: 201 Created (files metadata array)
    Portal-->>Faculty: Published successfully & real-time notifications dispatched
```

---

## 3. Authentication, Base URLs & Scopes Matrix

### 3.1. Base URLs
- **Production Base:** `https://mis.sntcssc.in/api/v1`
- **Local / Staging Base:** `http://localhost:8000/api/v1`

### 3.2. Authentication Headers
External services and server-to-server applications must supply the API Key:
```http
X-API-Key: sntc_live_YOUR_ASSIGNED_SECRET_KEY
```
or via the Authorization header:
```http
Authorization: Bearer sntc_live_YOUR_ASSIGNED_SECRET_KEY
```

For logged-in mobile students or web portal users, the standard Sanctum Bearer token is supported.

### 3.3. Scopes Reference
| Scope Name | Access Privilege | Permitted Operations |
|---|---|---|
| `read:materials` | Read Access | Browse catalog, fetch single dossier, search, filter, track telemetry |
| `write:materials` | Authoring Access | Create material, update metadata, delete, upload files, manage types, generate share links |
| `download:materials` | Download Access | Request signed download tokens, lead capture file downloads |

---

## 4. Complete RESTful API Endpoints Reference

### 4.1. Core Material Retrieval & Student Feeds

#### `GET /api/v1/study-materials`
Retrieve paginated, published study materials with search, course, batch, and type filters.
- **Required Scope:** `read:materials` (Optional for public catalog items)
- **Query Parameters:**
  - `page` (integer, default: 1): Page number.
  - `per_page` (integer, default: 15, max: 100): Results per page.
  - `search` (string, optional): Keyword search matching title, subject, topic, or description.
  - `course_id` (integer, optional): Filter by course ID.
  - `batch_id` (integer, optional): Filter by batch ID.
  - `material_type` (string, optional): Filter by type code (e.g., `lecture_notes`, `current_affairs`, `pib_sandhan`).
  - `pinned` (boolean, optional): Set `true` to retrieve only pinned materials.
  - `sort` (string, optional): Sort order (`order`, `latest`, `oldest`, `downloads`, `views`, `title`, `class_date`).
- **Response `200 OK`:**
```json
{
  "data": [
    {
      "id": 14,
      "uuid": "7c8234bb-6632-4e9f-9f77-50deef467571",
      "title": "Indian Polity: Constitutional Amendments & Basic Structure Doctrine",
      "slug": "indian-polity-constitutional-amendments",
      "course": {
        "id": 1,
        "name": "UPSC Civil Services Comprehensive Coaching",
        "code": "UPSC-CSE-2027"
      },
      "batches": [
        { "id": 1, "name": "Batch A (Morning Session)", "code": "UPSC-A-26" }
      ],
      "batches_summary": "Batch A (Morning Session)",
      "subject": "General Studies Paper II",
      "topic": "Indian Constitution & Polity",
      "module_code": "POL-MOD-04",
      "material_type": "lecture_notes",
      "material_type_label": "Lecture Notes",
      "description": "Comprehensive class handouts and analytical case law summaries.",
      "thumbnail_url": "https://mis.sntcssc.in/storage/study-materials/thumbnails/thumb_01.jpg",
      "class_date": "2026-10-25",
      "display_order": 1,
      "external_url": null,
      "access_level": "enrolled_only",
      "is_pinned": true,
      "status": "published",
      "view_count": 342,
      "download_count": 189,
      "total_files_count": 2,
      "formatted_total_size": "8.4 MB",
      "public_share_url": "https://mis.sntcssc.in/study-materials/shared/token_abc123",
      "created_at": "2026-10-25T10:30:00.000000Z",
      "updated_at": "2026-10-26T14:15:00.000000Z"
    }
  ],
  "links": { "first": "...", "last": "...", "prev": null, "next": null },
  "meta": { "current_page": 1, "from": 1, "last_page": 1, "per_page": 15, "to": 1, "total": 1 }
}
```

---

#### `GET /api/v1/study-materials/{identifier}`
Retrieve full study material dossier by ID or UUID.
- **Required Scope:** `read:materials`
- **Path Parameters:**
  - `identifier` (string|int): Numeric ID or UUID string.
- **Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "id": 14,
    "uuid": "7c8234bb-6632-4e9f-9f77-50deef467571",
    "title": "Indian Polity: Constitutional Amendments & Basic Structure Doctrine",
    "slug": "indian-polity-constitutional-amendments",
    "course": {
      "id": 1,
      "name": "UPSC Civil Services Comprehensive Coaching",
      "code": "UPSC-CSE-2027",
      "category": "Civil Services"
    },
    "batches": [
      { "id": 1, "name": "Batch A", "code": "UPSC-A-26", "academic_year": "2026-2027" }
    ],
    "subject": "General Studies Paper II",
    "topic": "Indian Constitution & Polity",
    "module_code": "POL-MOD-04",
    "material_type": "lecture_notes",
    "material_type_label": "Lecture Notes",
    "description": "Comprehensive class handouts and analytical case law summaries.",
    "thumbnail_url": "https://mis.sntcssc.in/storage/study-materials/thumbnails/thumb_01.jpg",
    "class_date": "2026-10-25",
    "display_order": 1,
    "content": "### Key Judicial Doctrines:\n- Kesavananda Bharati v. State of Kerala (1973)\n- Minerva Mills v. Union of India (1980)",
    "external_url": null,
    "access_level": "enrolled_only",
    "is_pinned": true,
    "status": "published",
    "view_count": 342,
    "download_count": 189,
    "files": [
      {
        "id": 28,
        "file_name": "GS2_Polity_Constitutional_Amendments.pdf",
        "file_size": 5242880,
        "formatted_size": "5.0 MB",
        "file_type": "pdf",
        "mime_type": "application/pdf",
        "download_count": 120,
        "download_url": "https://mis.sntcssc.in/study-materials/files/28/download",
        "preview_url": "https://mis.sntcssc.in/study-materials/14/preview/28",
        "created_at": "2026-10-25T10:32:00.000000Z"
      }
    ],
    "total_files_count": 1,
    "formatted_total_size": "5.0 MB",
    "public_share_url": "https://mis.sntcssc.in/study-materials/shared/token_abc123",
    "is_expired": false,
    "share_expires_at": null,
    "creator": {
      "id": 3,
      "name": "Dr. Sanjeev Sharma (Faculty)"
    },
    "created_at": "2026-10-25T10:30:00.000000Z",
    "updated_at": "2026-10-26T14:15:00.000000Z"
  }
}
```

---

#### `GET /api/v1/study-materials/my-materials`
Student-specific personalized study materials feed for Mobile App or Student Dashboard. Automatically filters materials matching the student's enrolled batches and courses.
- **Required Scope:** `read:materials` or logged-in Bearer student token.
- **Query Parameters:**
  - `student_id` (integer, optional): When invoked via administrative API Key to inspect a specific student's feed.
  - `search` (string, optional): Title/topic query.
  - `material_type` (string, optional): Type filter.
- **Response `200 OK`:** Paginated array of `StudyMaterialResource`.

---

### 4.2. Administrative Authoring & File Management

#### `POST /api/v1/study-materials`
Publish a new study material record.
- **Required Scope:** `write:materials`
- **Request Body (JSON or `multipart/form-data`):**
```json
{
  "title": "Modern Indian History: Governor-Generals & Viceroys Timeline",
  "course_id": 1,
  "batch_ids": [1, 2],
  "subject": "General Studies Paper I",
  "topic": "Modern Indian History (1757 - 1947)",
  "module_code": "HIST-MOD-02",
  "material_type": "handouts",
  "description": "Comparative chart of administrative reforms from Warren Hastings to Lord Mountbatten.",
  "class_date": "2026-10-28",
  "display_order": 2,
  "access_level": "enrolled_only",
  "is_pinned": false,
  "status": "published"
}
```
- **Response `201 Created`:** Full `StudyMaterialDetailResource` response.

---

#### `PUT /api/v1/study-materials/{identifier}`
Update an existing study material's metadata, associations, or status.
- **Required Scope:** `write:materials`
- **Response `200 OK`:** Updated `StudyMaterialDetailResource`.

---

#### `DELETE /api/v1/study-materials/{identifier}`
Safely soft-delete a study material.
- **Required Scope:** `write:materials`
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "Study material deleted successfully."
}
```

---

#### `POST /api/v1/study-materials/{identifier}/files`
Upload and attach binary files (PDF, DOCX, MP4, PPTX, ZIP) to an existing study material.
- **Required Scope:** `write:materials`
- **Request Content-Type:** `multipart/form-data`
- **Form Fields:** `files[]` (file stream, max: 50MB per file).
- **Response `201 Created`:**
```json
{
  "success": true,
  "message": "Uploaded 2 file(s) successfully.",
  "data": [
    {
      "id": 35,
      "file_name": "History_Viceroys_Handout.pdf",
      "file_size": 2490368,
      "formatted_size": "2.37 MB",
      "file_type": "pdf",
      "mime_type": "application/pdf",
      "download_count": 0,
      "download_url": "https://mis.sntcssc.in/study-materials/files/35/download",
      "created_at": "2026-10-28T16:00:00.000000Z"
    }
  ]
}
```

---

#### `DELETE /api/v1/study-materials/{identifier}/files/{fileId}`
Delete an attached file from server storage and remove database records.
- **Required Scope:** `write:materials`
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "File attachment deleted successfully."
}
```

---

### 4.3. Dynamic Material Types Management

#### `GET /api/v1/study-materials/types`
Retrieve active study material type categories with published material counts.
- **Required Scope:** `read:materials` (Optional for public clients)
- **Response `200 OK`:**
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "name": "Lecture Notes",
      "code": "lecture_notes",
      "description": "Class lecture notes and slide decks.",
      "icon": "book-open",
      "badge_color": "primary",
      "is_public": false,
      "is_system": true,
      "is_active": true,
      "display_order": 1,
      "materials_count": 142
    },
    {
      "id": 2,
      "name": "Current Affairs",
      "code": "current_affairs",
      "description": "Monthly compendiums and daily analysis.",
      "icon": "newspaper",
      "badge_color": "emerald",
      "is_public": true,
      "is_system": true,
      "is_active": true,
      "display_order": 2,
      "materials_count": 86
    },
    {
      "id": 3,
      "name": "PIB Sandhan",
      "code": "pib_sandhan",
      "description": "Monthly PIB compilation for civil service exams.",
      "icon": "file-text",
      "badge_color": "amber",
      "is_public": true,
      "is_system": true,
      "is_active": true,
      "display_order": 3,
      "materials_count": 34
    }
  ]
}
```

---

#### `POST /api/v1/study-materials/types`
Create a new custom material type (e.g. `podcast`, `infographics`, `mains_answer_key`).
- **Required Scope:** `write:materials`
- **Request Body:**
```json
{
  "name": "Mains Model Answers",
  "code": "mains_model_answers",
  "description": "Evaluated high-scoring model answers and topper copies.",
  "icon": "check-circle",
  "badge_color": "purple",
  "is_public": false,
  "is_active": true,
  "display_order": 7
}
```
- **Response `201 Created`:** Created type object.

---

### 4.4. Streaming Downloads & Mobile Telemetry

#### `GET /api/v1/study-materials/{identifier}/download-token`
Issue an authorized, signed, time-limited token for binary downloads.
- **Required Scope:** `download:materials`
- **Query Parameters:** `file_id` (integer, optional)
- **Response `200 OK`:**
```json
{
  "success": true,
  "token": "sntc_dl_7a8f9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a",
  "file_name": "GS2_Polity_Constitutional_Amendments.pdf",
  "file_size": 5242880,
  "formatted_size": "5.0 MB",
  "download_url": "https://mis.sntcssc.in/api/v1/study-materials/download/sntc_dl_7a8f9b0c1d2e3f4a5b6c7d8e9f0a1b2c3d4e5f6a",
  "expires_in_seconds": 1800
}
```

---

#### `GET /api/v1/study-materials/download/{token}`
Direct binary stream for mobile background downloaders and in-app PDF viewers.
- **Required Scope:** None (Authorized via signed token URL).
- **Headers Returned:**
  - `Content-Type: application/pdf` (or corresponding MIME)
  - `Content-Disposition: attachment; filename="GS2_Polity_Constitutional_Amendments.pdf"`
  - `Content-Length: 5242880`

---

#### `GET /api/v1/study-materials/{identifier}/download-zip`
Download all binary attachments of a study material bundled into a single ZIP archive. Ideal for mobile offline synchronizers and desktop clients that need to prefetch course folders in one network call.
- **Required Scope:** `download:materials` or authenticated student Bearer token.
- **Headers Returned:**
  - `Content-Type: application/zip`
  - `Content-Disposition: attachment; filename="modern-indian-history-timeline-files.zip"`
- **Response:** Streaming binary ZIP archive stream with automatic temporary cache deletion after delivery.

---

#### `POST /api/v1/study-materials/{identifier}/track-view`
Record reading telemetry from mobile, desktop, or web client.
- **Required Scope:** `read:materials`
- **Headers:** `X-Client-Platform: flutter_mobile` (or `react_native`, `electron_desktop`, `web_portal`)
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "View recorded.",
  "view_count": 343
}
```

---

#### `POST /api/v1/study-materials/{identifier}/share-link`
Generate or update a public tokenized share link with optional expiration and password protection.
- **Required Scope:** `write:materials`
- **Request Body:**
```json
{
  "share_password": "UPSC2026Password!",
  "share_expires_at": "2026-12-31 23:59:59",
  "regenerate_token": false
}
```
- **Response `200 OK`:**
```json
{
  "success": true,
  "message": "Share link updated.",
  "data": {
    "share_url": "https://mis.sntcssc.in/study-materials/shared/sntc_sh_x9y8z7w6v5",
    "share_token": "sntc_sh_x9y8z7w6v5",
    "has_password": true,
    "share_expires_at": "2026-12-31T23:59:59.000000Z",
    "is_expired": false
  }
}
```

---

#### `GET /api/v1/study-materials/statistics`
Real-time repository statistics and download analytics.
- **Required Scope:** `read:materials`
- **Response `200 OK`:**
```json
{
  "success": true,
  "data": {
    "total_materials": 420,
    "published_materials": 412,
    "draft_materials": 8,
    "pinned_materials": 15,
    "total_files": 680,
    "total_downloads": 34500,
    "total_views": 89200,
    "total_storage_bytes": 1583344000,
    "formatted_storage": "1.47 GB"
  }
}
```

---

## 5. Multi-Platform SDK Integration Recipes

### 5.1. Flutter (Dart) — Mobile App Implementation

```dart
import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

class SntcsscStudyMaterialService {
  final String baseUrl;
  final String apiKey;
  final String? userBearerToken;

  SntcsscStudyMaterialService({
    this.baseUrl = 'https://mis.sntcssc.in/api/v1',
    required this.apiKey,
    this.userBearerToken,
  });

  Map<String, String> get _headers => {
    'X-API-Key': apiKey,
    if (userBearerToken != null) 'Authorization': 'Bearer $userBearerToken',
    'Accept': 'application/json',
    'X-Client-Platform': 'flutter_mobile',
  };

  // 1. Fetch Personalized Student Feed
  Future<List<dynamic>> fetchMyMaterials({String? search, String? type}) async {
    final queryParams = <String, String>{};
    if (search != null) queryParams['search'] = search;
    if (type != null) queryParams['material_type'] = type;

    final uri = Uri.parse('$baseUrl/study-materials/my-materials').replace(queryParameters: queryParams);
    final res = await http.get(uri, headers: _headers);

    if (res.statusCode == 200) {
      final json = jsonDecode(res.body);
      return json['data'];
    }
    throw Exception('Failed to load study materials: ${res.statusCode}');
  }

  // 2. Track Material View Telemetry
  Future<void> trackMaterialView(int materialId) async {
    await http.post(
      Uri.parse('$baseUrl/study-materials/$materialId/track-view'),
      headers: _headers,
    );
  }

  // 3. Secure Streaming Download to Device Sandbox
  Future<File> downloadAndSaveMaterialFile(int materialId, String targetFileName) async {
    // Step A: Request signed time-limited token
    final tokenRes = await http.get(
      Uri.parse('$baseUrl/study-materials/$materialId/download-token'),
      headers: _headers,
    );
    if (tokenRes.statusCode != 200) {
      throw Exception('Failed to obtain download token');
    }
    final tokenData = jsonDecode(tokenRes.body);
    final String downloadUrl = tokenData['download_url'];

    // Step B: Stream binary file directly
    final response = await http.get(Uri.parse(downloadUrl));
    final directory = await getApplicationDocumentsDirectory();
    final filePath = '${directory.path}/materials/$targetFileName';
    final file = File(filePath);
    await file.create(recursive: true);
    await file.writeAsBytes(response.bodyBytes);

    return file;
  }
}
```

---

### 5.2. React Native (TypeScript / JavaScript)

```typescript
const BASE_URL = 'https://mis.sntcssc.in/api/v1';
const API_KEY = 'sntc_live_YOUR_ASSIGNED_SECRET_KEY';

export interface StudyMaterialItem {
  id: number;
  uuid: string;
  title: string;
  subject: string;
  material_type: string;
  thumbnail_url: string | null;
  total_files_count: number;
  formatted_total_size: string;
}

// 1. Fetch Materials Feed with Search Filter
export async function getStudyMaterials(searchQuery: string = '', materialType: string = ''): Promise<StudyMaterialItem[]> {
  const url = new URL(`${BASE_URL}/study-materials`);
  if (searchQuery) url.searchParams.append('search', searchQuery);
  if (materialType) url.searchParams.append('material_type', materialType);

  const response = await fetch(url.toString(), {
    method: 'GET',
    headers: {
      'X-API-Key': API_KEY,
      'Accept': 'application/json',
      'X-Client-Platform': 'react_native',
    },
  });

  const payload = await response.json();
  return payload.data;
}

// 2. Request Signed Download Token
export async function getDownloadUrl(materialId: number): Promise<string> {
  const response = await fetch(`${BASE_URL}/study-materials/${materialId}/download-token`, {
    method: 'GET',
    headers: {
      'X-API-Key': API_KEY,
      'Accept': 'application/json',
    },
  });
  const data = await response.json();
  return data.download_url;
}
```

---

### 5.3. Desktop Application (Electron / Node.js)

```javascript
const axios = require('axios');
const fs = require('fs');
const path = require('path');

const apiClient = axios.create({
  baseURL: 'https://mis.sntcssc.in/api/v1',
  headers: {
    'X-API-Key': process.env.SNTCSSC_API_KEY,
    'Accept': 'application/json',
    'X-Client-Platform': 'electron_desktop',
  },
  timeout: 15000,
});

// Sync materials catalog to local cache
async function syncLocalRepository(destFolder) {
  const { data } = await apiClient.get('/study-materials', { params: { per_page: 100 } });
  const materials = data.data;

  for (const item of materials) {
    console.log(`[Sync] Processing: ${item.title}`);
    if (item.total_files_count > 0) {
      const tokenRes = await apiClient.get(`/study-materials/${item.id}/download-token`);
      const dlUrl = tokenRes.data.download_url;
      const fileName = tokenRes.data.file_name;

      const writer = fs.createWriteStream(path.join(destFolder, fileName));
      const response = await axios({ url: dlUrl, method: 'GET', responseType: 'stream' });
      response.data.pipe(writer);
    }
  }
}

module.exports = { syncLocalRepository };
```

---

### 5.4. C# (.NET 8+) Desktop Client

```csharp
using System;
using System.IO;
using System.Net.Http;
using System.Text.Json;
using System.Threading.Tasks;

public class SntcsscMaterialClient
{
    private static readonly HttpClient client = new HttpClient();
    private const string BaseUrl = "https://mis.sntcssc.in/api/v1";

    public static async Task DownloadStudyMaterialAsync(string apiKey, int materialId, string destinationFilePath)
    {
        // 1. Get Token
        var tokenReq = new HttpRequestMessage(HttpMethod.Get, $"{BaseUrl}/study-materials/{materialId}/download-token");
        tokenReq.Headers.Add("X-API-Key", apiKey);
        tokenReq.Headers.Add("Accept", "application/json");

        var tokenRes = await client.SendAsync(tokenReq);
        tokenRes.EnsureSuccessStatusCode();
        var json = await tokenRes.Content.ReadAsStringAsync();
        using var doc = JsonDocument.Parse(json);
        string downloadUrl = doc.RootElement.GetProperty("download_url").GetString();

        // 2. Stream Binary File
        var fileBytes = await client.GetByteArrayAsync(downloadUrl);
        await File.WriteAllBytesAsync(destinationFilePath, fileBytes);
    }
}
```

---

### 5.5. Python Automation Script

```python
import requests

BASE_URL = "https://mis.sntcssc.in/api/v1"
API_KEY = "sntc_live_YOUR_KEY"

session = requests.Session()
session.headers.update({
    "X-API-Key": API_KEY,
    "Accept": "application/json",
})

# 1. Fetch KPI Statistics
stats = session.get(f"{BASE_URL}/study-materials/statistics").json()
print("Total Materials in Repository:", stats['data']['total_materials'])
print("Total File Downloads to Date:", stats['data']['total_downloads'])

# 2. Publish New Handout
payload = {
    "title": "Economy 2026: Union Budget & Fiscal Deficit Handout",
    "course_id": 1,
    "material_type": "handouts",
    "subject": "General Studies Paper III",
    "topic": "Indian Economy and Fiscal Policy",
    "access_level": "enrolled_only",
    "status": "published"
}
res = session.post(f"{BASE_URL}/study-materials", json=payload)
print("Published Record ID:", res.json()['data']['id'])
```

---

## 6. Offline Synchronization & Caching Strategy

Mobile and desktop apps should adhere to this recommended offline persistence strategy:

1. **Local SQLite Schema:**
   - Store material metadata indexed by `id` and `uuid`.
   - Maintain a `local_file_path` column to track cached attachments.
   - Store `updated_at` timestamps for differential sync checks.
2. **Differential Sync Algorithm:**
   - Request `GET /study-materials/my-materials?sort=latest`.
   - Iterate items: if `item.updated_at > local_record.updated_at`, trigger background binary re-download.
   - If an item is absent in server response and marked soft-deleted, remove local binary and delete record.
3. **Bandwidth Optimization:**
   - Always query `/download-token` first; examine `file_size` against mobile network constraints (e.g., prompt user if file > 25MB on cellular data).

---

## 7. Error Codes & HTTP Status Matrix

| HTTP Status | Error Code | Description & Client Remedy |
|---|---|---|
| `401 Unauthorized` | `API_KEY_MISSING` | The request lacked `X-API-Key` or `Authorization` Bearer header. |
| `401 Unauthorized` | `API_KEY_INVALID` | Secret key could not be verified or is revoked. |
| `403 Forbidden` | `INSUFFICIENT_SCOPE` | Key lacks the required scope (e.g. attempting POST without `write:materials`). |
| `403 Forbidden` | `LINK_EXPIRED` | Signed download token has expired (request a new token). |
| `404 Not Found` | `MODEL_NOT_FOUND` | Material or file ID does not exist. |
| `422 Unprocessable` | `VALIDATION_FAILED` | Input payload validation failed (inspect `errors` array). |
| `429 Too Many Req` | `RATE_LIMIT_EXCEEDED` | Threshold exceeded (inspect `Retry-After` header). |
| `500 Server Error` | `SERVER_EXCEPTION` | Internal processing failure. Contact institutional sysadmin. |

