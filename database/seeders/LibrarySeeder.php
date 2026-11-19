<?php

namespace Database\Seeders;

use App\Models\Book;
use App\Models\BookAuthor;
use App\Models\BookCategory;
use App\Models\BookCopy;
use App\Models\BookPublisher;
use App\Models\User;
use App\Services\Library\LibraryCardService;
use App\Services\Library\LibraryImportExportService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LibrarySeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::transaction(function () {
            // 1. Categories
            $categories = [
                ['name' => 'Indian Polity & Governance', 'code' => '320.954', 'description' => 'Constitution, political systems, public administration and governance in India.'],
                ['name' => 'Modern & Ancient Indian History', 'code' => '954.03', 'description' => 'Freedom struggle, ancient civilisations, and medieval Indian history.'],
                ['name' => 'Indian Economy & Development', 'code' => '330.954', 'description' => 'Macroeconomics, planning, fiscal policy, and socio-economic reforms.'],
                ['name' => 'Geography & Environment', 'code' => '915.4', 'description' => 'Physical, human, and economic geography of India and the world.'],
                ['name' => 'Ethics, Integrity & Aptitude', 'code' => '170.2', 'description' => 'Moral philosophy, administrative ethics, and case studies.'],
                ['name' => 'International Relations & Diplomacy', 'code' => '327.54', 'description' => 'Foreign policy, bilateral ties, and multilateral organisations.'],
            ];

            $categoryMap = [];
            foreach ($categories as $cat) {
                $categoryMap[$cat['name']] = BookCategory::firstOrCreate(
                    ['name' => $cat['name']],
                    [
                        'slug' => Str::slug($cat['name']),
                        'code' => $cat['code'],
                        'description' => $cat['description'],
                        'status' => 'active',
                    ]
                );
            }

            // 2. Authors
            $authors = [
                ['name' => 'M. Laxmikanth', 'country' => 'India', 'biography' => 'Author of renowned civil services reference books on Indian Polity and Governance.'],
                ['name' => 'Bipan Chandra', 'country' => 'India', 'biography' => 'Historian specialising in the economic and political history of modern India.'],
                ['name' => 'Ramesh Singh', 'country' => 'India', 'biography' => 'Economist and author on Indian Economy for competitive examinations.'],
                ['name' => 'Majid Husain', 'country' => 'India', 'biography' => 'Professor of Geography and author of extensive geographical treatises.'],
                ['name' => 'G. Subba Rao & P.N. Roy Chowdhury', 'country' => 'India', 'biography' => 'Civil servants and educators on ethics and administrative jurisprudence.'],
            ];

            $authorMap = [];
            foreach ($authors as $auth) {
                $authorMap[$auth['name']] = BookAuthor::firstOrCreate(
                    ['name' => $auth['name']],
                    [
                        'slug' => Str::slug($auth['name']),
                        'country' => $auth['country'],
                        'biography' => $auth['biography'],
                        'status' => 'active',
                    ]
                );
            }

            // 3. Publishers
            $publishers = [
                ['name' => 'McGraw Hill Education (India)', 'email' => 'support@mheducation.co.in', 'phone' => '+91-11-4500-1200', 'address' => 'Noida, Uttar Pradesh, India'],
                ['name' => 'Oxford University Press', 'email' => 'custserv.in@oup.com', 'phone' => '+91-120-4348-000', 'address' => 'YMCA Library Building, Jai Singh Road, New Delhi'],
                ['name' => 'Orient Blackswan', 'email' => 'customercare@orientblackswan.com', 'phone' => '+91-40-2766-2849', 'address' => 'Himayatnagar, Hyderabad, Telangana'],
                ['name' => 'Access Publishing', 'email' => 'editorial@accesspublishing.in', 'phone' => '+91-11-2327-4400', 'address' => 'Ansari Road, Daryaganj, New Delhi'],
            ];

            $publisherMap = [];
            foreach ($publishers as $pub) {
                $publisherMap[$pub['name']] = BookPublisher::firstOrCreate(
                    ['name' => $pub['name']],
                    [
                        'slug' => Str::slug($pub['name']),
                        'email' => $pub['email'],
                        'phone' => $pub['phone'],
                        'address' => $pub['address'],
                        'status' => 'active',
                    ]
                );
            }

            // 4. Books and Accession Copies
            $booksData = [
                [
                    'title' => 'Indian Polity for Civil Services and Other State Examinations',
                    'subtitle' => '7th Revised & Enlarged Edition',
                    'isbn' => '978-9355325884',
                    'edition' => '7th Edition',
                    'category' => 'Indian Polity & Governance',
                    'author' => 'M. Laxmikanth',
                    'publisher' => 'McGraw Hill Education (India)',
                    'classification' => '320.954 LAX',
                    'price' => 850.00,
                    'year' => 2024,
                    'pages' => 980,
                    'copies_count' => 4,
                    'rack' => 'Stack A, Rack 01, Shelf 2',
                ],
                [
                    'title' => 'India\'s Struggle for Independence: 1857-1947',
                    'subtitle' => 'The National Movement in Perspective',
                    'isbn' => '978-0140107814',
                    'edition' => 'Updated Classic Edition',
                    'category' => 'Modern & Ancient Indian History',
                    'author' => 'Bipan Chandra',
                    'publisher' => 'Orient Blackswan',
                    'classification' => '954.03 CHA',
                    'price' => 520.00,
                    'year' => 2022,
                    'pages' => 600,
                    'copies_count' => 3,
                    'rack' => 'Stack A, Rack 02, Shelf 1',
                ],
                [
                    'title' => 'Indian Economy',
                    'subtitle' => 'For Civil Services, Universities and Other Examinations',
                    'isbn' => '978-9355324542',
                    'edition' => '15th Edition',
                    'category' => 'Indian Economy & Development',
                    'author' => 'Ramesh Singh',
                    'publisher' => 'McGraw Hill Education (India)',
                    'classification' => '330.954 SIN',
                    'price' => 780.00,
                    'year' => 2023,
                    'pages' => 750,
                    'copies_count' => 3,
                    'rack' => 'Stack B, Rack 01, Shelf 3',
                ],
                [
                    'title' => 'Geography of India',
                    'subtitle' => 'Physical, Human, Economic & Regional Dimensions',
                    'isbn' => '978-9355321121',
                    'edition' => '9th Edition',
                    'category' => 'Geography & Environment',
                    'author' => 'Majid Husain',
                    'publisher' => 'McGraw Hill Education (India)',
                    'classification' => '915.4 HUS',
                    'price' => 690.00,
                    'year' => 2023,
                    'pages' => 680,
                    'copies_count' => 3,
                    'rack' => 'Stack B, Rack 02, Shelf 2',
                ],
                [
                    'title' => 'Ethics, Integrity and Aptitude for Civil Services Examination',
                    'subtitle' => 'General Studies Manual Paper IV',
                    'isbn' => '978-9389887761',
                    'edition' => '6th Edition',
                    'category' => 'Ethics, Integrity & Aptitude',
                    'author' => 'G. Subba Rao & P.N. Roy Chowdhury',
                    'publisher' => 'Access Publishing',
                    'classification' => '170.2 SUB',
                    'price' => 590.00,
                    'year' => 2024,
                    'pages' => 520,
                    'copies_count' => 3,
                    'rack' => 'Stack C, Rack 01, Shelf 1',
                ],
            ];

            foreach ($booksData as $b) {
                $category = $categoryMap[$b['category']];
                $publisher = $publisherMap[$b['publisher']];
                $author = $authorMap[$b['author']];

                $book = Book::firstOrCreate(
                    ['title' => $b['title']],
                    [
                        'subtitle' => $b['subtitle'],
                        'slug' => Str::slug($b['title']).'-'.Str::random(4),
                        'isbn' => $b['isbn'],
                        'edition' => $b['edition'],
                        'category_id' => $category->id,
                        'publisher_id' => $publisher->id,
                        'classification_number' => $b['classification'],
                        'language' => 'English',
                        'pages' => $b['pages'],
                        'published_year' => $b['year'],
                        'description' => "Standard institutional text for {$category->name} with comprehensive analysis, previous years' UPSC papers, and curated study modules.",
                        'price' => $b['price'],
                        'is_reference_only' => false,
                        'status' => 'active',
                    ]
                );

                if (! $book->authors()->where('author_id', $author->id)->exists()) {
                    $book->authors()->attach($author->id, ['is_primary' => true]);
                }

                // Create copies if not already existing
                if ($book->copies()->count() < $b['copies_count']) {
                    $needed = $b['copies_count'] - $book->copies()->count();
                    for ($c = 1; $c <= $needed; $c++) {
                        $accNo = LibraryImportExportService::generateNextAccessionNo('ACC');
                        BookCopy::create([
                            'book_id' => $book->id,
                            'accession_no' => $accNo,
                            'barcode' => $accNo,
                            'copy_number' => $book->copies()->count() + 1,
                            'acquisition_date' => now()->subMonths(rand(1, 6))->toDateString(),
                            'purchase_cost' => $b['price'],
                            'supplier_details' => 'Oxford Book Depot, College Street, Kolkata',
                            'invoice_number' => 'INV-BK-2026-'.rand(100, 999),
                            'funding_source' => 'State Government Academic Grant',
                            'classification_number' => $b['classification'],
                            'shelf_location' => $b['rack'],
                            'book_condition' => 'new',
                            'status' => BookCopy::STATUS_AVAILABLE,
                            'notes' => 'Catalogued and accessioned into institutional central library.',
                        ]);
                    }
                }

                $book->refreshCopiesCount();
            }

            // 5. Auto-sync existing users (students) to Library Members
            $students = User::whereHas('roles', fn ($q) => $q->where('name', 'Student'))->take(10)->get();
            foreach ($students as $stu) {
                LibraryCardService::getOrCreateMemberForUser($stu);
            }
        });
    }
}
