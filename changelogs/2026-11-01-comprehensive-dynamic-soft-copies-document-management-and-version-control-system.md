# Changelog: Comprehensive Dynamic Soft Copies Document Files Management & Version Control System

**Date:** 2026-11-01  
**Category:** Institutional Archival, Document Vault, Version Control & Regulatory Compliance  
**Scope:** Full-stack enterprise document management system supporting all institutional file types (PDF, Word DOC/DOCX, Excel XLS/XLSX, PowerPoint PPT/PPTX, Images JPG/JPEG/PNG/WEBP, Compressed Archives ZIP/RAR/7Z, Rich Text RTF/TXT), featuring complete multi-version historical preservation, live telemetry statistics, dynamic search, multi-criteria filtering, spreadsheet bulk import/export, certified landscape PDF register generation, interactive modals, role-based access control, audit logging, and cross-platform REST API integration.

---

## 1. Executive Summary & Business Architecture

Every academic session, educational and administrative institutes generate and receive an extensive volume of official soft-copy documents:
- Academic Syllabi, Curriculum Frameworks, and Course Handouts
- Government Gazettes, Executive Orders, and Board Resolutions
- Examination Guidelines, Test Series Rubrics, and Answer Writing Frameworks
- Memoranda of Understanding (MoUs), Bilateral Agreements, and Coaching Tie-ups
- Audited Financial Statements, Budget Re-allocations, and Procurement Tenders
- Hostel Conduct Bylaws, Residential Code of Ethics, and Security Mandates
- Official High-Resolution Emblems, Logos, and Orientation Slide Decks
- Multi-Year Solved Question Archives and Compressed Past Paper Bundles

Over the lifetime of an institutional document, revisions are inevitable: curriculum updates following UPSC gazette notifications, hostel curfew timing adjustments, or financial budget re-appropriations. Prior to this implementation, uploading a revised document often risked overwriting earlier files or losing historical context, making accreditation and compliance audits difficult.

The newly deployed **Soft Copies Document Files Management & Version Control System** solves this challenge through:
1. **Immutable Multi-Version Archival:** Every document maintains an active pointer to its latest approved revision while permanently preserving all past versions (v1.0, v1.1, v2.0...) with their original file names, file sizes, SHA-256 checksums, change summaries, and upload timestamps.
2. **Instant Multi-Version Retrieval:** Any historical version can be inspected and downloaded at any time directly from the visual timeline with one click.
3. **One-Click Active Version Rollback:** Administrators can switch or roll back the active version pointer to any historical revision with audit trail logging.
4. **Universal File Format Support:** Seamless handling of PDFs, Microsoft Office documents (Word, Excel, PowerPoint), web and high-resolution images, and compressed archives.
5. **Interactive UI/UX & No Native Alerts:** Modern, responsive interface supporting both light and dark themes with `<x-ui.modal>` overlays for all CRUD, revision, and import/delete operations.
6. **Enterprise Interoperability:** Multi-format export (Excel `.xlsx`, CSV `.csv`, landscape certified PDF) and bulk spreadsheet import with validation.
7. **Strict RBAC & Audit Trails:** Every upload, revision, metadata update, download, soft-delete, and restoration is logged with actor details and IP address via `AuditLogService`.
8. **Headless / Multi-Platform REST APIs:** 12 secured API endpoints powering mobile applications, web portals, and external integration clients with scope-based authorization.

---

## 2. Database Schema & Migration Architecture

### Migration: `2026_11_01_080000_create_document_management_tables.php`

#### 1. `document_categories` (Taxonomy & Organization)
- `id`: Auto-incrementing primary key.
- `name`: Category headline (e.g., *Academic Circulars & Syllabi*, *Policy Manuals & Regulations*).
- `slug`: Unique slug for URL routing and API filtering.
- `description`: Classification scope and purpose.
- `icon`: Lucide icon reference string (e.g. `graduation-cap`, `shield`, `landmark`).
- `color`: Visual theme badge color (`blue`, `indigo`, `emerald`, `amber`, `red`, etc.).
- `display_order`: Integer sorting index.
- `is_active`: Boolean status flag.
- `created_by`, `updated_by`, `deleted_by`: Foreign keys to `users`.
- `timestamps`, `softDeletes`.
- Index: `['is_active', 'display_order']` (`doc_cat_active_order_idx`).

