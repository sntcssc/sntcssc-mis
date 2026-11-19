<?php

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\Document\DocumentService;
use App\Services\GlobalSearchService;
use App\Services\RbacService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('public');
    RbacService::seedDefaults();

    $this->admin = User::factory()->create([
        'name' => 'Archive Administrator',
        'email' => 'archive.admin@sntcssc.gov.in',
    ]);
    $this->admin->assignRole('Super Administrator');
    $this->admin->ensureCurrentTeam();

    $this->faculty = User::factory()->create([
        'name' => 'Prof. Ananya Sen',
        'email' => 'ananya.faculty@sntcssc.gov.in',
    ]);
    $this->faculty->assignRole('Faculty');
    $this->faculty->ensureCurrentTeam();

    $this->category = DocumentCategory::firstOrCreate(
        ['slug' => 'test-circulars'],
        ['name' => 'Test Circulars', 'icon' => 'folder', 'color' => 'blue', 'is_active' => true]
    );
});

test('admin can access soft copies document management portal', function () {
    $this->actingAs($this->admin)
        ->get(route('admin.documents.index', ['current_team' => $this->admin->currentTeam->slug]))
        ->assertOk()
        ->assertSee('Soft Copies Documents & Version Archive')
        ->assertSee('Total Documents')
        ->assertSee('Revisions Preserved');
});

test('service creates document with initial file and version 1.0', function () {
    $file = UploadedFile::fake()->create('Institutional_Regulations_2026.pdf', 800, 'application/pdf');

    $doc = DocumentService::createDocument([
        'title' => 'Annual Civil Services Institutional Regulations',
        'purpose' => 'Statutory Administrative Guidance',
        'document_category_id' => $this->category->id,
        'department' => 'Administration',
        'tags' => ['regulations', 'statutory', 'admin'],
        'access_level' => 'internal',
        'status' => 'published',
        'description' => 'Official statutory regulations applicable for all enrolled students.',
        'version_number' => '1.0',
        'version_title' => 'Initial Approved Edition',
        'change_summary' => 'Initial upload and publication.',
    ], $file, $this->admin);

    expect($doc)->toBeInstanceOf(Document::class);
    expect($doc->title)->toBe('Annual Civil Services Institutional Regulations');
    expect($doc->total_versions_count)->toBe(1);
    expect($doc->current_version_id)->not->toBeNull();

    $version = $doc->currentVersion;
    expect($version)->not->toBeNull();
    expect($version->version_number)->toBe('1.0');
    expect($version->is_current)->toBeTrue();
    expect($version->file_type)->toBe('pdf');

    $this->assertDatabaseHas('documents', [
        'id' => $doc->id,
        'title' => 'Annual Civil Services Institutional Regulations',
    ]);

    $this->assertDatabaseHas('document_versions', [
        'document_id' => $doc->id,
        'version_number' => '1.0',
        'is_current' => 1,
    ]);

    Storage::disk('public')->assertExists($version->file_path);
});

