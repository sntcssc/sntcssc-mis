# SNTCSSC MIS — Student Information Sheet REST API & Multi-Platform Integration Guide
### Comprehensive Cross-Platform Developer Reference (Mobile App, Admin Web Console, Desktop Dossier Printer & Third-Party Integrations)

---

## 1. Executive Summary & Architecture Overview

The **Student Information Sheet** module of the **Satyendranath Tagore Civil Services Study Centre (SNTCSSC) MIS** delivers an enterprise-grade RESTful API and printable reporting pipeline. It compiles a candidate's complete 360-degree academic and administrative profile into a unified, publication-grade document and JSON dossier.

Whether integrating with a **Student Mobile Application** (Flutter / React Native), an **Administrative Web Console**, an **Automated Campus Kiosk / Gatekeeper Terminal** (Electron / C# / Python), or an **External Education Portal**, this API provides a normalized, decoupled, future-proof, and secure interface.

### Key Capabilities:
1. **Unified 360-Degree Candidate Dossier:** Aggregates personal identity, contact coordinates, permanent & correspondence addresses, academic qualification records (10th to Post-Graduation), previous civil services / UPSC CSE attempts, employment history, and active course enrollments into a single structured payload.
2. **Passport Photo & Avatar Resolution:** Multi-tier photo detection algorithm checking authenticated user avatars first, followed by verified student document uploads (tagged as `photo` or `photograph`), with dynamic SVG/canvas initials fallback.
3. **Publication-Grade Print & PDF Streaming:** Print-ready, single-student or bulk-compiled multi-page A4 PDFs rendered via DomPDF using strict DejaVu Sans typography, `<colgroup>` table architecture, `rowspan` identity passport photo cells, and page-break guards.
4. **Supporting Documents Annexure Attachment:** Selective inclusion of student verification documents (caste certificate, ID proof, certificates) rendered as high-fidelity base64 annexure attachments directly in the PDF or JSON response.
5. **Multi-Identifier Lookup:** Seamless querying via internal primary key `id`, universal `uuid`, 9-digit Lifetime Unique Student ID `student_id`, system `urn`, registered phone number, or institutional email.
6. **Student Self-Service Portal:** Authenticated candidates can inspect their own live profile sheet or stream their official PDF directly through Sanctum-authenticated endpoints.
7. **Comprehensive Audit Trails:** Every sheet generation, JSON inspection, single download, and bulk compilation action is recorded in the immutable `audit_logs` table with actor context, IP, User-Agent, and target student entity.
8. **Enterprise Soft-Deletion Guard:** Safely operates across soft-deleted records with administrative overrides (`with_trashed=1`) to facilitate historical archive retrieval.

---

## 2. Architecture & Sequence Flows

```mermaid
graph TD
    subgraph Multi-Platform Clients
        StudentApp["Student Mobile App<br/>(Flutter / React Native)"]
        AdminWeb["Admin Management Console<br/>(Livewire 4 / Blade)"]
        PrintKiosk["Printing Kiosk / Desktop App<br/>(Electron / C# / Python)"]
        ThirdParty["External Services / LMS<br/>(REST API)"]
    end

    subgraph Security & Access Layer
        Gateway["API Gateway / Route Dispatcher"]
        AuthGuard{"Auth Guard<br/>X-API-Key / Sanctum Bearer / Session"}
        ScopeEnforcer{"Scope Enforcer<br/>read:students<br/>download:students"}
    end

    subgraph Domain Services
        SheetApiCtrl["StudentInformationSheetApiController"]
        SheetSvc["StudentInformationSheetService"]
        AuditSvc["AuditLogService"]
        PdfEngine["DomPDF Engine<br/>(Barryvdh / DomPDF)"]
    end

    subgraph Storage & Persistence
        MySQL[("Relational Database<br/>(Users, Profiles, Addresses, Educations)")]
        DiskStorage[("Public / Local Storage<br/>(Avatars & Documents)")]
        AuditDB[("Audit Logs Database<br/>(audit_logs)")]
    end

    StudentApp -->|"Bearer Token / Sanctum"| Gateway
    AdminWeb -->|"Session / API Key"| Gateway
    PrintKiosk -->|"X-API-Key Header"| Gateway
    ThirdParty -->|"X-API-Key Header"| Gateway

    Gateway --> AuthGuard
    AuthGuard --> ScopeEnforcer
    ScopeEnforcer --> SheetApiCtrl

    SheetApiCtrl --> SheetSvc
    SheetApiCtrl --> AuditSvc
    AuditSvc --> AuditDB

    SheetSvc --> MySQL
    SheetSvc --> DiskStorage
    SheetSvc --> PdfEngine
```

### 2.1. Administrative Bulk PDF Generation Flow

```mermaid
sequenceDiagram
    autonumber
    actor Officer as Administrative Officer / Kiosk
    participant API as Information Sheet API (/api/v1/students/information-sheets/download)
    participant Svc as StudentInformationSheetService
    participant DB as Relational Database
    participant Storage as File Storage Disk
    participant Audit as AuditLogService

    Officer->>API: POST /api/v1/students/information-sheets/download (ids=[1, 4, 9], include_documents=true)
    API->>API: Validate scopes ('read:students', 'download:students')
    API->>DB: Query active candidate profiles & relationships
    API->>Storage: Resolve document files & photos to base64 Data URIs
    API->>Svc: getMultipleStudentsData(students, includeDocuments=true)
    Svc->>Svc: Compile structured JSON dossiers
    API->>Svc: generatePdf(studentsData, includeDocuments=true)
    Svc->>API: Render A4 PDF binary stream
    API->>Audit: Log 'student_information_sheet_downloaded'
    API-->>Officer: Stream multi-student PDF document (Content-Disposition: attachment)
```

---

## 3. Authentication & API Scopes

All Student Information Sheet endpoints support two distinct authentication strategies:

1. **API Key Authentication (`auth.api_key`):**
   - Header: `X-API-Key: sntc_live_xxxxxxxxxxxxxxxxxxxxxxxx` or `Authorization: Bearer sntc_live_xxxxxxxxxxxxxxxxxxxxxxxx`.
   - Used by administrative kiosks, desktop printing software, internal microservices, and external systems.
2. **Sanctum Token Authentication (`auth:sanctum`):**
   - Header: `Authorization: Bearer <sanctum_personal_access_token>`.
   - Used by native iOS / Android mobile applications and web client SPAs.

### Required Scopes:

| Scope | Description | Allowed Endpoints |
|---|---|---|
| `read:students` | Read student registry, profile attributes, and information sheet JSON payloads | `GET /students/information-sheets`, `GET /students/{id}/information-sheet`, `POST /students/information-sheets/bulk` |
| `download:students` | Permission to generate, compile, and stream PDF information sheets | `GET /students/{id}/information-sheet/download`, `POST /students/information-sheets/download` |
| `Student Auth` | Authenticated student self-service scope | `GET /student/information-sheet`, `GET /student/information-sheet/download` |

---

## 4. Endpoints Reference

### 4.1. List Information Sheets Directory Summary
`GET /api/v1/students/information-sheets`

Query and paginate candidate summary cards available for information sheet generation.

#### Request Headers:
```http
X-API-Key: sntc_live_xxxxxxxxxxxxxxxxxxxxxxxx
Accept: application/json
```

#### Query Parameters:
| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `search` | string | No | null | Search candidate name, student ID, URN, phone, or email |
| `course_id` | integer | No | null | Filter candidates enrolled in specific course ID |
| `batch_id` | integer | No | null | Filter candidates enrolled in specific batch ID |
| `gender` | string | No | null | Filter by gender (`Male`, `Female`, `Other`) |
| `category` | string | No | null | Filter by caste category (`UR`, `SC`, `ST`, `OBC-A`, `OBC-B`, `EWS`) |
| `status` | string | No | null | Account status (`active`, `inactive`, `suspended`) |
| `per_page` | integer | No | 15 | Pagination size (1 to 100) |
| `page` | integer | No | 1 | Page number |

#### Response `200 OK`:
```json
{
  "success": true,
  "data": [
    {
      "id": 1,
      "uuid": "4f50ef78-8311-4773-863a-bbad91f09201",
      "student_id": "202610001",
      "urn": "URN-2026-0001",
      "name": "Abhishek Mukherjee",
      "email": "abhishek.m@example.com",
      "phone": "9830112233",
      "gender": "Male",
      "category": "UR",
      "status": "active",
      "batch_name": "Civil Services Morning Cohort 2026-2027",
      "course_name": "UPSC Civil Services Foundation",
      "documents_count": 4,
      "endpoints": {
        "sheet": "https://mis.sntcssc.in/api/v1/students/1/information-sheet",
        "download_pdf": "https://mis.sntcssc.in/api/v1/students/1/information-sheet/download"
      }
    }
  ],
  "meta": {
    "current_page": 1,
    "per_page": 15,
    "total": 128,
    "last_page": 9,
    "metadata": {
      "institution_name": "Satyendranath Tagore Civil Services Study Centre",
      "institution_sub": "Government of West Bengal",
      "institution_address": "Netaji Subhas Administrative Training Institute Campus, Block FC, Sector III, Salt Lake, Kolkata - 700106",
      "generated_at": "09 Nov 2026, 11:30 AM"
    }
  }
}
```

---

### 4.2. Get Single Student Information Sheet Dossier
`GET /api/v1/students/{identifier}/information-sheet`

Fetches the complete, comprehensive JSON payload for a single student. `{identifier}` accepts database `id`, universal `uuid`, 9-digit `student_id`, system `urn`, phone, or email.

#### Query Parameters:
| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `include_documents` | boolean | No | `false` | When `true`, includes supporting documents metadata and base64 data URIs |
| `with_trashed` | boolean | No | `false` | When `true`, allows retrieval of soft-deleted student records |

#### Response `200 OK`:
```json
{
  "success": true,
  "data": {
    "metadata": {
      "institution_name": "Satyendranath Tagore Civil Services Study Centre",
      "institution_sub": "Government of West Bengal",
      "institution_address": "Netaji Subhas Administrative Training Institute Campus, Block FC, Sector III, Salt Lake, Kolkata - 700106",
      "generated_at": "09 Nov 2026, 11:30 AM"
    },
    "identity": {
      "id": 1,
      "uuid": "4f50ef78-8311-4773-863a-bbad91f09201",
      "name": "Abhishek Mukherjee",
      "student_id": "202610001",
      "urn": "URN-2026-0001",
      "email": "abhishek.m@example.com",
      "phone": "9830112233",
      "alternate_phone": "9830998877",
      "dob": "15 Aug 1999",
      "age": 27,
      "gender": "Male",
      "category": "UR",
      "physically_challenged": "No",
      "pwd_type": null,
      "blood_group": "B+",
      "status": "active",
      "photo_url": "https://mis.sntcssc.in/storage/avatars/user-1.jpg",
      "photo_data_uri": "data:image/jpeg;base64,/9j/4AAQSkZJRg...",
      "initials": "AM"
    },
    "family": {
      "father_name": "Debabrata Mukherjee",
      "mother_name": "Sutapa Mukherjee",
      "guardian_name": "Debabrata Mukherjee",
      "parent_phone": "9830114455",
      "family_annual_income": "₹ 6,50,000",
      "occupation": "Government Service"
    },
    "addresses": {
      "permanent": {
        "address": "Flat 4B, Greenfield Heights, Action Area I",
        "city": "New Town",
        "district": "North 24 Parganas",
        "state": "West Bengal",
        "pincode": "700156",
        "landmark": "Near Pride Hotel"
      },
      "correspondence": {
        "address": "Room 204, Administrative Hostel, Salt Lake",
        "city": "Kolkata",
        "district": "Kolkata",
        "state": "West Bengal",
        "pincode": "700106",
        "landmark": "Near NSATI Gate 2"
      }
    },
    "educations": [
      {
        "qualification": "Post Graduation",
        "degree": "M.Sc. Economics",
        "board_university": "Calcutta University",
        "institute": "Department of Economics",
        "passing_year": 2022,
        "percentage": "78.50%",
        "grade": "A+",
        "division": "First"
      },
      {
        "qualification": "Graduation",
        "degree": "B.Sc. (Hons) Economics",
        "board_university": "Presidency University",
        "institute": "Main Campus",
        "passing_year": 2020,
        "percentage": "82.00%",
        "grade": "First Class",
        "division": "First"
      }
    ],
    "employments": [
      {
        "designation": "Research Associate",
        "organization": "Centre for Development Studies",
        "organization_type": "Autonomous Research Institute",
        "from_date": "01 Jan 2023",
        "to_date": "Present",
        "is_current": true
      }
    ],
    "upsc_attempts": [
      {
        "exam_year": 2025,
        "roll_number": "0845129",
        "optional_subject": "Economics",
        "stage_reached": "Interview (Personality Test)"
      }
    ],
    "active_enrollment": {
      "batch_name": "Civil Services Morning Cohort 2026-2027",
      "course_name": "UPSC Civil Services Foundation",
      "section_name": "Section A",
      "roll_no": "CS-2026-004",
      "enrollment_no": "ENR-2026-0012",
      "enrollment_date": "01 Jul 2026",
      "status": "Active"
    },
    "documents": [
      {
        "id": 12,
        "type": "Caste Certificate",
        "file_name": "caste-certificate.pdf",
        "web_url": "https://mis.sntcssc.in/storage/documents/caste-12.pdf",
        "is_image": false,
        "verified": true,
        "verified_at": "05 Jul 2026",
        "uploaded_at": "02 Jul 2026"
      }
    ]
  },
  "meta": {
    "student_id": "202610001",
    "urn": "URN-2026-0001",
    "retrieved_at": "2026-11-09T11:30:00+05:30",
    "documents_included": false
  }
}
```

---

### 4.3. Bulk Fetch Information Sheets JSON Payloads
`POST /api/v1/students/information-sheets/bulk`

Bulk-compile up to 100 student information sheets in a single request.

#### Request Body:
```json
{
  "ids": [1, 2, 3, "202610004", "URN-2026-0005"],
  "include_documents": false
}
```

#### Response `200 OK`:
```json
{
  "success": true,
  "data": [
    { /* Complete Sheet 1 */ },
    { /* Complete Sheet 2 */ }
  ],
  "meta": {
    "count": 2,
    "requested_count": 5,
    "documents_included": false,
    "metadata": { /* Institution Metadata */ }
  }
}
```

---

### 4.4. Stream or Download Single / Multi-Student PDF
`GET /api/v1/students/{identifier}/information-sheet/download`  
`POST /api/v1/students/information-sheets/download`

Generates publication-quality PDF documents.

#### Query / Body Parameters:
| Parameter | Type | Required | Default | Description |
|---|---|---|---|---|
| `ids` | array / string | Conditional | null | Array or comma-delimited list of IDs (required for bulk endpoint) |
| `include_documents`| boolean | No | `false` | When `true`, appends document annexure pages with base64 visual renders |
| `disposition` | string | No | `attachment` | Set to `inline` for browser PDF viewer preview or `attachment` to force download |
| `format` | string | No | `pdf` | Set to `base64` or send `Accept: application/json` for API test environments |

#### Binary Output:
- **Content-Type:** `application/pdf`
- **Content-Disposition:** `attachment; filename="Student-Information-Sheet-202610001-20261109.pdf"`

#### Base64 Output (when `format=base64` or requested via Developer Console):
```json
{
  "success": true,
  "data": {
    "filename": "Student-Information-Sheet-202610001-20261109.pdf",
    "student_count": 1,
    "documents_included": false,
    "file_size_bytes": 142850,
    "mime_type": "application/pdf",
    "base64_pdf": "JVBERi0xLjQKMSAwIG9iago8PA...",
    "download_url": "https://mis.sntcssc.in/api/v1/students/1/information-sheet/download?format=binary"
  }
}
```

---

### 4.5. Student Self-Service Endpoints
`GET /api/v1/student/information-sheet`  
`GET /api/v1/student/information-sheet/download`

Allows authenticated students to access their own information sheet via Sanctum token.

#### Request Headers:
```http
Authorization: Bearer 34|5xY49fE7b0...
Accept: application/json
```

---

## 5. Multi-Platform Integration Code Samples

### 5.1. cURL (Command Line & Testing)
```bash
# 1. Fetch JSON Dossier for Candidate
curl -X GET "https://mis.sntcssc.in/api/v1/students/202610001/information-sheet?include_documents=1" \
  -H "X-API-Key: sntc_live_sample_key" \
  -H "Accept: application/json"

# 2. Download Official Printable PDF
curl -X GET "https://mis.sntcssc.in/api/v1/students/202610001/information-sheet/download" \
  -H "X-API-Key: sntc_live_sample_key" \
  --output "Student_202610001_Information_Sheet.pdf"

# 3. Bulk Download PDF for a Cohort
curl -X POST "https://mis.sntcssc.in/api/v1/students/information-sheets/download" \
  -H "X-API-Key: sntc_live_sample_key" \
  -H "Content-Type: application/json" \
  -d '{"ids": [1, 2, 3], "include_documents": false}' \
  --output "Batch_Information_Sheets.pdf"
```

### 5.2. Flutter / Dart (Student & Staff Mobile App)
```dart
import 'dart:convert';
import 'dart:io';
import 'package:http/http.dart' as http;
import 'package:path_provider/path_provider.dart';

class StudentInformationSheetClient {
  final String baseUrl;
  final String apiKey;

  StudentInformationSheetClient({
    this.baseUrl = 'https://mis.sntcssc.in/api/v1',
    required this.apiKey,
  });

  Map<String, String> get _headers => {
    'X-API-Key': apiKey,
    'Accept': 'application/json',
  };

  /// Fetch full candidate information sheet payload
  Future<Map<String, dynamic>> fetchStudentSheet(String identifier, {bool includeDocuments = false}) async {
    final uri = Uri.parse('$baseUrl/students/$identifier/information-sheet?include_documents=$includeDocuments');
    final response = await http.get(uri, headers: _headers);

    if (response.statusCode == 200) {
      final decoded = json.decode(response.body);
      return decoded['data'] as Map<String, dynamic>;
    } else {
      throw Exception('Failed to load student sheet: ${response.statusCode} - ${response.body}');
    }
  }

  /// Download and persist official PDF sheet to device storage
  Future<File> downloadPdfSheet(String identifier, {bool includeDocuments = false}) async {
    final uri = Uri.parse('$baseUrl/students/$identifier/information-sheet/download?include_documents=$includeDocuments');
    final response = await http.get(uri, headers: _headers);

    if (response.statusCode == 200) {
      final dir = await getApplicationDocumentsDirectory();
      final file = File('${dir.path}/student_sheet_$identifier.pdf');
      await file.writeAsBytes(response.bodyBytes);
      return file;
    } else {
      throw Exception('Failed to download PDF: ${response.statusCode}');
    }
  }
}
```

### 5.3. React Native / TypeScript
```typescript
import axios from 'axios';
import RNFS from 'react-native-fs';

const api = axios.create({
  baseURL: 'https://mis.sntcssc.in/api/v1',
  headers: {
    'X-API-Key': 'sntc_live_sample_key',
    'Accept': 'application/json',
  },
});

export interface StudentSheetResponse {
  identity: {
    student_id: string;
    name: string;
    photo_url: string;
    dob: string;
    category: string;
  };
  family: Record<string, string>;
  addresses: Record<string, any>;
  educations: Array<any>;
  employments: Array<any>;
  upsc_attempts: Array<any>;
  active_enrollment: Record<string, any>;
  documents: Array<any>;
}

export async function getStudentSheet(identifier: string | number): Promise<StudentSheetResponse> {
  const response = await api.get(`/students/${identifier}/information-sheet`);
  return response.data.data;
}

export async function downloadSheetPdf(identifier: string | number, localPath: string): Promise<string> {
  const downloadUrl = `https://mis.sntcssc.in/api/v1/students/${identifier}/information-sheet/download`;
  const result = await RNFS.downloadFile({
    fromUrl: downloadUrl,
    toFile: localPath,
    headers: {
      'X-API-Key': 'sntc_live_sample_key',
    },
  }).promise;

  if (result.statusCode === 200) {
    return localPath;
  }
  throw new Error(`PDF download failed with status ${result.statusCode}`);
}
```

### 5.4. Python (Data Pipeline & Kiosk Gatekeeper)
```python
import requests