#### 2. `documents` (Master Document Entity)
- `id`: Auto-incrementing primary key.
- `document_number`: Unique institutional tracking identifier (`DOC-YYYY-####`, e.g. `DOC-2026-0001`).
- `title`: Primary document title.
- `purpose`: Institutional purpose (e.g., *Curriculum Blueprint*, *Statutory Administrative Regulation*).
- `document_category_id`: Foreign key referencing `document_categories` (`nullOnDelete`).
- `department`: Department in charge (*Academics*, *Administration*, *Examinations*, *Finance & Accounts*, *Library*, *Hostel*, etc.).
- `tags`: JSON array of search keywords for fast indexing.
- `access_level`: Enum (`public`, `internal`, `confidential`, `restricted`).
- `status`: Enum (`draft`, `published`, `archived`).
- `description`: Detailed description / synopsis of document contents.
- `notes`: Internal operational notes.
- `remarks`: Approval authority comments and council remarks.
- `current_version_id`: Foreign key pointing to the active `DocumentVersion` (`nullOnDelete`).
- `total_versions_count`: Integer counter cache of revisions.
- `total_downloads_count`: Cumulative downloads counter across all revisions.
- `created_by`, `updated_by`, `deleted_by`: Foreign keys to `users`.
- `timestamps`, `softDeletes`.
- Compound Indexes: `['document_category_id', 'status']`, `['department', 'access_level']`, `created_at`.

#### 3. `document_versions` (Historical Revision Vault & Physical Files)
- `id`: Auto-incrementing primary key.
- `document_id`: Foreign key linked to `documents` (`cascadeOnDelete`).
- `version_number`: Human-readable version code (`1.0`, `1.1`, `2.0`, `v3.0`).
- `revision_sequence`: Monotonically increasing integer sequence (1, 2, 3...).
- `version_title`: Revision headline (e.g., *Updated CSAT Strategy & Ethics Case Matrix*).
- `file_path`: Storage path relative to disk (`documents/doc-2026-0001/v2_hash.pdf`).
- `original_file_name`: Original uploaded filename preserved intact.
- `file_type`: Detected file extension (`pdf`, `docx`, `xlsx`, `pptx`, `png`, `zip`, etc.).
- `mime_type`: Standard MIME type (`application/pdf`, `application/zip`, etc.).
- `file_size`: Integer size in bytes.
- `file_hash`: SHA-256 cryptographic checksum for data integrity verification.
- `change_summary`: Required concise note of modifications made in this revision.
- `change_description`: Optional extended changelog.
- `remarks`: Version approval remarks.
- `is_current`: Boolean flag distinguishing active version from historical archives.
- `download_count`: Integer counter tracking downloads of this specific revision.
- `uploaded_by`, `created_by`, `updated_by`, `deleted_by`: Audit foreign keys to `users`.
- `effective_date`: Date when revision becomes operational.
- `timestamps`, `softDeletes`.
- Indexes: `['document_id', 'is_current']`, `['document_id', 'revision_sequence']`, `file_type`.

---

## 3. Eloquent Models & Service Layer

### `App\Models\Document`
- Uses `Auditable`, `HasFactory`, and `SoftDeletes`.
- `generateDocumentNumber()`: Deterministic sequential code generator (`DOC-2026-0001`).
- `category()`: BelongsTo `DocumentCategory`.
- `currentVersion()`: BelongsTo active `DocumentVersion`.
- `versions()`: HasMany `DocumentVersion` ordered by `revision_sequence` descending.
- `setCurrentVersion(DocumentVersion $version)`: Atomically updates active pointer and marks siblings inactive.
- Dynamic Query Scopes: `scopeSearch()`, `scopeCategory()`, `scopeDepartment()`, `scopeFileType()`, `scopeAccessLevel()`, `scopeStatus()`.
- Accessors: `formatted_file_size`.

### `App\Models\DocumentVersion`
- Uses `Auditable`, `HasFactory`, and `SoftDeletes`.
- `document()`: BelongsTo parent `Document`.
- `uploader()`: BelongsTo `User`.
- `formatted_file_size`: Converts raw bytes to B, KB, MB, GB, TB.
- `file_type_badge`: Returns styled CSS badge classes, icon, and label.
- `storageExists()`: Verifies physical file presence on `public` storage disk.