test('service allows uploading revised version preserving past version history', function () {
    $fileV1 = UploadedFile::fake()->create('Course_Brochure_v1.docx', 500, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $doc = DocumentService::createDocument([
        'title' => 'Optional Subject Curriculum Guide',
        'purpose' => 'Subject Selection',
        'document_category_id' => $this->category->id,
        'department' => 'Academics',
        'version_number' => '1.0',
        'change_summary' => 'First release',
    ], $fileV1, $this->admin);

    $v1 = $doc->currentVersion;
    expect($v1->is_current)->toBeTrue();

    // Upload Revision (e.g. Word file updated with History optional syllabus)
    $fileV2 = UploadedFile::fake()->create('Course_Brochure_v1.1_Revised.docx', 650, 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');

    $v2 = DocumentService::uploadRevision($doc, [
        'version_number' => '1.1',
        'version_title' => 'Revised with History & Geography Syllabus',
        'change_summary' => 'Added detailed syllabus modules for History Paper I and II.',
    ], $fileV2, $this->admin);

    expect($v2->is_current)->toBeTrue();
    expect($v2->revision_sequence)->toBe(2);

    // Refresh v1 to confirm it is preserved as historical (is_current = false)
    $v1->refresh();
    expect($v1->is_current)->toBeFalse();
    expect($v1->version_number)->toBe('1.0');

    // Both physical files exist in disk storage
    Storage::disk('public')->assertExists($v1->file_path);
    Storage::disk('public')->assertExists($v2->file_path);

    // Verify parent document pointer updated
    $doc->refresh();
    expect($doc->current_version_id)->toBe($v2->id);
    expect($doc->total_versions_count)->toBe(2);
});

test('admin can rollback active version to an earlier historical revision', function () {
    $fileV1 = UploadedFile::fake()->create('Test_Doc_v1.pdf', 300, 'application/pdf');
    $doc = DocumentService::createDocument([
        'title' => 'Version Rollback Test',
        'version_number' => '1.0',
        'change_summary' => 'Initial version',
    ], $fileV1, $this->admin);

    $v1 = $doc->currentVersion;

    $fileV2 = UploadedFile::fake()->create('Test_Doc_v2.pdf', 400, 'application/pdf');
    $v2 = DocumentService::uploadRevision($doc, [
        'version_number' => '2.0',
        'change_summary' => 'Second version',
    ], $fileV2, $this->admin);

    expect($doc->fresh()->current_version_id)->toBe($v2->id);

    // Rollback to v1
    DocumentService::setCurrentVersion($doc, $v1, $this->admin);

    $doc->refresh();
    $v1->refresh();
    $v2->refresh();

    expect($doc->current_version_id)->toBe($v1->id);
    expect($v1->is_current)->toBeTrue();
    expect($v2->is_current)->toBeFalse();
});

test('admin can soft delete document and its versions', function () {
    $file = UploadedFile::fake()->create('Audit_Report.xlsx', 400, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $doc = DocumentService::createDocument([
        'title' => 'Audit Report to be Deleted',
        'version_number' => '1.0',
    ], $file, $this->admin);

    $docId = $doc->id;
    $versionId = $doc->current_version_id;

    DocumentService::deleteDocument($doc, $this->admin);

    $this->assertSoftDeleted('documents', ['id' => $docId]);
    $this->assertSoftDeleted('document_versions', ['id' => $versionId]);

    // Restore
    $restored = DocumentService::restoreDocument($docId, $this->admin);
    expect($restored)->not->toBeNull();
    $this->assertDatabaseHas('documents', ['id' => $docId, 'deleted_at' => null]);
    $this->assertDatabaseHas('document_versions', ['id' => $versionId, 'deleted_at' => null]);
});

test('livewire component can filter and search documents', function () {
    $file1 = UploadedFile::fake()->create('UPSC_Ethics_Guide.pdf', 500, 'application/pdf');
    $doc1 = DocumentService::createDocument([
        'title' => 'UPSC Ethics Paper IV Guide',
        'purpose' => 'Mains Examination Preparation',
        'department' => 'Academics',
        'tags' => ['ethics', 'upsc', 'mains'],
        'access_level' => 'public',
        'status' => 'published',
    ], $file1, $this->admin);

    $file2 = UploadedFile::fake()->create('Hostel_Mess_Roster.xlsx', 300, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    $doc2 = DocumentService::createDocument([
        'title' => 'Hostel Mess Duty Roster',
        'purpose' => 'Dining Operations',
        'department' => 'Administration',
        'tags' => ['hostel', 'mess', 'roster'],
        'access_level' => 'internal',
        'status' => 'published',
    ], $file2, $this->admin);

    Livewire::test('pages::admin.documents')
        ->set('search', 'Ethics')
        ->assertSee('UPSC Ethics Paper IV Guide')
        ->assertDontSee('Hostel Mess Duty Roster')
        ->set('search', '')
        ->set('departmentFilter', 'Administration')
        ->assertSee('Hostel Mess Duty Roster')
        ->assertDontSee('UPSC Ethics Paper IV Guide');
});

test('downloading document version streams file and increments counters', function () {
    $file = UploadedFile::fake()->create('General_Studies_Reading_List.pdf', 600, 'application/pdf');
    $doc = DocumentService::createDocument([
        'title' => 'General Studies Reading List 2026',
        'version_number' => '1.0',
    ], $file, $this->admin);

    $version = $doc->currentVersion;
    expect($version->download_count)->toBe(0);
    expect($doc->total_downloads_count)->toBe(0);

    $this->actingAs($this->admin)
        ->get(route('documents.download.version', $version))
        ->assertOk();

    $version->refresh();
    $doc->refresh();

    expect($version->download_count)->toBe(1);
    expect($doc->total_downloads_count)->toBe(1);
});

test('documents rest api returns paginated list and telemetry statistics', function () {
    $file = UploadedFile::fake()->create('Annual_Report_2026.pdf', 700, 'application/pdf');
    DocumentService::createDocument([
        'title' => 'Annual Report 2026 API Test',
        'version_number' => '1.0',
    ], $file, $this->admin);

    // Create an API Key via ApiKeyService
    $keyData = app(ApiKeyService::class)->generateKey([
        'name' => 'Test Integration Client',
        'scopes' => ['read:documents', 'write:documents', 'read:academics', 'write:academics'],
    ], $this->admin);

    $response = $this->withHeaders([
        'X-API-KEY' => $keyData['plainTextKey'],
    ])->getJson(route('api.v1.documents.index'));

    $response->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data',
            'meta' => ['current_page', 'last_page', 'per_page', 'total'],
        ]);

    $statsResponse = $this->withHeaders([
        'X-API-KEY' => $keyData['plainTextKey'],
    ])->getJson(route('api.v1.documents.statistics'));

    $statsResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => ['total_documents', 'total_versions', 'total_downloads', 'storage_formatted', 'file_types'],
        ]);
});

