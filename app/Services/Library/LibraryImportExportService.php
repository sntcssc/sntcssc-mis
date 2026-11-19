<?php

namespace App\Services\Library;

use App\Models\Book;
use App\Models\BookAuthor;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\BookPublisher;
use App\Models\LibraryMember;
use App\Support\Export\TableExporter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LibraryImportExportService
{
    /**
     * Parse rows from either CSV or Excel file.
     *
     * @return array<int, array<string, string|null>>
     */
    public static function parseRowsFromFile(string $filePath): array
    {
        $rows = [];

        if (str_ends_with(strtolower($filePath), '.csv')) {
            if (($handle = fopen($filePath, 'r')) !== false) {
                // Strip possible UTF-8 BOM
                $bom = fread($handle, 3);
                if ($bom !== "\xEF\xBB\xBF") {
                    rewind($handle);
                }

                $header = null;
                while (($data = fgetcsv($handle, 2000, ',')) !== false) {
                    if (! $header) {
                        $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), $data);
                    } else {
                        if (count($header) === count($data)) {
                            $rows[] = array_combine($header, $data);
                        }
                    }
                }
                fclose($handle);
            }
        } else {
            // Excel import (xlsx, xls, etc.)
            $sheet = Excel::toArray([], $filePath);
            if (! empty($sheet) && ! empty($sheet[0])) {
                $sheetRows = $sheet[0];
                $rawHeader = array_shift($sheetRows);
                $header = array_map(fn ($h) => strtolower(trim(str_replace([' ', '-', '.'], '_', (string) $h))), (array) $rawHeader);
                foreach ($sheetRows as $r) {
                    if (count($header) === count($r)) {
                        $rows[] = array_combine($header, $r);
                    }
                }
            }
        }

        return $rows;
    }

    /**
     * Import books and physical copies from a CSV or Excel file.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public static function importBooksAndCopies(string $filePath): array
    {
        $rows = self::parseRowsFromFile($filePath);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $title = trim($row['title'] ?? $row['book_title'] ?? '');
            if ($title === '') {
                $skipped++;

                continue;
            }

            $accessionNo = trim($row['accession_no'] ?? $row['accession_number'] ?? '');
            if ($accessionNo !== '' && BookCopy::where('accession_no', $accessionNo)->exists()) {
                $errors[] = "Row {$line}: Accession number '{$accessionNo}' already exists in database. Skipped duplicate.";
                $skipped++;

                continue;
            }

            try {
                DB::transaction(function () use ($row, $title, $accessionNo, &$imported) {
                    // Category
                    $categoryName = trim($row['category'] ?? $row['category_name'] ?? 'General');
                    $category = BookCategory::firstOrCreate(
                        ['name' => $categoryName],
                        ['slug' => Str::slug($categoryName), 'code' => strtoupper(Str::slug(substr($categoryName, 0, 4)).rand(10, 99)), 'status' => 'active', 'is_active' => true]
                    );

                    // Publisher
                    $publisherName = trim($row['publisher'] ?? $row['publisher_name'] ?? '');
                    $publisherId = null;
                    if ($publisherName !== '') {
                        $publisher = BookPublisher::firstOrCreate(
                            ['name' => $publisherName],
                            ['slug' => Str::slug($publisherName), 'status' => 'active', 'is_active' => true]
                        );
                        $publisherId = $publisher->id;
                    }

                    // Book Master
                    $isbn = trim($row['isbn'] ?? '');
                    $price = (float) ($row['price'] ?? $row['purchase_cost'] ?? 0.00);
                    $classificationNo = trim($row['classification_number'] ?? $row['call_no'] ?? $row['call_number'] ?? '');

                    $book = Book::where('title', $title)
                        ->when($isbn, fn ($q) => $q->where('isbn', $isbn))
                        ->first();

                    if (! $book) {
                        $book = Book::create([
                            'title' => $title,
                            'subtitle' => trim($row['subtitle'] ?? '') ?: null,
                            'slug' => Str::slug($title).'-'.Str::random(5),
                            'isbn' => $isbn ?: null,
                            'category_id' => $category->id,
                            'publisher_id' => $publisherId,
                            'edition' => trim($row['edition'] ?? '') ?: null,
                            'classification_number' => $classificationNo ?: null,
                            'classification_no' => $classificationNo ?: null,
                            'price' => $price,
                            'status' => 'active',
                            'is_active' => true,
                        ]);
                    }

                    // Author
                    $authorName = trim($row['author'] ?? $row['author_name'] ?? '');
                    if ($authorName !== '') {
                        $author = BookAuthor::firstOrCreate(
                            ['name' => $authorName],
                            ['slug' => Str::slug($authorName), 'status' => 'active', 'is_active' => true]
                        );
                        if (! $book->authors()->where('author_id', $author->id)->exists()) {
                            $book->authors()->attach($author->id, ['is_primary' => true]);
                        }
                    }

                    // Physical Copy
                    $acc = $accessionNo ?: self::generateNextAccessionNo();
                    $barcode = trim($row['barcode'] ?? '') ?: $acc;
                    $shelfLocation = trim($row['shelf_location'] ?? $row['shelf'] ?? $row['location'] ?? 'Main Stack');
                    $supplier = trim($row['supplier'] ?? $row['supplier_details'] ?? '');
                    $invoiceNo = trim($row['invoice_number'] ?? $row['invoice_no'] ?? '');
                    $fundingSource = trim($row['funding_source'] ?? 'Institutional Fund');
                    $condition = strtolower(trim($row['condition'] ?? 'good'));
                    if (! in_array($condition, ['new', 'good', 'fair', 'damaged', 'worn', 'lost'], true)) {
                        $condition = 'good';
                    }

                    $copyNumber = $book->copies()->count() + 1;

                    BookCopy::create([
                        'book_id' => $book->id,
                        'accession_no' => $acc,
                        'barcode' => $barcode,
                        'copy_number' => $copyNumber,
                        'acquisition_date' => now()->toDateString(),
                        'purchase_cost' => $price,
                        'price' => $price,
                        'supplier_details' => $supplier ?: null,
                        'invoice_number' => $invoiceNo ?: null,
                        'funding_source' => $fundingSource,
                        'classification_number' => $classificationNo ?: null,
                        'classification_no' => $classificationNo ?: null,
                        'shelf_location' => $shelfLocation,
                        'book_condition' => $condition,
                        'status' => BookCopy::STATUS_AVAILABLE,
                    ]);

                    $imported++;
                });
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Import Book Categories from CSV/Excel file.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public static function importCategories(string $filePath): array
    {
        $rows = self::parseRowsFromFile($filePath);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $name = trim($row['name'] ?? $row['category_name'] ?? '');
            if ($name === '') {
                $skipped++;

                continue;
            }

            $code = strtoupper(trim($row['code'] ?? $row['category_code'] ?? ''));
            if ($code === '') {
                $code = strtoupper(Str::slug(substr($name, 0, 4)).rand(10, 99));
            }

            $ddc = trim($row['ddc_classification'] ?? $row['ddc'] ?? '');
            $description = trim($row['description'] ?? '');
            $isActive = ! isset($row['status']) || in_array(strtolower(trim($row['status'])), ['1', 'true', 'active', 'yes'], true);

            try {
                BookCategory::updateOrCreate(
                    ['code' => $code],
                    [
                        'name' => $name,
                        'slug' => Str::slug($name),
                        'ddc_classification' => $ddc ?: null,
                        'description' => $description ?: null,
                        'status' => $isActive ? 'active' : 'inactive',
                        'is_active' => $isActive,
                    ]
                );
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Import Book Authors from CSV/Excel file.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public static function importAuthors(string $filePath): array
    {
        $rows = self::parseRowsFromFile($filePath);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $name = trim($row['name'] ?? $row['author_name'] ?? '');
            if ($name === '') {
                $skipped++;

                continue;
            }

            $email = trim($row['email'] ?? '');
            $country = trim($row['country'] ?? $row['nationality'] ?? '');
            $bio = trim($row['biography'] ?? $row['bio'] ?? '');
            $website = trim($row['website'] ?? '');
            $isActive = ! isset($row['status']) || in_array(strtolower(trim($row['status'])), ['1', 'true', 'active', 'yes'], true);

            try {
                BookAuthor::updateOrCreate(
                    ['name' => $name],
                    [
                        'slug' => Str::slug($name),
                        'email' => $email ?: null,
                        'country' => $country ?: null,
                        'biography' => $bio ?: null,
                        'website' => $website ?: null,
                        'status' => $isActive ? 'active' : 'inactive',
                        'is_active' => $isActive,
                    ]
                );
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Import Book Publishers from CSV/Excel file.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public static function importPublishers(string $filePath): array
    {
        $rows = self::parseRowsFromFile($filePath);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $name = trim($row['name'] ?? $row['publisher_name'] ?? '');
            if ($name === '') {
                $skipped++;

                continue;
            }

            $contact = trim($row['contact_person'] ?? $row['contact'] ?? '');
            $email = trim($row['email'] ?? '');
            $phone = trim($row['phone'] ?? '');
            $address = trim($row['address'] ?? '');
            $website = trim($row['website'] ?? '');
            $isActive = ! isset($row['status']) || in_array(strtolower(trim($row['status'])), ['1', 'true', 'active', 'yes'], true);

            try {
                BookPublisher::updateOrCreate(
                    ['name' => $name],
                    [
                        'slug' => Str::slug($name),
                        'contact_person' => $contact ?: null,
                        'email' => $email ?: null,
                        'phone' => $phone ?: null,
                        'address' => $address ?: null,
                        'website' => $website ?: null,
                        'status' => $isActive ? 'active' : 'inactive',
                        'is_active' => $isActive,
                    ]
                );
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Import Library Members from CSV/Excel file.
     *
     * @return array{imported: int, skipped: int, errors: array<string>}
     */
    public static function importMembers(string $filePath): array
    {
        $rows = self::parseRowsFromFile($filePath);

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $name = trim($row['name'] ?? $row['member_name'] ?? '');
            if ($name === '') {
                $skipped++;

                continue;
            }

            $memberNumber = trim($row['member_number'] ?? $row['member_no'] ?? $row['card_number'] ?? '');
            if ($memberNumber === '') {
                $memberNumber = LibraryCardService::generateMemberNumber();
            }

            $memberType = strtolower(trim($row['member_type'] ?? $row['type'] ?? 'student'));
            if (! in_array($memberType, [LibraryMember::TYPE_STUDENT, LibraryMember::TYPE_FACULTY, LibraryMember::TYPE_STAFF, LibraryMember::TYPE_EXTERNAL], true)) {
                $memberType = LibraryMember::TYPE_STUDENT;
            }

            $status = strtolower(trim($row['status'] ?? LibraryMember::STATUS_ACTIVE));
            if (! in_array($status, [LibraryMember::STATUS_ACTIVE, LibraryMember::STATUS_EXPIRED, LibraryMember::STATUS_SUSPENDED, LibraryMember::STATUS_CANCELLED], true)) {
                $status = LibraryMember::STATUS_ACTIVE;
            }

            $email = trim($row['email'] ?? '');
            $phone = trim($row['phone'] ?? '');
            $studentId = trim($row['student_id'] ?? '');
            $gender = trim($row['gender'] ?? '');
            $address = trim($row['address'] ?? '');

            $joiningDate = ! empty($row['joining_date'])
                ? Carbon::parse($row['joining_date'])->toDateString()
                : now()->toDateString();

            $expiryDate = ! empty($row['expiry_date'])
                ? Carbon::parse($row['expiry_date'])->toDateString()
                : now()->addYear()->toDateString();

            try {
                LibraryMember::updateOrCreate(
                    ['member_number' => $memberNumber],
                    [
                        'name' => $name,
                        'member_type' => $memberType,
                        'student_id' => $studentId ?: null,
                        'email' => $email ?: null,
                        'phone' => $phone ?: null,
                        'gender' => $gender ?: null,
                        'address' => $address ?: null,
                        'joining_date' => $joiningDate,
                        'expiry_date' => $expiryDate,
                        'status' => $status,
                    ]
                );
                $imported++;
            } catch (\Throwable $e) {
                $errors[] = "Row {$line}: {$e->getMessage()}";
                $skipped++;
            }
        }

        return [
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * Download sample categories CSV template.
     */
    public static function sampleCategoriesCsv(): StreamedResponse
    {
        $headers = ['Code', 'Name', 'DDC_Classification', 'Description', 'Status'];
        $rows = [
            ['CS101', 'Computer Science & AI', '004', 'Computing, Algorithms, Machine Learning', 'active'],
            ['HIS01', 'Modern Indian History', '954', 'Indian Freedom Movement & Polity', 'active'],
            ['LIT02', 'English Literature', '820', 'Classic and Modern World Literature', 'active'],
        ];

        return TableExporter::csv($headers, $rows, 'sample_library_categories.csv');
    }

    /**
     * Download sample authors CSV template.
     */
    public static function sampleAuthorsCsv(): StreamedResponse
    {
        $headers = ['Name', 'Biography', 'Country', 'Email', 'Website', 'Status'];
        $rows = [
            ['Dr. B.R. Ambedkar', 'Principal Architect of the Constitution of India', 'India', 'info@ambedkar.org', 'https://ambedkar.gov.in', 'active'],
            ['A.P.J. Abdul Kalam', 'Scientist and 11th President of India', 'India', 'kalam@drdo.gov.in', 'https://abdulkalam.nic.in', 'active'],
            ['Amartya Sen', 'Nobel Memorial Prize in Economic Sciences', 'India', 'asen@harvard.edu', 'https://scholar.harvard.edu/sen', 'active'],
        ];

        return TableExporter::csv($headers, $rows, 'sample_library_authors.csv');
    }

    /**
     * Download sample publishers CSV template.
     */
    public static function samplePublishersCsv(): StreamedResponse
    {
        $headers = ['Name', 'Contact_Person', 'Email', 'Phone', 'Address', 'Website', 'Status'];
        $rows = [
            ['Oxford University Press', 'Sales Desk', 'orders@oup.com', '+91 11 40578300', 'New Delhi, India', 'https://global.oup.com', 'active'],
            ['Penguin Random House', 'Distribution Team', 'inquiry@penguinrandomhouse.in', '+91 12 44785600', 'Gurugram, Haryana', 'https://penguin.co.in', 'active'],
            ['Orient Blackswan', 'Institutional Sales', 'sales@orientblackswan.com', '+91 40 27665445', 'Hyderabad, India', 'https://orientblackswan.com', 'active'],
        ];

        return TableExporter::csv($headers, $rows, 'sample_library_publishers.csv');
    }

    /**
     * Download sample members CSV template.
     */
    public static function sampleMembersCsv(): StreamedResponse
    {
        $headers = ['Member_Number', 'Name', 'Email', 'Phone', 'Member_Type', 'Student_ID', 'Gender', 'Joining_Date', 'Expiry_Date', 'Status'];
        $rows = [
            ['LIB-M-00101', 'Aarav Sharma', 'aarav.sharma@example.edu', '9876543210', 'student', 'STU-2026-001', 'Male', now()->format('Y-m-d'), now()->addYear()->format('Y-m-d'), 'active'],
            ['LIB-M-00102', 'Prof. Sunita Roy', 'sunita.roy@example.edu', '9876543211', 'faculty', 'FAC-HIST-04', 'Female', now()->format('Y-m-d'), now()->addYear()->format('Y-m-d'), 'active'],
            ['LIB-M-00103', 'Rajesh K. Verma', 'rajesh.verma@example.edu', '9876543212', 'staff', 'STF-LIB-02', 'Male', now()->format('Y-m-d'), now()->addYear()->format('Y-m-d'), 'active'],
        ];

        return TableExporter::csv($headers, $rows, 'sample_library_members.csv');
    }

    /**
     * Download sample books catalog CSV template.
     */
    public static function sampleBooksCsv(): StreamedResponse
    {
        $headers = [
            'Title', 'Subtitle', 'ISBN', 'Category', 'Author', 'Publisher', 'Edition', 'Price',
            'Accession_No', 'Shelf_Location', 'Supplier', 'Invoice_No', 'Funding_Source', 'Condition',
        ];
        $rows = [
            [
                'India After Gandhi', 'The History of the World\'s Largest Democracy', '9780330505543', 'Modern Indian History',
                'Ramachandra Guha', 'HarperCollins', 'Revised 2nd Ed.', '699.00',
                'ACC-'.now()->format('Y').'-00001', 'History Wing A-1', 'Universal Book Store', 'INV-2026-091', 'State Grant', 'new',
            ],
            [
                'Introduction to Algorithms', 'A Comprehensive Text', '9780262046305', 'Computer Science & AI',
                'Thomas H. Cormen', 'MIT Press', '4th Edition', '1450.00',
                'ACC-'.now()->format('Y').'-00002', 'CS Stack B-3', 'Eastern Book Sellers', 'INV-2026-092', 'Library Endowment', 'new',
            ],
        ];

        return TableExporter::csv($headers, $rows, 'sample_library_catalog_import.csv');
    }

    /**
     * Generate next sequential accession number.
     */
    public static function generateNextAccessionNo(string $prefix = 'ACC'): string
    {
        $year = now()->format('Y');
        $pattern = "{$prefix}-{$year}-%";

        $last = BookCopy::withTrashed()
            ->where('accession_no', 'like', $pattern)
            ->orderBy('id', 'desc')
            ->first();

        $seq = 1;
        if ($last && preg_match("/{$prefix}-{$year}-(\d+)/", $last->accession_no, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('%s-%s-%05d', $prefix, $year, $seq);
    }
}
