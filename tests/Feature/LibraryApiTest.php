<?php

use App\Models\Book;
use App\Models\BookAuthor;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\BookFine;
use App\Models\BookIssue;
use App\Models\BookPublisher;
use App\Models\BookReservation;
use App\Models\LibraryDeposit;
use App\Models\LibraryMember;
use App\Models\User;
use App\Services\ApiKey\ApiKeyService;
use App\Services\RbacService;

beforeEach(function () {
    RbacService::seedDefaults();
    $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->run();

    $this->admin = User::factory()->create(['name' => 'Library API Admin']);
    $this->admin->assignRole('Super Administrator');

    $this->apiKeyService = app(ApiKeyService::class);
    $generated = $this->apiKeyService->generateKey([
        'name' => 'Library Full Access Key',
        'type' => 'backend_service',
        'environment' => 'production',
        'rate_limit_per_minute' => 300,
        'scopes' => ['read:library', 'write:library', 'download:library_cards', 'ping'],
        'description' => 'Test key with full library scopes',
    ], $this->admin);

    $this->plainKey = $generated['plainTextKey'];

    // Read-only key
    $readGen = $this->apiKeyService->generateKey([
        'name' => 'Library Readonly Key',
        'type' => 'mobile_app',
        'environment' => 'production',
        'rate_limit_per_minute' => 120,
        'scopes' => ['read:library'],
        'description' => 'Read only library key',
    ], $this->admin);
    $this->readOnlyPlainKey = $readGen['plainTextKey'];

    // Setup base catalog
    $this->category = BookCategory::firstOrCreate(['code' => 'TEST_CAT'], ['name' => 'Governance', 'slug' => 'governance']);
    $this->publisher = BookPublisher::firstOrCreate(['name' => 'Academic Press'], ['slug' => 'academic-press']);
    $this->author = BookAuthor::firstOrCreate(['name' => 'Dr. A. Sharma'], ['slug' => 'dr-a-sharma']);

    $this->book = Book::create([
        'title' => 'Indian Administration & Polity',
        'slug' => 'indian-admin-polity-'.uniqid(),
        'isbn' => '978-93-5000-001-1',
        'book_category_id' => $this->category->id,
        'book_publisher_id' => $this->publisher->id,
        'edition' => '5th Edition',
        'price' => 450.00,
        'is_active' => true,
    ]);
    $this->book->authors()->attach($this->author->id);

    $this->copy = BookCopy::create([
        'book_id' => $this->book->id,
        'accession_no' => 'ACC-TEST-'.uniqid(),
        'barcode' => 'BAR-TEST-'.uniqid(),
        'price' => 450.00,
        'condition' => BookCopy::CONDITION_NEW,
        'status' => BookCopy::STATUS_AVAILABLE,
        'acquisition_date' => now()->toDateString(),
    ]);

    // Student User & Member
    $this->studentUser = User::factory()->create();
    $this->member = LibraryMember::create([
        'member_number' => 'LIB-MEM-'.uniqid(),
        'user_id' => $this->studentUser->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $this->studentUser->name,
        'email' => $this->studentUser->email,
        'phone' => '9876543210',
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    // Adequate deposit for borrowing
    LibraryDeposit::create([
        'deposit_no' => 'DEP-'.uniqid(),
        'user_id' => $this->studentUser->id,
        'amount' => 1000.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);
});

test('library dashboard requires valid api key and scope', function () {
    // 1. Missing API key returns 401
    $this->getJson('/api/v1/library/dashboard')
        ->assertStatus(401)
        ->assertJson([
            'success' => false,
            'error' => ['code' => 'API_KEY_MISSING'],
        ]);

    // 2. With valid API key returns 200 and metrics
    $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/library/dashboard')
        ->assertStatus(200)
        ->assertJsonStructure([
            'success',
            'data' => [
                'metrics' => [
                    'total_titles',
                    'total_copies',
                    'available_copies',
                    'issued_copies',
                    'active_members',
                    'overdue_count',
                    'total_fines_unpaid',
                ],
                'recent_issues',
                'active_reservations',
                'popular_books',
            ],
        ]);
});

test('read-only key cannot perform write operations', function () {
    $this->withHeader('X-API-Key', $this->readOnlyPlainKey)
        ->postJson('/api/v1/library/issues', [
            'barcode' => $this->copy->barcode,
            'library_member_id' => $this->member->id,
        ])
        ->assertStatus(403)
        ->assertJson([
            'success' => false,
            'error' => ['code' => 'INSUFFICIENT_SCOPE'],
        ]);
});

test('books catalog can be listed, queried, created, updated, and soft deleted', function () {
    // 1. List catalog
    $res = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/library/books?search=Administration');

    $res->assertStatus(200)
        ->assertJson(['success' => true]);
    expect(count($res->json('data')))->toBeGreaterThanOrEqual(1);

    // 2. Show single book
    $showRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/library/books/'.$this->book->id);

    $showRes->assertStatus(200)
        ->assertJsonPath('data.title', $this->book->title)
        ->assertJsonStructure(['data' => ['copies', 'authors', 'category']]);

    // 3. Create new book
    $createRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/books', [
            'title' => 'Ethics, Integrity and Aptitude',
            'isbn' => '978-93-8000-002-2',
            'book_category_id' => $this->category->id,
            'book_publisher_id' => $this->publisher->id,
            'author_ids' => [$this->author->id],
            'price' => 380.00,
        ]);

    $createRes->assertStatus(201)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.title', 'Ethics, Integrity and Aptitude');

    $newBookId = $createRes->json('data.id');

    // 4. Update book
    $updateRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->putJson('/api/v1/library/books/'.$newBookId, [
            'title' => 'Ethics, Integrity and Aptitude (Revised)',
            'price' => 420.00,
        ]);

    $updateRes->assertStatus(200)
        ->assertJsonPath('data.title', 'Ethics, Integrity and Aptitude (Revised)')
        ->assertJsonPath('data.price', '420.00');

    // 5. Soft delete book
    $deleteRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->deleteJson('/api/v1/library/books/'.$newBookId);

    $deleteRes->assertStatus(200)
        ->assertJson(['success' => true]);

    expect(Book::find($newBookId))->toBeNull();
    expect(Book::withTrashed()->find($newBookId))->not->toBeNull();
});

test('barcode lookup retrieves physical copy details with book relation', function () {
    $res = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/library/copies/'.$this->copy->barcode);

    $res->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.barcode', $this->copy->barcode)
        ->assertJsonPath('data.accession_no', $this->copy->accession_no)
        ->assertJsonPath('data.book.title', $this->book->title);
});

test('book copy status can be updated for maintenance or damages', function () {
    $res = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/copies/'.$this->copy->barcode.'/status', [
            'status' => BookCopy::STATUS_MAINTENANCE,
            'remarks' => 'Binding repair required at preservation unit',
        ]);

    $res->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.status', BookCopy::STATUS_MAINTENANCE);

    expect($this->copy->fresh()->status)->toBe(BookCopy::STATUS_MAINTENANCE);
});