test('documents rest api supports revision upload and version switching', function () {
    $file = UploadedFile::fake()->create('Syllabus_Api_Doc.pdf', 400, 'application/pdf');
    $doc = DocumentService::createDocument([
        'title' => 'API Document for Revision',
        'version_number' => '1.0',
    ], $file, $this->admin);

    $keyData = app(ApiKeyService::class)->generateKey([
        'name' => 'Test Admin API',
        'scopes' => ['read:documents', 'write:documents', 'read:academics', 'write:academics'],
    ], $this->admin);

    $revFile = UploadedFile::fake()->create('Syllabus_Api_Doc_v2.pdf', 550, 'application/pdf');

    $revisionResponse = $this->withHeaders([
        'X-API-KEY' => $keyData['plainTextKey'],
    ])->post(route('api.v1.documents.versions.store', ['id' => $doc->id]), [
        'file' => $revFile,
        'version_number' => '2.0',
        'change_summary' => 'Added new civil services optional syllabus modules via API.',
    ]);

    $revisionResponse->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.version_number', '2.0');

    $doc->refresh();
    expect($doc->total_versions_count)->toBe(2);
});

test('livewire component can export documents to excel and csv', function () {
    $file = UploadedFile::fake()->create('Export_Sample.pdf', 300, 'application/pdf');
    DocumentService::createDocument([
        'title' => 'Export Sample Document',
        'version_number' => '1.0',
    ], $file, $this->admin);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.documents')
        ->call('exportExcel')
        ->assertFileDownloaded();

    Livewire::actingAs($this->admin)
        ->test('pages::admin.documents')
        ->call('exportCsv')
        ->assertFileDownloaded();
});

test('pdf register export route generates landscape pdf', function () {
    $file = UploadedFile::fake()->create('PDF_Export_Doc.pdf', 300, 'application/pdf');
    DocumentService::createDocument([
        'title' => 'PDF Export Document Test',
        'version_number' => '1.0',
    ], $file, $this->admin);

    $this->actingAs($this->admin)
        ->get(route('documents.export.pdf'))
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf');
});

test('bulk import service can import documents from csv spreadsheet', function () {
    $csvContent = "Title,Purpose,Category,Department,Access Level,Tags,Description,Notes,Remarks\n";
    $csvContent .= "Test CSV Document 1,Syllabus,Test Circulars,Academics,public,\"upsc, syllabus\",Description 1,Notes 1,Remarks 1\n";
    $csvContent .= "Test CSV Document 2,Notice,Test Circulars,Administration,internal,\"notice, admin\",Description 2,Notes 2,Remarks 2\n";

    $csvFile = UploadedFile::fake()->createWithContent('bulk_docs.csv', $csvContent);

    $result = DocumentService::importFromSpreadsheet($csvFile, $this->admin);

    expect($result['imported'])->toBe(2);
    expect($result['errors'])->toBeEmpty();

    $this->assertDatabaseHas('documents', ['title' => 'Test CSV Document 1']);
    $this->assertDatabaseHas('documents', ['title' => 'Test CSV Document 2']);
});