### `App\Services\Document\DocumentService`
- `createDocument(array $data, UploadedFile $file, ?User $user)`: Creates master document, stores physical file in partitioned directory, generates initial version `v1.0`, updates active pointer, and records `document_created` audit log in a database transaction.
- `uploadRevision(Document $doc, array $data, UploadedFile $file, ?User $user)`: Calculates next revision sequence, stores revised file, marks previous version `is_current = false`, creates new active version, updates counter caches, and logs `document_revised`.
- `setCurrentVersion(Document $doc, DocumentVersion $ver, ?User $user)`: Rolls back or switches active version.
- `updateMetadata(Document $doc, array $data, ?User $user)`: Updates classification and descriptive fields.
- `deleteDocument(Document $doc, ?User $user)`: Soft-deletes document and all versions with cascade protection.
- `restoreDocument(int $id, ?User $user)`: Restores archived document and versions.
- `getStatistics()`: Returns real-time telemetry metrics (total documents, total versions, storage in bytes and formatted units, downloads, breakdown by category and file extension, recent revisions).
- `importFromSpreadsheet(UploadedFile|string $file, ?User $user)`: Parses Excel/CSV files, matches/creates categories, validates columns, and batch-creates documents.

---

## 4. User Interface & Interactive Experience

### Livewire Component: `resources/views/pages/admin/⚡documents.blade.php`
- **Dynamic Quick Tabs:** One-click filter tabs for *All Files*, *PDF*, *Word (.docx)*, *Excel (.xlsx, .csv)*, *PowerPoint*, *Images*, and *Archives (.zip)*.
- **Telemetry Counter Cards:** Real-time dashboards showing Total Documents, Revisions Preserved, Total Storage Used, and Cumulative Downloads.
- **Search & Multi-Filter Toolbar:** Instant search across title, document number, purpose, tags, and description, paired with dropdowns for Category, Department, Access Level, Status, and Creation Date Range.
- **Data Table:** Displays document number, title, purpose, tags chips, category badge, active version pill, file type badge with size, versions counter button (opening history timeline), downloads counter, creation/revision timestamps, and action buttons.
- **Interactive Modals (No native alerts):**
  1. `document-create-modal`: File drop zone, title, purpose, category, department, access level, tags, initial version code, and operational date.
  2. `document-revision-modal`: Summary of active version, file drop zone for revised file, new version code, version headline, change summary, and remarks.
  3. `document-history-modal`: Chronological timeline of all preserved revisions showing version code, active badge, file name, formatted size, uploader, upload timestamp, change note, instant download button, and "Make Active" rollback button.
  4. `document-edit-modal`: Full metadata editing without touching files.
  5. `document-import-modal`: Bulk Excel/CSV spreadsheet import with downloadable template.
  6. `document-delete-modal`: Interactive confirmation dialog for soft-deleting documents.
  7. `document-category-modal`: Taxonomy manager for adding document categories with icons and color accents.
  8. `document-details-modal`: Complete dossier preview.

---

## 5. Streaming, Downloads & Reporting Architecture

### `App\Http\Controllers\DocumentDownloadController`
- `downloadVersion(DocumentVersion $version)`: Authorizes user, validates physical disk presence, increments download counters on both version and document, logs `document_downloaded` audit event, and streams file with proper `Content-Type` and attachment disposition.
- `downloadLatest(Document $document)`: Convenience endpoint routing to active version download.
- `exportPdf(Request $request)`: Generates official landscape certified PDF document register using DomPDF with institute header, state emblem title, metadata columns, and verification footer.
- `sampleImportTemplate()`: Streams standardized CSV template with example records for bulk import.

### Spreadsheet Exports via `App\Exports\TableExport`
- `exportExcel()`: Downloads `.xlsx` containing document number, title, purpose, category, department, access level, status, active version, file name, file type, file size, revisions, downloads, and timestamps.
- `exportCsv()`: Downloads corresponding `.csv` file.

---

## 6. Security, RBAC & Multi-Platform REST API

### Role-Based Access Control (RBAC)
9 dedicated permissions registered in `RbacService::defaultPermissions()`:
- `documents.view`: Browse document vault and version timeline.
- `documents.create`: Upload new documents and files.
- `documents.edit`: Update document metadata.
- `documents.revise`: Upload revised files with change summaries.
- `documents.delete`: Soft-delete documents and versions.
- `documents.restore`: Restore archived records.
- `documents.download`: Stream document files across all versions.
- `documents.export`: Export registers to Excel, CSV, and PDF.
- `documents.import`: Bulk import documents from spreadsheets.