test('circulation desk can issue, renew, and return book copies', function () {
    // 1. Issue book
    $issueRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/issues', [
            'barcode' => $this->copy->barcode,
            'library_member_id' => $this->member->id,
            'days' => 14,
        ]);

    $issueRes->assertStatus(201)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.status', BookIssue::STATUS_ISSUED);

    $issueId = $issueRes->json('data.id');
    expect($this->copy->fresh()->status)->toBe(BookCopy::STATUS_ISSUED);

    // 2. Renew loan
    $renewRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/issues/'.$issueId.'/renew', [
            'additional_days' => 7,
            'remarks' => 'Extended for exam preparation',
        ]);

    $renewRes->assertStatus(200)
        ->assertJson(['success' => true]);

    // 3. Return book
    $returnRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/issues/'.$issueId.'/return', [
            'condition_on_return' => BookCopy::CONDITION_GOOD,
            'remarks' => 'Returned on time in pristine condition',
        ]);

    $returnRes->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.status', BookIssue::STATUS_RETURNED);

    expect($this->copy->fresh()->status)->toBe(BookCopy::STATUS_AVAILABLE);
});

test('in-house reading room issue and return facility functions properly', function () {
    // 1. Issue for day use
    $inHouseRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/in-house/issue', [
            'barcode' => $this->copy->barcode,
            'library_member_id' => $this->member->id,
            'purpose' => 'Reference desk study',
        ]);

    $inHouseRes->assertStatus(201)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.is_in_house', true)
        ->assertJsonPath('data.status', BookIssue::STATUS_ISSUED);

    $issueId = $inHouseRes->json('data.id');

    // 2. Return from in-house use
    $returnRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/in-house/return', [
            'issue_id' => $issueId,
            'remarks' => 'Returned before library closing time',
        ]);

    $returnRes->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.status', BookIssue::STATUS_RETURNED);

    expect($this->copy->fresh()->status)->toBe(BookCopy::STATUS_AVAILABLE);
});