test('renames uploads trait renames uploaded documents with user and timestamp pattern', function () {
    $file = UploadedFile::fake()->create('Test_Curriculum_Guidelines.pdf', 400, 'application/pdf');

    $doc = DocumentService::createDocument([
        'title' => 'Renamed File Document Verification',
        'version_number' => '1.0',
    ], $file, $this->admin);

    $v1 = $doc->currentVersion;
    // Format from RenamesUploads: {type}_user_{userId}_{timestamp}.{extension}
    expect(basename($v1->file_path))->toMatch('/^doc_.*_user_.*_\d{8}_\d{6}\.pdf$/');

    $revFile = UploadedFile::fake()->create('Test_Curriculum_Revised.pdf', 450, 'application/pdf');
    $v2 = DocumentService::uploadRevision($doc, [
        'version_number' => '1.1',
        'change_summary' => 'Revised guidelines',
    ], $revFile, $this->admin);

    expect(basename($v2->file_path))->toMatch('/^doc_.*_user_.*_\d{8}_\d{6}\.pdf$/');
    Storage::disk('public')->assertExists($v1->file_path);
    Storage::disk('public')->assertExists($v2->file_path);
});

test('livewire component dispatches modal-open events correctly for all modals', function () {
    $file = UploadedFile::fake()->create('Modal_Test.pdf', 200, 'application/pdf');
    $doc = DocumentService::createDocument([
        'title' => 'Modal Dispatch Test Document',
        'version_number' => '1.0',
    ], $file, $this->admin);

    Livewire::actingAs($this->admin)
        ->test('pages::admin.documents')
        ->call('openCreateModal')
        ->assertDispatched('modal-open', name: 'document-create-modal')
        ->call('editDocument', $doc->id)
        ->assertDispatched('modal-open', name: 'document-edit-modal')
        ->call('openRevisionModal', $doc->id)
        ->assertDispatched('modal-open', name: 'document-revision-modal')
        ->call('openHistoryModal', $doc->id)
        ->assertDispatched('modal-open', name: 'document-history-modal')
        ->call('viewDetails', $doc->id)
        ->assertDispatched('modal-open', name: 'document-details-modal')
        ->call('confirmDelete', $doc->id)
        ->assertDispatched('modal-open', name: 'document-delete-modal')
        ->call('openImportModal')
        ->assertDispatched('modal-open', name: 'document-import-modal')
        ->call('openCategoryModal')
        ->assertDispatched('modal-open', name: 'document-category-modal');
});

test('global search includes document navigation, quick action, and database records', function () {
    $file = UploadedFile::fake()->create('Civil_Services_Rules.pdf', 300, 'application/pdf');
    $doc = DocumentService::createDocument([
        'document_number' => 'DOC-2026-TESTSEARCH',
        'title' => 'Unique Civil Services Statutory Handbook',
        'purpose' => 'Institutional Governance Rulebook',
        'version_number' => '1.0',
    ], $file, $this->admin);

    // 1. Search for generic keyword 'documents'
    $resultsGeneric = GlobalSearchService::search('documents', $this->admin);
    $itemIds = collect($resultsGeneric['items'])->pluck('id')->all();
    expect($itemIds)->toContain('nav_documents');

    // 2. Search for unique document number
    $resultsDoc = GlobalSearchService::search('TESTSEARCH', $this->admin);
    $docMatches = collect($resultsDoc['items'])->where('type', 'document');
    expect($docMatches)->not->toBeEmpty();
    expect($docMatches->first()['title'])->toBe('Unique Civil Services Statutory Handbook');
    expect($docMatches->first()['url'])->toContain('DOC-2026-TESTSEARCH');

    // 3. Search for quick action 'upload'
    $resultsUpload = GlobalSearchService::search('upload document', $this->admin);
    $uploadIds = collect($resultsUpload['items'])->pluck('id')->all();
    expect($uploadIds)->toContain('action_upload_document');

    // 4. Verify in global-search-modal livewire component
    Livewire::actingAs($this->admin)
        ->test('global-search-modal')
        ->set('search', 'document')
        ->assertSee('Soft Copies Documents & Repository');
});