BASE_URL = "https://mis.sntcssc.in/api/v1"
API_KEY = "sntc_live_sample_key"

headers = {
    "X-API-Key": API_KEY,
    "Accept": "application/json"
}

def get_student_information_sheet(identifier: str, include_documents: bool = False):
    """Retrieve full structured JSON dossier"""
    url = f"{BASE_URL}/students/{identifier}/information-sheet"
    params = {"include_documents": str(include_documents).lower()}
    response = requests.get(url, headers=headers, params=params)
    response.raise_for_status()
    return response.json()["data"]

def download_batch_information_sheets(student_ids: list[int], output_path: str):
    """Compile and save multi-student PDF sheet"""
    url = f"{BASE_URL}/students/information-sheets/download"
    payload = {"ids": student_ids, "include_documents": False}
    response = requests.post(url, headers=headers, json=payload, stream=True)
    response.raise_for_status()
    
    with open(output_path, "wb") as f:
        for chunk in response.iter_content(chunk_size=8192):
            f.write(chunk)
    print(f"Successfully saved consolidated PDF to {output_path}")

if __name__ == "__main__":
    sheet = get_student_information_sheet("202610001")
    print(f"Loaded sheet for {sheet['identity']['name']} ({sheet['identity']['student_id']})")
    download_batch_information_sheets([1, 2], "sample_cohort_sheets.pdf")