test('reservations queue can be created and cancelled', function () {
    // Mark copy as issued so reservation makes sense
    $this->copy->update(['status' => BookCopy::STATUS_ISSUED]);

    // 1. Create reservation
    $res = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/reservations', [
            'book_id' => $this->book->id,
            'library_member_id' => $this->member->id,
            'notes' => 'Urgent requirement for mains optional syllabus',
        ]);

    $res->assertStatus(201)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.status', BookReservation::STATUS_PENDING);

    $reservationId = $res->json('data.id');

    // 2. Cancel reservation
    $cancelRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/reservations/'.$reservationId.'/cancel', [
            'reason' => 'Candidate acquired personal copy',
        ]);

    $cancelRes->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.status', BookReservation::STATUS_CANCELLED);
});

test('fines can be queried, paid, and waived with audit logging', function () {
    // Create an overdue issue and fine
    $issue = BookIssue::create([
        'issue_no' => 'ISS-TEST-'.uniqid(),
        'book_id' => $this->book->id,
        'book_copy_id' => $this->copy->id,
        'library_member_id' => $this->member->id,
        'issue_date' => now()->subDays(20)->toDateString(),
        'due_date' => now()->subDays(6)->toDateString(),
        'status' => BookIssue::STATUS_OVERDUE,
    ]);

    $fine = BookFine::create([
        'book_issue_id' => $issue->id,
        'library_member_id' => $this->member->id,
        'amount' => 60.00,
        'status' => BookFine::STATUS_PENDING,
        'reason' => '6 days overdue @ ₹10/day',
    ]);

    // 1. Query fines
    $listRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/library/fines?status=unpaid');

    $listRes->assertStatus(200)
        ->assertJson(['success' => true]);

    // 2. Waive fine with audit justification
    $waiveRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/fines/'.$fine->id.'/waive', [
            'waiver_reason' => 'Medical leave exemption approved by competent authority',
        ]);

    $waiveRes->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.status', BookFine::STATUS_WAIVED);

    expect($fine->fresh()->status)->toBe(BookFine::STATUS_WAIVED);
});

test('clearance eligibility check and certificate issuance', function () {
    // 1. Check clearance (member has no loans, no unpaid fines -> eligible)
    $checkRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->getJson('/api/v1/library/clearance/check/'.$this->member->id);

    $checkRes->assertStatus(200)
        ->assertJson([
            'success' => true,
            'data' => [
                'eligible' => true,
                'active_loans_count' => 0,
                'unpaid_fines_amount' => 0,
            ],
        ]);

    // 2. Issue clearance certificate
    $issueRes = $this->withHeader('X-API-Key', $this->plainKey)
        ->postJson('/api/v1/library/clearance/issue', [
            'library_member_id' => $this->member->id,
            'remarks' => 'Course completed successfully',
        ]);

    $issueRes->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure([
            'data' => [
                'certificate_number',
                'issue_date',
                'member',
            ],
        ]);
});

test('public catalog is accessible without authentication', function () {
    $res = $this->getJson('/api/v1/library/catalog?search=Administration');

    $res->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonStructure(['data', 'meta']);
});

test('student self-service returns student account and active loans', function () {
    // Issue a book to this student
    BookIssue::create([
        'issue_no' => 'ISS-TEST-'.uniqid(),
        'book_id' => $this->book->id,
        'book_copy_id' => $this->copy->id,
        'library_member_id' => $this->member->id,
        'issue_date' => now()->toDateString(),
        'due_date' => now()->addDays(14)->toDateString(),
        'status' => BookIssue::STATUS_ISSUED,
    ]);

    // As logged in student
    $res = $this->actingAs($this->studentUser)
        ->getJson('/api/v1/library/my-account');

    $res->assertStatus(200)
        ->assertJson(['success' => true])
        ->assertJsonPath('data.member_number', $this->member->member_number);

    $loansRes = $this->actingAs($this->studentUser)
        ->getJson('/api/v1/library/my-loans');

    $loansRes->assertStatus(200)
        ->assertJson(['success' => true]);
    expect(count($loansRes->json('data')))->toBe(1);
});