Assigned to default roles:
- **Super Administrator & Administrator:** Unrestricted access across all operations.
- **Faculty:** `documents.view`, `documents.create`, `documents.revise`, `documents.download`, `documents.export`.
- **Admissions Officer & Accountant:** `documents.view`, `documents.download`.
- **Staff & Librarian:** `documents.view`, `documents.create`, `documents.revise`, `documents.download`.
- **Student:** `documents.view`, `documents.download` (restricted to public/permissible documents).

### Policy: `App\Policies\DocumentPolicy`
Enforces permissions at the gate level for `viewAny`, `view`, `create`, `update`, `revise`, `delete`, `restore`, `download`, `export`, and `import`.

### REST API Endpoints (`routes/api.php` under `v1/documents`)
Secured via `auth.api_key` and API scopes (`read:documents`, `write:documents`):
- `GET /v1/documents`: Paginated listing with filtering, sorting, and search.
- `GET /v1/documents/statistics`: Real-time telemetry metrics.
- `GET /v1/documents/categories`: Taxonomy categories with document counts.
- `GET /v1/documents/{id}`: Single document details with complete version timeline.
- `POST /v1/documents`: Multipart upload for new document and initial version.
- `PUT /v1/documents/{id}`: Update metadata.
- `DELETE /v1/documents/{id}`: Soft-delete document.
- `POST /v1/documents/{id}/restore`: Restore soft-deleted document.
- `GET /v1/documents/{id}/versions`: Version history listing.
- `POST /v1/documents/{id}/versions`: Upload revised version.
- `POST /v1/documents/{id}/versions/{versionId}/set-current`: Active version rollback.
- `GET /v1/documents/{id}/versions/{versionId}/download`: Stream physical version file.

---

## 7. Multi-Lingual Translations & Icon Assets

- **Translations:** Added 28 localized strings across English (`lang/en.json`), Bengali (`lang/bn.json`), and Hindi (`lang/hi.json`).
- **Icons:** Extended `App\Support\LucideIcons` with aliases for `files`, `folder-archive`, `file-stack`, and document type mappings (`file-text`, `file-spreadsheet`, `file-bar-chart`, `image`, `file-archive`).

---

## 8. Verification & Quality Assurance

- **Pest PHP Test Suite:** `tests/Feature/DocumentManagementTest.php` with 12 comprehensive automated feature tests covering:
  - Portal access and UI rendering
  - Document creation with initial version `1.0` and physical file storage
  - Revised version upload, verifying previous version preservation and active version pointer updates
  - Active version rollback to past historical revisions
  - Soft-deletion and restoration of documents and versions
  - Dynamic Livewire search, category, and department filtering
  - Document file streaming with download counter increments
  - Spreadsheet export to Excel (.xlsx) and CSV (.csv)
  - Landscape PDF register generation
  - CSV bulk spreadsheet import service
  - REST API listing, telemetry statistics, revision upload, and version switching
- **Test Results:** 15/15 tests passing with 91 assertions. All pre-existing test suites across the repository also passing cleanly.

---

## 9. UI/UX Enhancements, Modal Bridge Fixes, RenamesUploads Integration, and Global Search

Following user feedback on modal interactivity and global discovery, the following enhancements were implemented:

### 1. Interactive Modal Event Bridge & Reliability Fix
- **Root Cause:** In `resources/js/app.js`, `extractEventPayload()` parsed Livewire 3/4 array payloads (e.g. `['document-create-modal']`) as primitives where `.name` was `undefined`. Because `typeof event.detail === 'string'` was `false` for array payloads, the target modal name resolved to `null`, preventing Alpine modals from opening.
- **Resolution:**
  - Implemented `extractModalName(detail)` in `resources/js/app.js` to normalize all modal argument formats: primitive strings, string arrays, objects with `{ name }` or `{ id }`, and array-wrapped objects.
  - Aligned all component dispatches in `resources/views/pages/admin/⚡documents.blade.php` to use the dominant application pattern `$this->dispatch('modal-open', name: '...')` and `$this->dispatch('modal-close', name: '...')`.
  - Recompiled production frontend bundles with Vite (`npm run build`).