test('documents api provides complete endpoints for latest download, categories, template, import, and export', function () {
    $file = UploadedFile::fake()->create('Institutional_Policy.pdf', 350, 'application/pdf');
    $doc = DocumentService::createDocument([
        'title' => 'Institutional Policy Guidelines 2026',
        'document_category_id' => $this->category->id,
        'version_number' => '1.0',
    ], $file, $this->admin);

    $keyData = app(ApiKeyService::class)->generateKey([
        'name' => 'Extended Doc Client',
        'scopes' => ['read:documents', 'write:documents', 'download:documents', 'admin:documents', 'read:academics', 'write:academics'],
    ], $this->admin);

    $headers = ['X-API-KEY' => $keyData['plainTextKey']];

    // 1. Download Latest Active Version
    $downloadResponse = $this->withHeaders($headers)->get(route('api.v1.documents.download.latest', ['id' => $doc->id]));
    $downloadResponse->assertOk()
        ->assertHeader('content-disposition');

    // 2. Create Category via API
    $catResponse = $this->withHeaders($headers)->postJson(route('api.v1.documents.categories.store'), [
        'name' => 'Faculty Handbooks',
        'description' => 'Official operational guides for faculties.',
        'color' => 'indigo',
        'icon' => 'book-open',
        'is_active' => true,
    ]);
    $catResponse->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Faculty Handbooks');

    $this->assertDatabaseHas('document_categories', ['name' => 'Faculty Handbooks']);

    // 3. Download CSV Import Template
    $templateResponse = $this->withHeaders($headers)->get(route('api.v1.documents.template'));
    $templateResponse->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    // 4. Bulk Import Spreadsheet via API
    $csvContent = "Title,Purpose,Category,Department,Access Level,Tags,Description,Notes,Remarks\n";
    $csvContent .= "API Ingested Handbook,Staff Guidance,Faculty Handbooks,Academics,internal,\"handbook, faculty\",Comprehensive handbook,Note A,Remark A\n";
    $csvFile = UploadedFile::fake()->createWithContent('api_docs.csv', $csvContent);

    $importResponse = $this->withHeaders($headers)->post(route('api.v1.documents.import'), [
        'file' => $csvFile,
    ]);
    $importResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.imported', 1);

    $this->assertDatabaseHas('documents', ['title' => 'API Ingested Handbook']);

    // 5. Export Documents Register (JSON)
    $exportJsonResponse = $this->withHeaders($headers)->getJson(route('api.v1.documents.export', ['format' => 'json']));
    $exportJsonResponse->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'success',
            'data' => [
                '*' => ['id', 'document_number', 'title', 'category'],
            ],
        ]);

    // 6. Export Documents Register (CSV)
    $exportCsvResponse = $this->withHeaders($headers)->get(route('api.v1.documents.export', ['format' => 'csv']));
    $exportCsvResponse->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');
});

test('developer console interactive docs renders document integration guide and presets', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.api-keys.docs')
        // Verify endpoints list includes documents category
        ->assertSee('Documents & Vault')
        ->assertSee('/api/v1/documents')
        ->assertSee('Document Vault')
        // Filter by documents category
        ->set('sandboxCategoryFilter', 'documents')
        ->assertSet('sandboxCategoryFilter', 'documents')
        ->call('selectPreset', 'GET', '/api/v1/documents')
        ->assertSet('sandboxEndpoint', '/api/v1/documents')
        ->assertSet('sandboxMethod', 'GET')
        // Open the Markdown Integration Guide
        ->call('openGuide', 'documents')
        ->assertSet('showGuideModal', true)
        ->assertSet('guideTitle', 'Soft Copies Document Management & Version Control System REST API — Enterprise Multi-Platform Integration Guide')
        ->assertSee('Soft Copies Document Management')
        ->call('closeGuideModal')
        ->assertSet('showGuideModal', false);
});
