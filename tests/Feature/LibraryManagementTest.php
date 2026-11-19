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
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\Library\BarcodeService;
use App\Services\Library\BookCirculationService;
use App\Services\Library\LibraryCardService;
use App\Services\Library\LibraryClearanceService;
use App\Services\Library\LibraryImportExportService;
use App\Services\Library\LibraryPolicyService;
use App\Services\Library\LibrarySecurityDepositValidator;
use App\Services\Payment\LibraryDepositService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpFoundation\StreamedResponse;

beforeEach(function () {
    $this->artisan('db:seed', ['--class' => 'RbacSeeder'])->run();
    $this->artisan('db:seed', ['--class' => 'SettingsSeeder'])->run();
});

test('librarian role exists and has library permissions', function () {
    $librarianRole = Role::where('name', 'Librarian')->first();

    expect($librarianRole)->not->toBeNull();
    expect($librarianRole->hasPermissionTo('library.books.manage'))->toBeTrue();
    expect($librarianRole->hasPermissionTo('library.issue.manage'))->toBeTrue();
    expect($librarianRole->hasPermissionTo('library.accession.manage'))->toBeTrue();
    expect($librarianRole->hasPermissionTo('library.fines.manage'))->toBeTrue();
});

test('book accessioning creates physical copy with unique accession number and barcode', function () {
    $category = BookCategory::firstOrCreate(['code' => 'TEST'], ['name' => 'Testing', 'slug' => 'testing']);
    $publisher = BookPublisher::firstOrCreate(['name' => 'Test Press'], ['slug' => 'test-press']);
    $author = BookAuthor::firstOrCreate(['name' => 'John Scholar'], ['slug' => 'john-scholar']);

    $book = Book::create([
        'title' => 'Civil Services Governance',
        'slug' => 'civil-services-governance',
        'isbn' => '978-0-123456-78-9',
        'book_category_id' => $category->id,
        'book_publisher_id' => $publisher->id,
        'price' => 500.00,
        'is_active' => true,
    ]);
    $book->authors()->attach($author->id);

    $accessionNo = 'ACC-TEST-0001';
    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => $accessionNo,
        'barcode' => $accessionNo,
        'price' => 500.00,
        'condition' => BookCopy::CONDITION_NEW,
        'status' => BookCopy::STATUS_AVAILABLE,
        'acquisition_date' => now()->toDateString(),
    ]);

    expect($copy->id)->toBeGreaterThan(0);
    expect($copy->accession_no)->toBe($accessionNo);

    // Verify barcode generation returns valid data URI
    $barcodeUri = BarcodeService::generateBarcodeDataUri($copy->barcode);
    expect($barcodeUri)->toStartWith('data:image/svg+xml;base64,');

    $qrUri = BarcodeService::generateQrCodeDataUri($copy->accession_no);
    expect($qrUri)->toStartWith('data:image/png;base64,');
});