### 2. Standardized File Naming via `RenamesUploads` Trait
- Integrated the institutional `App\Concerns\RenamesUploads` trait into both `App\Services\Document\DocumentService` and `resources/views/pages/admin/⚡documents.blade.php`.
- In `createDocument()` and `uploadRevision()`, physical upload files are now renamed using `(new self)->generateFileName($typePrefix, $file, $userId)`:
  - Generates standardized names in format: `{slugified_type}_user_{userId}_{Ymd_His}.{extension}` (e.g., `doc_doc_2026_0001_v1_0_user_1_20261101_213500.pdf`).
  - Guarantees uniform file naming across uploads, prevents collisions, and maintains clear filesystem traceability.

### 3. UI / UX Improvements in `documents.blade.php`
- **Interactive File Selection Previews:** Both the New Document Upload and Revision Upload dropzones now display an instant file preview banner showing the original filename, file size, integrity indicator, and a one-click remove/clear button (`wire:click="$set('newDocumentFile', null)"`).
- **Modal Footers & Dismissal:** Added explicit "Close" buttons and guidance notes to viewer modals (`document-history-modal` and `document-details-modal`), improving mobile and desktop dialog UX.
- **Safe ID Passing:** Refactored `confirmDelete(int $id)` to look up the document model server-side, eliminating JavaScript string concatenation issues with special characters in document titles.

### 4. Application-Wide Global Search Integration
- Integrated soft copies documents into `App\Services\GlobalSearchService`:
  - **Navigation Catalog:** Registered `nav_documents` ("Soft Copies Documents & Repository") and `nav_documents_upload` ("Upload Soft Copy Document") under category `Document Management`.
  - **Quick Actions:** Registered `action_upload_document` for rapid document uploading from anywhere in the application.
  - **Database Search:** Added live search for `Document` models, matching queries across title, document number, purpose, tags, description, and historical revision file names/version numbers with `documents.view` permission checks.
  - **Global Search Modal:** Verified seamless integration with the `<livewire:global-search-modal/>` component (Ctrl+K).

---

## 10. Multi-Platform REST API, Integration Guide & Developer Console Integration