```

### 5.5. C# / .NET (WPF / Windows Service Terminal)
```csharp
using System;
using System.IO;
using System.Net.Http;
using System.Net.Http.Headers;
using System.Text;
using System.Text.Json;
using System.Threading.Tasks;

public class SntcsscSheetClient
{
    private readonly HttpClient _client;

    public SntcsscSheetClient(string apiKey, string baseUrl = "https://mis.sntcssc.in/api/v1/")
    {
        _client = new HttpClient { BaseAddress = new Uri(baseUrl) };
        _client.DefaultRequestHeaders.Add("X-API-Key", apiKey);
        _client.DefaultRequestHeaders.Accept.Add(new MediaTypeWithQualityHeaderValue("application/json"));
    }

    public async Task<JsonDocument> GetStudentSheetAsync(string identifier)
    {
        var response = await _client.GetAsync($"students/{identifier}/information-sheet");
        response.EnsureSuccessStatusCode();
        var jsonString = await response.Content.ReadAsStringAsync();
        return JsonDocument.Parse(jsonString);
    }

    public async Task DownloadSheetPdfAsync(string identifier, string targetFilePath)
    {
        var response = await _client.GetAsync($"students/{identifier}/information-sheet/download");
        response.EnsureSuccessStatusCode();
        await using var stream = await response.Content.ReadAsStreamAsync();
        await using var fileStream = new FileStream(targetFilePath, FileMode.Create, FileAccess.Write);
        await stream.CopyToAsync(fileStream);
    }
}
```

---

## 6. Error Codes & Exception Handling

All API responses follow a standardized JSON envelope when an error occurs:

```json
{
  "success": false,
  "error": {
    "code": "STUDENT_NOT_FOUND",
    "message": "No student found matching identifier '999999999'.",
    "details": null
  }
}
```

| HTTP Status | Error Code | Description / Recovery Action |
|---|---|---|
| `400 Bad Request` | `INVALID_ARGUMENT` | Malformed parameter or unparseable JSON payload |
| `401 Unauthorized` | `API_KEY_MISSING` / `API_KEY_INVALID` | Check `X-API-Key` or `Authorization: Bearer` token in request headers |
| `403 Forbidden` | `INSUFFICIENT_SCOPE` | Key lacks required scope (`read:students` or `download:students`). Reissue key with correct scopes in Admin Console |
| `404 Not Found` | `STUDENT_NOT_FOUND` | No candidate profile matches the provided ID, UUID, Student ID, or phone |
| `422 Unprocessable` | `VALIDATION_ERROR` | Validation failed on input parameters (e.g., missing `ids` array) |
| `422 Unprocessable` | `BULK_LIMIT_EXCEEDED` | Attempted to process more than 100 students in a single batch request |
| `500 Server Error` | `PDF_GENERATION_FAILED` | Internal error while compiling PDF. Check server logs or DomPDF memory allocations |

---

## 7. Security, Auditing & Compliance Guidelines

1. **Immutable Audit Logging:** Every read and download request automatically triggers an asynchronous audit event via `App\Services\AuditLogService` tracking `user_id`, `ip_address`, `user_agent`, `url`, and target `auditable_id`.
2. **Access Control & Multi-Tenancy:** Routes are scoped to team permissions via `EnsureTeamMembership` where applicable.
3. **Data Protection & PII:** In accordance with personal data protection practices, student phone numbers, addresses, and parent income are strictly gated behind authenticated API scopes.
4. **Rate Limiting:** API endpoints are protected with standard throttle limits (60 requests per minute for standard API keys; burst capacity available for dedicated printing kiosks).
5. **PDF Offline Resilience:** Image assets and logos are embedded directly as base64 Data URIs (`data:image/jpeg;base64,...`), ensuring the PDF documents can be generated and viewed in air-gapped or offline kiosk environments.

---

*Document Version:* `1.0.0`  
*Target Environment:* Production Ready (PHP 8.5 / Laravel 12 / DomPDF / Livewire 4)  
*Maintained By:* SNTCSSC MIS Technical Architecture Team