test('security deposit limit prevents borrowing when book price exceeds active deposit', function () {
    $user = User::factory()->create();
    $member = LibraryMember::create([
        'member_number' => 'LIB-TEST-0001',
        'user_id' => $user->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $user->name,
        'email' => $user->email,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    // Active deposit of ₹300
    LibraryDeposit::create([
        'deposit_no' => 'DEP-TEST-'.uniqid(),
        'user_id' => $user->id,
        'amount' => 300.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    // Book cost ₹500
    $category = BookCategory::firstOrCreate(['code' => 'GEN'], ['name' => 'General', 'slug' => 'general']);
    $book = Book::create([
        'title' => 'Advanced Indian Polity',
        'slug' => 'advanced-indian-polity-'.uniqid(),
        'book_category_id' => $category->id,
        'price' => 500.00,
    ]);
    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-POL-'.uniqid(),
        'barcode' => 'BAR-POL-'.uniqid(),
        'price' => 500.00,
        'acquisition_date' => now()->toDateString(),
        'status' => BookCopy::STATUS_AVAILABLE,
    ]);

    // Validation must fail because ₹500 > ₹300
    $validation = LibrarySecurityDepositValidator::validateIssueEligibility($copy, $member);
    expect($validation['eligible'])->toBeFalse();
    expect(str_contains((string) $validation['reason'], 'Security Deposit Limit Exceeded'))->toBeTrue();

    // Attempting issue without bypass must throw Exception
    expect(fn () => BookCirculationService::issueBook($copy, $member, 15, false))
        ->toThrow(Exception::class);
});

test('librarian bypass feature allows issuing without security deposit check when justification provided', function () {
    $user = User::factory()->create();
    $librarian = User::factory()->create();
    $member = LibraryMember::create([
        'member_number' => 'LIB-TEST-0002',
        'user_id' => $user->id,
        'member_type' => LibraryMember::TYPE_STAFF,
        'name' => 'Dr. Staff Member',
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    // Zero deposit
    expect($member->getActiveSecurityDeposit())->toBe(0.00);

    $category = BookCategory::firstOrCreate(['code' => 'HIS'], ['name' => 'History', 'slug' => 'history']);
    $book = Book::create([
        'title' => 'Ancient Indian History',
        'slug' => 'ancient-indian-history-'.uniqid(),
        'book_category_id' => $category->id,
        'price' => 650.00,
    ]);
    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-HIS-'.uniqid(),
        'barcode' => 'BAR-HIS-'.uniqid(),
        'price' => 650.00,
        'acquisition_date' => now()->toDateString(),
        'status' => BookCopy::STATUS_AVAILABLE,
    ]);

    // Issue with bypass and justification
    $issue = BookCirculationService::issueBook(
        $copy,
        $member,
        15,
        true,
        'Authorized by Head of Dept for 1-week curriculum research',
        'Staff loan desk',
        $librarian
    );

    expect($issue->id)->toBeGreaterThan(0);
    expect($issue->is_deposit_bypassed)->toBeTrue();
    expect(str_contains((string) $issue->bypass_justification, 'curriculum research'))->toBeTrue();
    expect($copy->fresh()->status)->toBe(BookCopy::STATUS_ISSUED);
});

test('standard 15-day borrowing timeline and renewal extension work as specified', function () {
    $user = User::factory()->create();
    $member = LibraryMember::create([
        'member_number' => 'LIB-TEST-0003',
        'user_id' => $user->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $user->name,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    // Sufficient deposit of ₹1500
    LibraryDeposit::create([
        'deposit_no' => 'DEP-TEST-'.uniqid(),
        'user_id' => $user->id,
        'amount' => 1500.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    $category = BookCategory::firstOrCreate(['code' => 'ECO'], ['name' => 'Economics', 'slug' => 'economics']);
    $book = Book::create([
        'title' => 'Macroeconomics Fundamentals',
        'slug' => 'macroeconomics-fundamentals-'.uniqid(),
        'book_category_id' => $category->id,
        'price' => 450.00,
    ]);
    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-ECO-'.uniqid(),
        'barcode' => 'BAR-ECO-'.uniqid(),
        'price' => 450.00,
        'acquisition_date' => now()->toDateString(),
        'status' => BookCopy::STATUS_AVAILABLE,
    ]);

    // Issue book for 15 days
    $issue = BookCirculationService::issueBook($copy, $member, 15);

    expect($issue->issue_date->toDateString())->toBe(now()->toDateString());
    expect($issue->due_date->toDateString())->toBe(now()->addDays(15)->toDateString());
    expect($issue->renewal_count)->toBe(0);

    // 1st Renewal (+15 days)
    $renewed = BookCirculationService::renewBook($issue, 15);
    expect($renewed->renewal_count)->toBe(1);
    expect($renewed->status)->toBe(BookIssue::STATUS_RENEWED);

    // 2nd Renewal (+15 days)
    $renewedAgain = BookCirculationService::renewBook($renewed, 15);
    expect($renewedAgain->renewal_count)->toBe(2);

    // 3rd Renewal must fail because max allowed renewals is 2
    expect(fn () => BookCirculationService::renewBook($renewedAgain, 15))
        ->toThrow(Exception::class);
});

test('overdue book return calculates fine and fine waiver/payment workflows function', function () {
    $user = User::factory()->create();
    $member = LibraryMember::create([
        'member_number' => 'LIB-TEST-0004',
        'user_id' => $user->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $user->name,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    LibraryDeposit::create([
        'deposit_no' => 'DEP-TEST-'.uniqid(),
        'user_id' => $user->id,
        'amount' => 1000.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    $category = BookCategory::firstOrCreate(['code' => 'GEO'], ['name' => 'Geography', 'slug' => 'geography']);
    $book = Book::create([
        'title' => 'Physical Geography of India',
        'slug' => 'physical-geography-'.uniqid(),
        'book_category_id' => $category->id,
        'price' => 400.00,
    ]);
    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-GEO-'.uniqid(),
        'barcode' => 'BAR-GEO-'.uniqid(),
        'price' => 400.00,
        'acquisition_date' => now()->toDateString(),
        'status' => BookCopy::STATUS_AVAILABLE,
    ]);

    $issue = BookCirculationService::issueBook($copy, $member, 15);

    // Simulate overdue by 5 days (due date 5 days ago)
    $issue->update(['due_date' => now()->subDays(5)]);

    // Return overdue book
    $result = BookCirculationService::returnBook($issue, BookCopy::CONDITION_GOOD, 'Returned at counter');

    expect($result['issue']->status)->toBe(BookIssue::STATUS_RETURNED);
    expect($result['overdue_days'])->toBe(5);
    expect($result['fine'])->not->toBeNull();

    // 5 days @ ₹5/day = ₹25.00
    expect((float) $result['fine']->amount)->toBe(25.00);
    expect((float) $result['fine']->payable_amount)->toBe(25.00);
    expect($result['fine']->status)->toBe(BookFine::STATUS_PENDING);

    // Settle fine with cash payment
    $fine = $result['fine'];
    $fine->update([
        'paid_amount' => 25.00,
        'payable_amount' => 0.00,
        'status' => BookFine::STATUS_PAID,
        'payment_method' => 'cash',
        'paid_at' => now(),
    ]);

    expect($fine->fresh()->status)->toBe(BookFine::STATUS_PAID);
});

test('book reservation queue respects deposit threshold and converts to issue upon fulfillment', function () {
    $user = User::factory()->create();
    $member = LibraryMember::create([
        'member_number' => 'LIB-TEST-0005',
        'user_id' => $user->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $user->name,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    LibraryDeposit::create([
        'deposit_no' => 'DEP-TEST-'.uniqid(),
        'user_id' => $user->id,
        'amount' => 1000.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    $category = BookCategory::firstOrCreate(['code' => 'PUB'], ['name' => 'Public Administration', 'slug' => 'public-admin']);
    $book = Book::create([
        'title' => 'Administrative Thinkers',
        'slug' => 'administrative-thinkers-'.uniqid(),
        'book_category_id' => $category->id,
        'price' => 380.00,
    ]);
    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-PUB-'.uniqid(),
        'barcode' => 'BAR-PUB-'.uniqid(),
        'price' => 380.00,
        'acquisition_date' => now()->toDateString(),
        'status' => BookCopy::STATUS_AVAILABLE,
    ]);

    // Create reservation
    $reservation = BookCirculationService::reserveBook($book, $member, 'Exam preparation hold');

    expect($reservation->id)->toBeGreaterThan(0);
    expect($reservation->status)->toBe(BookReservation::STATUS_PENDING);
    expect($reservation->reservation_number)->toStartWith('RES-');

    // Fulfill reservation
    $issue = BookCirculationService::fulfillReservation($reservation, $copy, 15);

    expect($reservation->fresh()->status)->toBe(BookReservation::STATUS_FULFILLED);
    expect($copy->fresh()->status)->toBe(BookCopy::STATUS_ISSUED);
    expect($issue->id)->toBeGreaterThan(0);
});

test('library card payload generator respects deposit and issued books toggles', function () {
    $user = User::factory()->create();
    $member = LibraryMember::create([
        'member_number' => 'LIB-CARD-0001',
        'user_id' => $user->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => 'Ayan Mukherjee',
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    LibraryDeposit::create([
        'deposit_no' => 'DEP-TEST-'.uniqid(),
        'user_id' => $user->id,
        'amount' => 1200.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    // Test with both toggles TRUE
    $payloadWithBoth = LibraryCardService::getCardPayload($member, true, true);
    expect($payloadWithBoth['with_deposit_details'])->toBeTrue();
    expect($payloadWithBoth['security_deposit_amount'])->toBe(1200.00);
    expect($payloadWithBoth['with_issued_books'])->toBeTrue();
    expect($payloadWithBoth['barcode_uri'])->not->toBeEmpty();
    expect($payloadWithBoth['qr_uri'])->not->toBeEmpty();

    // Test with both toggles FALSE
    $payloadWithout = LibraryCardService::getCardPayload($member, false, false);
    expect($payloadWithout['with_deposit_details'])->toBeFalse();
    expect($payloadWithout['with_issued_books'])->toBeFalse();
});

test('library card download controller endpoint streams pdf with requested options', function () {
    $user = User::factory()->create();
    $member = LibraryMember::create([
        'member_number' => 'LIB-CARD-0002',
        'user_id' => $user->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => 'Sneha Sharma',
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    $response = $this->actingAs($user)
        ->get(route('library.card.download', [
            'member' => $member->id,
            'include_deposit' => 1,
            'include_books' => 0,
        ]));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('book_categories where is_active = 1 query executes without 1054 column error', function () {
    $category = BookCategory::firstOrCreate(['code' => 'ACTIVE_TEST'], ['name' => 'Active Category Test', 'slug' => 'active-category-test']);
    $author = BookAuthor::firstOrCreate(['name' => 'Active Author Test'], ['slug' => 'active-author-test']);
    $publisher = BookPublisher::firstOrCreate(['name' => 'Active Publisher Test'], ['slug' => 'active-publisher-test']);

    // Direct query that threw the error reported by the user
    $categories = BookCategory::where('is_active', 1)->whereNull('deleted_at')->orderBy('name', 'asc')->get();
    expect($categories)->not->toBeEmpty();
    expect($categories->first()->is_active)->toBeTrue();

    $authors = BookAuthor::where('is_active', 1)->orderBy('name', 'asc')->get();
    expect($authors)->not->toBeEmpty();

    $publishers = BookPublisher::where('is_active', 1)->orderBy('name', 'asc')->get();
    expect($publishers)->not->toBeEmpty();
});

test('student with unreturned books cannot request library security deposit refund and receives informative error', function () {
    $student = User::factory()->create();
    $student->assignRole('Student');

    $member = LibraryMember::create([
        'member_number' => 'LIB-DEP-TEST-01',
        'user_id' => $student->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $student->name,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    $deposit = LibraryDeposit::create([
        'deposit_no' => 'DEP-CLEAR-'.uniqid(),
        'user_id' => $student->id,
        'amount' => 1500.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    $category = BookCategory::firstOrCreate(['code' => 'HIST'], ['name' => 'History', 'slug' => 'history']);
    $book = Book::create([
        'title' => 'Modern India History',
        'slug' => 'modern-india-history-'.uniqid(),
        'book_category_id' => $category->id,
        'price' => 450.00,
    ]);

    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-HIST-'.uniqid(),
        'barcode' => 'BAR-HIST-'.uniqid(),
        'price' => 450.00,
        'status' => BookCopy::STATUS_ISSUED,
        'acquisition_date' => now()->toDateString(),
    ]);

    $issue = BookIssue::create([
        'issue_no' => 'ISS-CLR-'.uniqid(),
        'library_member_id' => $member->id,
        'book_id' => $book->id,
        'book_copy_id' => $copy->id,
        'user_id' => $student->id,
        'issue_date' => now()->subDays(5)->toDateString(),
        'due_date' => now()->addDays(10)->toDateString(),
        'status' => BookIssue::STATUS_ISSUED,
    ]);

    // Eligibility check must indicate failure with unreturned books
    $eligibility = LibraryClearanceService::checkEligibility($student);
    expect($eligibility['eligible'])->toBeFalse();
    expect($eligibility['active_loans_count'])->toBe(1);
    expect($eligibility['active_loans'])->toHaveCount(1);
    expect($eligibility['active_loans']->first()->book->title)->toBe('Modern India History');

    // Attempting to request refund must throw ValidationException
    try {
        LibraryDepositService::requestRefund(
            deposit: $deposit,
            requestedBy: $student->id,
            bankDetails: [
                'refund_reason' => 'Graduation refund request',
                'upi_id' => 'student@upi',
            ]
        );
        test()->fail('Expected ValidationException was not thrown for unreturned books');
    } catch (ValidationException $e) {
        $messages = collect($e->errors())->flatten()->implode(' ');
        expect($messages)->toContain('Modern India History');
        expect($messages)->toContain('ACC-HIST-');
    }

    // Deposit must remain active
    expect($deposit->fresh()->status)->toBe(LibraryDeposit::STATUS_ACTIVE);
});

test('refund request succeeds when books are returned, librarian issues clearance certificate, and admin approves refund', function () {
    $admin = User::factory()->create();
    $admin->assignRole('Super Administrator');

    $librarian = User::factory()->create();
    $librarian->assignRole('Librarian');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $deposit = LibraryDeposit::create([
        'deposit_no' => 'DEP-FLOW-'.uniqid(),
        'user_id' => $student->id,
        'amount' => 2000.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    // 1. Student has no unreturned books, request refund succeeds
    $requestedDeposit = LibraryDepositService::requestRefund(
        deposit: $deposit,
        requestedBy: $student->id,
        bankDetails: [
            'refund_reason' => 'Course completed, requesting caution money return',
            'account_number' => '123456789012',
            'ifsc_code' => 'SBIN0001234',
            'bank_name' => 'State Bank of India',
            'account_holder_name' => $student->name,
        ]
    );

    expect($requestedDeposit->status)->toBe(LibraryDeposit::STATUS_REFUND_REQUESTED);
    expect($requestedDeposit->clearance_status)->toBe(LibraryDeposit::CLEARANCE_PENDING);

    // 2. Admin cannot approve before librarian clearance
    try {
        LibraryDepositService::approveRefund(
            deposit: $requestedDeposit,
            reviewedBy: $admin->id,
            approvedAmount: 2000.00
        );
        test()->fail('Expected ValidationException when approving without librarian clearance');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain('clearance certificate');
    }

    // 3. Librarian issues clearance certificate
    $clearedDeposit = LibraryClearanceService::issueClearanceCertificate(
        deposit: $requestedDeposit,
        clearedBy: $librarian->id,
        remarks: 'Verified physical records. Zero unreturned books, zero overdue fines.'
    );

    expect($clearedDeposit->clearance_status)->toBe(LibraryDeposit::CLEARANCE_CLEARED);
    expect($clearedDeposit->clearance_certificate_no)->toStartWith('LIB-CLR-');
    expect($clearedDeposit->cleared_by_id)->toBe($librarian->id);
    expect($clearedDeposit->cleared_at)->not->toBeNull();

    // 4. Admin can now approve refund
    $approvedDeposit = LibraryDepositService::approveRefund(
        deposit: $clearedDeposit,
        reviewedBy: $admin->id,
        approvedAmount: 2000.00,
        adminNotes: 'Clearance verified, refund approved'
    );

    expect($approvedDeposit->status)->toBe(LibraryDeposit::STATUS_REFUND_APPROVED);
    expect((float) $approvedDeposit->refund_approved_amount)->toBe(2000.00);

    // 5. Download Clearance Certificate PDF
    $response = $this->actingAs($student)
        ->get(route('library.clearance.download', $approvedDeposit->id));

    $response->assertOk();
    $response->assertHeader('Content-Type', 'application/pdf');
});

test('student catalog search handles classification_no and category filters without sql error', function () {
    $category = BookCategory::firstOrCreate(['code' => 'CIVIL'], ['name' => 'Civil Services', 'slug' => 'civil-services']);
    $publisher = BookPublisher::firstOrCreate(['name' => 'Oxford Press'], ['slug' => 'oxford-press']);

    $book = Book::create([
        'title' => 'Indian Economy Survey 2026',
        'slug' => 'indian-economy-survey-2026-'.uniqid(),
        'isbn' => '978-93-5000-001-1',
        'book_category_id' => $category->id,
        'book_publisher_id' => $publisher->id,
        'classification_no' => '330.954',
        'price' => 750.00,
        'is_active' => true,
    ]);

    expect($book->classification_no)->toBe('330.954');
    expect($book->classification_number)->toBe('330.954');

    // Execute the exact query executed by student catalog searching
    $search = '330.954';
    $results = Book::query()
        ->where('is_active', true)
        ->where(function ($q) use ($search) {
            $q->where('title', 'like', "%{$search}%")
                ->orWhere('isbn', 'like', "%{$search}%")
                ->orWhere('classification_no', 'like', "%{$search}%")
                ->orWhere('classification_number', 'like', "%{$search}%")
                ->orWhereHas('authors', function ($aq) use ($search) {
                    $aq->where('name', 'like', "%{$search}%");
                });
        })
        ->get();

    expect($results->count())->toBeGreaterThanOrEqual(1);
    expect($results->first()->id)->toBe($book->id);
});

test('in-house reading facility allows issuing multiple books and journals without security deposit criteria', function () {
    $librarian = User::factory()->create();
    $librarian->assignRole('Librarian');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $member = LibraryMember::create([
        'member_number' => 'LIB-STU-'.uniqid(),
        'user_id' => $student->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $student->name,
        'email' => $student->email,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    // Student has ₹0 active security deposit!
    expect($member->getActiveSecurityDeposit())->toBe(0.0);

    // Create 2 materials: 1 high-value book and 1 journal
    $category = BookCategory::firstOrCreate(['code' => 'LAW'], ['name' => 'Law & Constitution', 'slug' => 'law-constitution']);
    $publisher = BookPublisher::firstOrCreate(['name' => 'LexisNexis'], ['slug' => 'lexisnexis']);

    $book = Book::create([
        'title' => 'Constitutional Law of India (Vol 1)',
        'slug' => 'constitutional-law-'.uniqid(),
        'isbn' => '978-0-1234-999-1',
        'book_category_id' => $category->id,
        'book_publisher_id' => $publisher->id,
        'material_type' => Book::MATERIAL_BOOK,
        'price' => 3500.00,
        'is_active' => true,
    ]);

    $journal = Book::create([
        'title' => 'Indian Journal of Public Administration (Q3 2026)',
        'slug' => 'ijpa-q3-2026-'.uniqid(),
        'isbn' => '978-0-1234-999-2',
        'book_category_id' => $category->id,
        'book_publisher_id' => $publisher->id,
        'material_type' => Book::MATERIAL_JOURNAL,
        'price' => 1200.00,
        'is_active' => true,
    ]);

    $copy1 = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-LAW-'.uniqid(),
        'barcode' => 'BC-LAW-'.uniqid(),
        'status' => BookCopy::STATUS_AVAILABLE,
        'condition' => BookCopy::CONDITION_GOOD,
        'price' => 3500.00,
        'acquisition_date' => now()->toDateString(),
    ]);

    $copy2 = BookCopy::create([
        'book_id' => $journal->id,
        'accession_no' => 'ACC-JRN-'.uniqid(),
        'barcode' => 'BC-JRN-'.uniqid(),
        'status' => BookCopy::STATUS_AVAILABLE,
        'condition' => BookCopy::CONDITION_GOOD,
        'price' => 1200.00,
        'acquisition_date' => now()->toDateString(),
    ]);

    // Issue both copies together for in-house reading room session
    $session = BookCirculationService::issueInHouseReading(
        copies: [$copy1, $copy2],
        member: $member,
        readerDetails: [
            'name' => $student->name,
            'phone' => '9876543210',
            'seat_no' => 'Desk #14 (Reading Hall A)',
        ],
        dueTime: '17:00',
        notes: 'In-house preparation for UPSC Mains',
        issuedBy: $librarian
    );

    expect($session)->toBeInstanceOf(Collection::class);
    expect($session)->toHaveCount(2);

    $firstIssue = $session->first();
    $sessionToken = $firstIssue->inhouse_session_token;
    expect($sessionToken)->toBeString()->not->toBeEmpty();

    // Verify copy statuses changed to in_house_reading
    $copy1->refresh();
    $copy2->refresh();
    expect($copy1->status)->toBe(BookCopy::STATUS_IN_HOUSE_READING);
    expect($copy2->status)->toBe(BookCopy::STATUS_IN_HOUSE_READING);

    // Verify issue record attributes
    expect($firstIssue->issue_type)->toBe(BookIssue::TYPE_IN_HOUSE);
    expect($firstIssue->is_deposit_bypassed)->toBeTrue();
    expect($firstIssue->due_time)->toBe('17:00');
    expect($firstIssue->reading_room_seat_no)->toBe('Desk #14 (Reading Hall A)');
    expect($firstIssue->isInHouseReading())->toBeTrue();

    // 1. Librarian returns the first item individually
    $returnedIssue = BookCirculationService::returnInHouseIssue(
        issue: $firstIssue,
        returnedBy: $librarian,
        notes: 'Finished reading first volume'
    );

    expect($returnedIssue->status)->toBe(BookIssue::STATUS_RETURNED);
    expect($returnedIssue->returned_time)->not->toBeNull();
    $copy1->refresh();
    expect($copy1->status)->toBe(BookCopy::STATUS_AVAILABLE);

    // 2. Librarian batch returns the remainder of the session in 1 click
    $returnedCount = BookCirculationService::returnInHouseSession(
        sessionToken: $sessionToken,
        returnedBy: $librarian,
        notes: 'Reader left reading hall'
    );

    expect($returnedCount)->toBe(1);
    $copy2->refresh();
    expect($copy2->status)->toBe(BookCopy::STATUS_AVAILABLE);
});

test('in-house reading hours and settings can be configured via LibraryPolicyService', function () {
    Setting::set('library.inhouse_reading_start_time', '10:00');
    Setting::set('library.inhouse_reading_end_time', '18:00');
    Setting::set('library.inhouse_reading_max_items', 6);

    expect(LibraryPolicyService::getInhouseReadingStartTime())->toBe('10:00');
    expect(LibraryPolicyService::getInhouseReadingEndTime())->toBe('18:00');
    expect(LibraryPolicyService::getInhouseReadingMaxItems())->toBe(6);
    expect(LibraryPolicyService::getInhouseReadingHoursFormatted())->toBe('10:00 AM - 06:00 PM');
});

test('student requesting loan renewal creates pending renewal request requiring librarian approval', function () {
    $librarian = User::factory()->create();
    $librarian->assignRole('Librarian');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $member = LibraryMember::create([
        'member_number' => 'LIB-STU-'.uniqid(),
        'user_id' => $student->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $student->name,
        'email' => $student->email,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    LibraryDeposit::create([
        'deposit_no' => 'DEP-'.uniqid(),
        'user_id' => $student->id,
        'amount' => 1500.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    $category = BookCategory::firstOrCreate(['code' => 'HIST'], ['name' => 'History', 'slug' => 'history']);
    $publisher = BookPublisher::firstOrCreate(['name' => 'National Press'], ['slug' => 'national-press']);

    $book = Book::create([
        'title' => 'Modern Indian History',
        'slug' => 'modern-indian-history-'.uniqid(),
        'isbn' => '978-0-1234-555-1',
        'book_category_id' => $category->id,
        'book_publisher_id' => $publisher->id,
        'price' => 450.00,
        'is_active' => true,
    ]);

    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-HIST-'.uniqid(),
        'barcode' => 'BC-HIST-'.uniqid(),
        'status' => BookCopy::STATUS_AVAILABLE,
        'condition' => BookCopy::CONDITION_GOOD,
        'price' => 450.00,
        'acquisition_date' => now()->toDateString(),
    ]);

    // Issue the book
    $issue = BookCirculationService::issueBook(
        member: $member,
        copy: $copy,
        loanDays: 15,
        issuedBy: $librarian
    );

    $originalDueDate = $issue->due_date->copy();
    expect($issue->renewal_count)->toBe(0);
    expect($issue->renewal_status)->toBe(BookIssue::RENEWAL_NONE);

    // 1. Student requests loan renewal
    $requestedIssue = BookCirculationService::requestLoanRenewal(
        issue: $issue,
        reason: 'Preparing for test next week, need extra time with text',
        requestedBy: $student
    );

    // Loan is NOT immediately renewed without librarian approval!
    expect($requestedIssue->renewal_status)->toBe(BookIssue::RENEWAL_REQUESTED);
    expect($requestedIssue->isRenewalPending())->toBeTrue();
    expect($requestedIssue->status)->toBe(BookIssue::STATUS_ISSUED);
    expect($requestedIssue->renewal_count)->toBe(0);
    expect($requestedIssue->due_date->toDateString())->toBe($originalDueDate->toDateString());
    expect($requestedIssue->renewal_request_reason)->toContain('Preparing for test');

    // Trying to request again throws validation exception
    try {
        BookCirculationService::requestLoanRenewal(
            issue: $requestedIssue,
            requestedBy: $student
        );
        test()->fail('Expected ValidationException when another renewal request is already pending');
    } catch (ValidationException $e) {
        expect(collect($e->errors())->flatten()->implode(' '))->toContain('already pending');
    }

    // 2. Librarian reviews and approves the renewal request
    $approvedIssue = BookCirculationService::approveLoanRenewal(
        issue: $requestedIssue,
        notes: 'Approved for exam preparation',
        reviewedBy: $librarian
    );

    expect($approvedIssue->renewal_status)->toBe(BookIssue::RENEWAL_APPROVED);
    expect($approvedIssue->status)->toBe(BookIssue::STATUS_RENEWED);
    expect($approvedIssue->renewal_count)->toBe(1);
    expect($approvedIssue->due_date->toDateString())->toBe($originalDueDate->copy()->addDays(15)->toDateString());
    expect($approvedIssue->renewal_reviewed_by)->toBe($librarian->id);
    expect($approvedIssue->renewal_reviewed_at)->not->toBeNull();
});

test('librarian can reject pending renewal request with reason preserving original due date', function () {
    $librarian = User::factory()->create();
    $librarian->assignRole('Librarian');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $member = LibraryMember::create([
        'member_number' => 'LIB-STU-'.uniqid(),
        'user_id' => $student->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $student->name,
        'email' => $student->email,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    LibraryDeposit::create([
        'deposit_no' => 'DEP-'.uniqid(),
        'user_id' => $student->id,
        'amount' => 1500.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    $category = BookCategory::firstOrCreate(['code' => 'SOC'], ['name' => 'Sociology', 'slug' => 'sociology']);
    $publisher = BookPublisher::firstOrCreate(['name' => 'Sage'], ['slug' => 'sage']);

    $book = Book::create([
        'title' => 'Social Problems in India',
        'slug' => 'social-problems-'.uniqid(),
        'isbn' => '978-0-1234-777-1',
        'book_category_id' => $category->id,
        'book_publisher_id' => $publisher->id,
        'price' => 380.00,
        'is_active' => true,
    ]);

    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-SOC-'.uniqid(),
        'barcode' => 'BC-SOC-'.uniqid(),
        'status' => BookCopy::STATUS_AVAILABLE,
        'condition' => BookCopy::CONDITION_GOOD,
        'price' => 380.00,
        'acquisition_date' => now()->toDateString(),
    ]);

    $issue = BookCirculationService::issueBook(
        member: $member,
        copy: $copy,
        loanDays: 15,
        issuedBy: $librarian
    );

    $originalDueDate = $issue->due_date->copy();

    // Student requests renewal
    $pendingIssue = BookCirculationService::requestLoanRenewal(
        issue: $issue,
        reason: 'Need for seminar paper',
        requestedBy: $student
    );

    // Librarian declines renewal request
    $rejectedIssue = BookCirculationService::rejectLoanRenewal(
        issue: $pendingIssue,
        rejectionReason: 'Book requested for recall by department faculty',
        reviewedBy: $librarian
    );

    expect($rejectedIssue->renewal_status)->toBe(BookIssue::RENEWAL_REJECTED);
    expect($rejectedIssue->isRenewalRejected())->toBeTrue();
    expect($rejectedIssue->renewal_rejection_reason)->toBe('Book requested for recall by department faculty');
    expect($rejectedIssue->due_date->toDateString())->toBe($originalDueDate->toDateString());
    expect($rejectedIssue->renewal_count)->toBe(0);
});

test('student portal opens renewal request modal and submits request without browser alert', function () {
    $librarian = User::factory()->create();
    $librarian->assignRole('Librarian');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $member = LibraryMember::create([
        'member_number' => 'LIB-MODAL-'.uniqid(),
        'user_id' => $student->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $student->name,
        'email' => $student->email,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    LibraryDeposit::create([
        'deposit_no' => 'DEP-MODAL-'.uniqid(),
        'user_id' => $student->id,
        'amount' => 1000.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    $category = BookCategory::firstOrCreate(['code' => 'POL'], ['name' => 'Polity', 'slug' => 'polity']);
    $publisher = BookPublisher::firstOrCreate(['name' => 'TMH'], ['slug' => 'tmh']);

    $book = Book::create([
        'title' => 'Indian Polity by Laxmikanth',
        'slug' => 'indian-polity-modal-'.uniqid(),
        'isbn' => '978-0-9999-888-1',
        'book_category_id' => $category->id,
        'book_publisher_id' => $publisher->id,
        'price' => 600.00,
        'is_active' => true,
    ]);

    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-POL-'.uniqid(),
        'barcode' => 'BC-POL-'.uniqid(),
        'status' => BookCopy::STATUS_AVAILABLE,
        'condition' => BookCopy::CONDITION_GOOD,
        'price' => 600.00,
        'acquisition_date' => now()->toDateString(),
    ]);

    $issue = BookCirculationService::issueBook(
        member: $member,
        copy: $copy,
        loanDays: 15,
        issuedBy: $librarian
    );

    Livewire::actingAs($student)
        ->test('pages::student.library')
        ->set('activeTab', 'my_loans')
        ->call('openRenewalModal', $issue->id)
        ->assertSet('showRenewalModal', true)
        ->assertSet('renewalLoanId', $issue->id)
        ->assertSee('Indian Polity by Laxmikanth')
        ->assertSee('Request Loan Renewal')
        ->set('renewalReason', 'Preparing for UPSC Preliminary Examination')
        ->call('submitRenewalRequest')
        ->assertSet('showRenewalModal', false);

    $freshIssue = $issue->fresh();
    expect($freshIssue->renewal_status)->toBe(BookIssue::RENEWAL_REQUESTED);
    expect($freshIssue->renewal_request_reason)->toBe('Preparing for UPSC Preliminary Examination');
    expect($freshIssue->due_date->toDateString())->toBe($issue->due_date->toDateString());
});

test('admin circulation desk opens approve renewal and direct renew modals without browser alert', function () {
    $librarian = User::factory()->create();
    $librarian->assignRole('Librarian');

    $student = User::factory()->create();
    $student->assignRole('Student');

    $member = LibraryMember::create([
        'member_number' => 'LIB-ADMIN-MODAL-'.uniqid(),
        'user_id' => $student->id,
        'member_type' => LibraryMember::TYPE_STUDENT,
        'name' => $student->name,
        'email' => $student->email,
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    LibraryDeposit::create([
        'deposit_no' => 'DEP-ADM-'.uniqid(),
        'user_id' => $student->id,
        'amount' => 1500.00,
        'status' => LibraryDeposit::STATUS_ACTIVE,
        'deposit_date' => now()->toDateString(),
    ]);

    $category = BookCategory::firstOrCreate(['code' => 'ENV'], ['name' => 'Environment', 'slug' => 'environment']);
    $publisher = BookPublisher::firstOrCreate(['name' => 'Orient'], ['slug' => 'orient']);

    $book = Book::create([
        'title' => 'Ecology and Environment',
        'slug' => 'ecology-modal-'.uniqid(),
        'isbn' => '978-0-7777-666-1',
        'book_category_id' => $category->id,
        'book_publisher_id' => $publisher->id,
        'price' => 500.00,
        'is_active' => true,
    ]);

    $copy = BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-ENV-'.uniqid(),
        'barcode' => 'BC-ENV-'.uniqid(),
        'status' => BookCopy::STATUS_AVAILABLE,
        'condition' => BookCopy::CONDITION_GOOD,
        'price' => 500.00,
        'acquisition_date' => now()->toDateString(),
    ]);

    $issue = BookCirculationService::issueBook(
        member: $member,
        copy: $copy,
        loanDays: 15,
        issuedBy: $librarian
    );

    // 1. Direct Staff Renew Modal
    Livewire::actingAs($librarian)
        ->test('pages::admin.library.circulation')
        ->set('activeTab', 'active_loans')
        ->call('openDirectRenewModal', $issue->id)
        ->assertSet('directRenewTargetIssue.id', $issue->id)
        ->assertDispatched('modal-open')
        ->call('executeDirectRenew')
        ->assertDispatched('modal-close')
        ->assertSet('directRenewTargetIssue', null);

    expect($issue->fresh()->renewal_count)->toBe(1);

    // 2. Student requests 2nd renewal
    $issue2 = BookCirculationService::requestLoanRenewal(
        issue: $issue->fresh(),
        reason: 'Preparing for tests',
        requestedBy: $student
    );

    // 3. Admin opens Approve Renewal Modal
    Livewire::actingAs($librarian)
        ->test('pages::admin.library.circulation')
        ->set('activeTab', 'renewal_requests')
        ->call('openApproveRenewalModal', $issue2->id)
        ->assertSet('renewalApproveTargetIssue.id', $issue2->id)
        ->assertDispatched('modal-open')
        ->set('renewalApprovalNotes', 'Approved via admin modal endorsement')
        ->call('executeApproveRenewal')
        ->assertDispatched('modal-close')
        ->assertSet('renewalApproveTargetIssue', null);

    $finalIssue = $issue2->fresh();
    expect($finalIssue->renewal_status)->toBe(BookIssue::RENEWAL_APPROVED);
    expect($finalIssue->renewal_count)->toBe(2);
    expect($finalIssue->renewal_reviewed_by)->toBe($librarian->id);
});

test('reports route and component load without price column error and exports work', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('library.reports.view');

    $category = BookCategory::firstOrCreate(['code' => 'REP1'], ['name' => 'Report Cat', 'slug' => 'report-cat']);
    $book = Book::create([
        'title' => 'Report Intelligence Book',
        'slug' => 'report-intel-book',
        'category_id' => $category->id,
        'price' => 750.00,
        'status' => 'active',
    ]);
    BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-REP-001',
        'barcode' => 'ACC-REP-001',
        'purchase_cost' => 750.00,
        'price' => 750.00,
        'status' => BookCopy::STATUS_AVAILABLE,
        'acquisition_date' => now()->toDateString(),
    ]);

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.library.reports')
        ->assertOk();

    expect($component->get('summaryStats')['asset_value'])->toBeGreaterThanOrEqual(750.00);

    // Test all report exports
    $defaultersRes = $component->instance()->exportDefaultersCsv();
    expect($defaultersRes)->toBeInstanceOf(StreamedResponse::class);

    $inventoryRes = $component->instance()->exportInventoryCsv();
    expect($inventoryRes)->toBeInstanceOf(StreamedResponse::class);

    $popularRes = $component->instance()->exportPopularBooksCsv();
    expect($popularRes)->toBeInstanceOf(StreamedResponse::class);

    $revenueRes = $component->instance()->exportRevenueCsv();
    expect($revenueRes)->toBeInstanceOf(StreamedResponse::class);
});

test('fines route and component load without amount column error and exports work', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('library.fines.manage');

    $member = LibraryMember::create([
        'member_number' => 'LIB-FINE-01',
        'name' => 'Fine Test Member',
        'email' => 'fine@example.com',
        'status' => LibraryMember::STATUS_ACTIVE,
    ]);

    $fine = BookFine::create([
        'fine_number' => 'FINE-TEST-001',
        'fine_type' => 'damage',
        'library_member_id' => $member->id,
        'amount' => 150.00,
        'calculated_fine' => 150.00,
        'payable_amount' => 150.00,
        'paid_amount' => 0.00,
        'status' => BookFine::STATUS_PENDING,
    ]);

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.library.fines')
        ->assertOk();

    expect($component->get('stats')['total_assessed'])->toBeGreaterThanOrEqual(150.00);

    $csvRes = $component->instance()->exportCsv();
    expect($csvRes)->toBeInstanceOf(StreamedResponse::class);

    $xlsxRes = $component->instance()->export('xlsx');
    expect($xlsxRes)->not->toBeNull();
});

test('masters component supports export and import for categories, authors, and publishers', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('library.categories.manage');

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.library.masters')
        ->assertOk();

    // Export categories
    $component->set('activeTab', 'categories');
    $catCsv = $component->instance()->export('csv');
    expect($catCsv)->not->toBeNull();

    // Template download
    $tpl = $component->instance()->downloadTemplate();
    expect($tpl)->toBeInstanceOf(StreamedResponse::class);

    // Export authors
    $component->set('activeTab', 'authors');
    $authCsv = $component->instance()->export('csv');
    expect($authCsv)->not->toBeNull();

    // Export publishers
    $component->set('activeTab', 'publishers');
    $pubCsv = $component->instance()->export('csv');
    expect($pubCsv)->not->toBeNull();

    // Test import categories via service
    $tempFile = tempnam(sys_get_temp_dir(), 'cat_').'.csv';
    file_put_contents($tempFile, "Code,Name,DDC_Classification,Description,Status\nGEO101,Geology & Mining,550,Earth sciences,active\n");
    $result = LibraryImportExportService::importCategories($tempFile);
    expect($result['imported'])->toBe(1);
    expect(BookCategory::where('code', 'GEO101')->exists())->toBeTrue();
    @unlink($tempFile);
});

test('books and members components support export and import templates', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('library.books.manage');
    $admin->givePermissionTo('library.members.manage');

    // Books export & template
    $booksComp = Livewire::actingAs($admin)
        ->test('pages::admin.library.books')
        ->assertOk();

    $bExport = $booksComp->instance()->export('csv');
    expect($bExport)->not->toBeNull();

    $bTpl = $booksComp->instance()->downloadTemplate();
    expect($bTpl)->toBeInstanceOf(StreamedResponse::class);

    // Members export & template
    $membersComp = Livewire::actingAs($admin)
        ->test('pages::admin.library.members')
        ->assertOk();

    $mExportCsv = $membersComp->instance()->exportCsv();
    expect($mExportCsv)->toBeInstanceOf(StreamedResponse::class);

    $mTpl = $membersComp->instance()->downloadTemplate();
    expect($mTpl)->toBeInstanceOf(StreamedResponse::class);
});

test('reports component can switch to popular_books tab without condition column error', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('library.view');

    $author = BookAuthor::create(['name' => 'Popular Author', 'country' => 'Indian']);
    $book = Book::create([
        'title' => 'Civil Services Exam Guide',
        'slug' => 'civil-services-exam-guide',
        'price' => 450.00,
        'is_active' => true,
    ]);
    $book->authors()->attach($author->id);

    BookCopy::create([
        'book_id' => $book->id,
        'accession_no' => 'ACC-POP-001',
        'barcode' => 'ACC-POP-001',
        'book_condition' => BookCopy::CONDITION_GOOD,
        'condition' => BookCopy::CONDITION_GOOD,
        'status' => BookCopy::STATUS_AVAILABLE,
        'acquisition_date' => now()->toDateString(),
    ]);

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.library.reports')
        ->assertOk()
        ->call('selectTab', 'popular_books')
        ->assertSet('activeTab', 'popular_books')
        ->assertOk();

    // Verify stock condition distribution rendered
    $component->assertSee('Stock Condition Distribution');
    $component->assertSee('Top Borrowed Books');
});

test('masters component author and publisher forms save and edit all database fields', function () {
    $admin = User::factory()->create();
    $admin->givePermissionTo('library.authors.manage');
    $admin->givePermissionTo('library.publishers.manage');

    $component = Livewire::actingAs($admin)
        ->test('pages::admin.library.masters')
        ->assertOk();

    // 1. Create Author with all fields
    $component->call('openAuthorModal')
        ->set('authorName', 'Ramachandra Guha')
        ->set('authorNationality', 'Indian')
        ->set('authorEmail', 'rguha@historian.org')
        ->set('authorWebsite', 'https://ramachandraguha.in')
        ->set('authorBio', 'Eminent Indian historian and author of India After Gandhi.')
        ->set('authorActive', true)
        ->call('saveAuthor')
        ->assertHasNoErrors();

    $author = BookAuthor::where('name', 'Ramachandra Guha')->first();
    expect($author)->not->toBeNull();
    expect($author->country)->toBe('Indian');
    expect($author->nationality)->toBe('Indian');
    expect($author->email)->toBe('rguha@historian.org');
    expect($author->website)->toBe('https://ramachandraguha.in');
    expect($author->is_active)->toBeTrue();
    expect($author->status)->toBe('active');

    // Edit Author
    $component->call('openAuthorModal', $author->id)
        ->assertSet('authorWebsite', 'https://ramachandraguha.in')
        ->assertSet('authorActive', true)
        ->set('authorWebsite', 'https://newdomain.org')
        ->set('authorActive', false)
        ->call('saveAuthor')
        ->assertHasNoErrors();

    $author->refresh();
    expect($author->website)->toBe('https://newdomain.org');
    expect($author->is_active)->toBeFalse();
    expect($author->status)->toBe('inactive');

    // 2. Create Publisher with all fields
    $component->call('openPublisherModal')
        ->set('pubName', 'Penguin Random House India')
        ->set('pubContact', 'Rajesh Sharma')
        ->set('pubEmail', 'info@penguinrandomhouse.in')
        ->set('pubPhone', '+91 11 4613 1400')
        ->set('pubWebsite', 'https://penguin.co.in')
        ->set('pubAddress', '7th Floor, Infinity Tower C, DLF Cyber City, Gurgaon')
        ->set('pubActive', true)
        ->call('savePublisher')
        ->assertHasNoErrors();

    $publisher = BookPublisher::where('name', 'Penguin Random House India')->first();
    expect($publisher)->not->toBeNull();
    expect($publisher->contact_person)->toBe('Rajesh Sharma');
    expect($publisher->email)->toBe('info@penguinrandomhouse.in');
    expect($publisher->phone)->toBe('+91 11 4613 1400');
    expect($publisher->website)->toBe('https://penguin.co.in');
    expect($publisher->address)->toBe('7th Floor, Infinity Tower C, DLF Cyber City, Gurgaon');
    expect($publisher->is_active)->toBeTrue();
    expect($publisher->status)->toBe('active');

    // Edit Publisher
    $component->call('openPublisherModal', $publisher->id)
        ->assertSet('pubWebsite', 'https://penguin.co.in')
        ->assertSet('pubAddress', '7th Floor, Infinity Tower C, DLF Cyber City, Gurgaon')
        ->assertSet('pubActive', true)
        ->set('pubPhone', '+91 98765 00000')
        ->set('pubActive', false)
        ->call('savePublisher')
        ->assertHasNoErrors();

    $publisher->refresh();
    expect($publisher->phone)->toBe('+91 98765 00000');
    expect($publisher->is_active)->toBeFalse();
    expect($publisher->status)->toBe('inactive');
});