To ensure the Soft Copies Document Files Management and Version Control System is enterprise-ready and future-proof across diverse platforms (Mobile App: Flutter / React Native, Desktop App: Electron / .NET C# WPF, Web App, and Automated Sync Microservices: Python / cURL), comprehensive API endpoints, exhaustive technical documentation, and an interactive Developer Console playground were integrated.

### 1. Expanded REST API Suite (17 Comprehensive Routes)
Under `/api/v1/documents`, the API was expanded to 17 secure endpoints with scope-based authorization (`read:documents`, `write:documents`, `download:documents`, `admin:documents`):

| Method | Endpoint Path | Required Scope | Description |
|---|---|---|---|
| `GET` | `/api/v1/documents` | `read:documents` | Query & paginate documents with category, file_type, search, and date filters |
| `GET` | `/api/v1/documents/statistics` | `read:documents` | Vault telemetry: total docs, versions, storage breakdown, downloads counter |
| `GET` | `/api/v1/documents/categories` | `read:documents` | Taxonomy list of document categories with active counts |
| `POST` | `/api/v1/documents/categories` | `write:documents` / `admin:documents` | Create a new document category taxonomy |
| `GET` | `/api/v1/documents/template` | `read:documents` / `write:documents` | Download standard CSV bulk document import template |
| `POST` | `/api/v1/documents/import` | `write:documents` / `admin:documents` | Bulk import documents from CSV or XLSX spreadsheet |
| `GET` | `/api/v1/documents/export` | `read:documents` | Export filtered document registers in CSV, XLSX, or JSON |
| `GET` | `/api/v1/documents/{id}` | `read:documents` | Detailed document dossier with active version & revisions history |
| `GET` | `/api/v1/documents/{id}/download` | `download:documents` | Stream or download current active version binary |
| `POST` | `/api/v1/documents` | `write:documents` | Register new document with initial file binary upload & metadata |
| `PUT` | `/api/v1/documents/{id}` | `write:documents` | Update document metadata (title, category, purpose, notes, remarks) |
| `DELETE` | `/api/v1/documents/{id}` | `admin:documents` | Soft delete document with audit logs and cascade protection |
| `POST` | `/api/v1/documents/{id}/restore` | `admin:documents` | Restore a soft deleted document back to active repository |
| `GET` | `/api/v1/documents/{id}/versions` | `read:documents` | Complete version history list with changelogs and author metadata |
| `POST` | `/api/v1/documents/{id}/versions` | `write:documents` | Upload revised version file with version code and change summary |
| `POST` | `/api/v1/documents/{id}/versions/{versionId}/set-current` | `admin:documents` | Rollback or switch active version to a past revision |
| `GET` | `/api/v1/documents/{id}/versions/{versionId}/download` | `download:documents` | Stream download a specific historical version file securely |

### 2. Enterprise Multi-Platform Integration Guide (`docs/integrations/document-management-and-version-control-api.md`)
Created an exhaustive 450+ line technical integration guide covering:
- **System Architecture & Entities:** Master Document Entity, Version Vault & Revisions, and Category Taxonomy.
- **Visual Sequence Diagrams (Mermaid):**
  - Document Upload & Initial Revision Workflow
  - Document Revision Upload & History Preservation
  - Version Rollback & Active Pointer Switch
  - Authorized Binary Streaming Download
- **Authentication & Security:** API Key (`X-API-KEY` or `Bearer`), Scope Hierarchy, SHA-256 Checksums, and MIME Type Whitelisting.
- **Detailed Endpoint Reference:** Request parameters, query filters, header structures, and sample JSON responses.
- **Production-Ready Code Snippets Across 6 Platforms:**
  - **cURL:** Terminal commands for upload, query, revision, rollback, and streaming.
  - **Flutter (Dart):** Cross-platform mobile repository client with `http` package and `MultipartRequest`.
  - **React Native (TypeScript):** Mobile app client using `fetch` and `FormData`.
  - **Python:** Script and microservice client using `requests` with chunked streaming downloads.
  - **C# (.NET 8 / WPF):** Windows Desktop client using `HttpClient` and `MultipartFormDataContent`.
  - **Electron / Node.js:** Cross-platform desktop client with `axios` and `form-data`.
- **Security & Governance Standards:** Cryptographic checksums, `RenamesUploads` filename sanitation, soft-delete safety, and `AuditLogService` logging.

### 3. Developer Console & API Documentation Portal Integration
Updated `resources/views/pages/admin/api-keys/⚡docs.blade.php`:
- **Interactive Markdown Guide Modal:** Wired `openGuide('documents')` to load and render `docs/integrations/document-management-and-version-control-api.md` directly inside an in-browser modal.
- **Category Filter & Sandbox Buttons:** Added `'documents'` category filter pill ("Documents & Vault") and 11 one-click sandbox presets for rapid API testing.
- **Endpoints Catalog:** Documented all 17 routes in the searchable and filterable API endpoints table under category `'documents'`.
- **Interactive Guide Card:** Added dedicated guide card in the developer portal grid with live link to `openGuide('documents')`.

### 4. Localization Updates
Added key localized strings across English, Bengali, and Hindi:
- `Documents & Vault` -> `নথিপত্র ও ভল্ট` / `दस्तावेज़ एवं वॉल्ट`
- `Document Vault` -> `নথি ভল্ট` / `दस्तावेज़ वॉल्ट`
- `Document Vault • Version Control • Revisions` -> `নথি ভল্ট • সংস্করণ নিয়ন্ত্রণ • সংশোধন` / `दस्तावेज़ वॉल्ट • संस्करण नियंत्रण • संशोधन`
- `Soft Copies Document Files Management & Version Control Guide` -> `সফট কপি নথি ফাইল ব্যবস্থাপনা ও সংস্করণ নিয়ন্ত্রণ নির্দেশিকা` / `सॉफ्ट कॉपी दस्तावेज़ फ़ाइल प्रबंधन एवं संस्करण नियंत्रण गाइड`

### 5. Automated Verification & Test Results
- **Full Test Suite:** `tests/Feature/DocumentManagementTest.php` expanded to 17 tests:
  - Added test for direct latest download, category creation, CSV template download, spreadsheet import, and multi-format export.
  - Added test for Developer Console Livewire component verifying endpoints listing, preset selection, and guide modal rendering.
- **Pest Results:** 17 passed, 130 assertions, 0 failures.
- **Code Style:** Formatted cleanly with `vendor/bin/pint --dirty --format agent`.
- **Frontend Build:** Recompiled production bundle with Vite (`npm run build`). Exit code 0.



