<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Book;
use App\Models\BookAccessionHistory;
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
use App\Services\AuditLogService;
use App\Services\Library\BarcodeService;
use App\Services\Library\BookCirculationService;
use App\Services\Library\LibraryCardService;
use App\Services\Library\LibraryClearanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class LibraryApiController extends Controller
{
    /**
     * Resolve the authenticated user context (via API key binding, Sanctum, or session guard).
     */
    protected function resolveUser(Request $request): ?User
    {
        return $request->user()
            ?? Auth::guard('sanctum')->user()
            ?? ($request->attributes->get('api_key')?->user);
    }

    /**
     * Format a standard API success response.
     */
    protected function success(mixed $data, ?array $meta = null, int $status = 200): JsonResponse
    {
        $payload = [
            'success' => true,
            'data' => $data,
        ];

        if ($meta !== null) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    /**
     * Format a standard API error response.
     */
    protected function error(string $code, string $message, int $status = 400, ?array $details = null): JsonResponse
    {
        $payload = [
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ];

        if ($details !== null) {
            $payload['error']['details'] = $details;
        }

        return response()->json($payload, $status);
    }

    // ==========================================
    // 1. DASHBOARD & OVERVIEW
    // ==========================================

    /**
     * Executive Library KPIs, inventory counters, circulation status, and active holds.
     */
    public function dashboard(Request $request): JsonResponse
    {
        try {
            $totalBooks = Book::count();
            $totalCopies = BookCopy::count();
            $availableCopies = BookCopy::where('status', BookCopy::STATUS_AVAILABLE)->count();
            $issuedCopies = BookCopy::where('status', BookCopy::STATUS_ISSUED)->count();
            $inHouseCopies = BookCopy::where('status', BookCopy::STATUS_IN_HOUSE_READING)->count();
            $maintenanceCopies = BookCopy::where('status', BookCopy::STATUS_MAINTENANCE)->count();
            $lostCopies = BookCopy::whereIn('status', [BookCopy::STATUS_LOST, BookCopy::STATUS_WRITTEN_OFF])->count();

            $activeMembers = LibraryMember::where('status', LibraryMember::STATUS_ACTIVE)->count();
            $activeLoans = BookIssue::where('status', BookIssue::STATUS_ISSUED)->count();
            $overdueLoans = BookIssue::where('status', BookIssue::STATUS_ISSUED)
                ->where('due_date', '<', now()->toDateString())
                ->count();

            $pendingReservations = BookReservation::where('status', BookReservation::STATUS_PENDING)->count();
            $readyReservations = BookReservation::where('status', BookReservation::STATUS_READY)->count();

            $pendingFinesCount = BookFine::where('status', BookFine::STATUS_PENDING)->count();
            $pendingFinesAmount = (float) BookFine::where('status', BookFine::STATUS_PENDING)->sum('payable_amount');

            $todayIssues = BookIssue::whereDate('issue_date', now()->toDateString())->count();
            $todayReturns = BookIssue::whereDate('return_date', now()->toDateString())->count();
            $todayInHouse = BookIssue::where('issue_type', BookIssue::TYPE_IN_HOUSE)
                ->whereDate('issue_date', now()->toDateString())
                ->count();

            $recentCirculation = BookIssue::with(['book:id,title,cover_image', 'bookCopy:id,accession_no,barcode', 'libraryMember:id,name,member_number'])
                ->latest('id')
                ->take(6)
                ->get()
                ->map(fn ($item) => [
                    'id' => $item->id,
                    'issue_no' => $item->issue_no,
                    'book_title' => $item->book?->title,
                    'accession_no' => $item->bookCopy?->accession_no,
                    'member_name' => $item->libraryMember?->name,
                    'member_no' => $item->libraryMember?->member_number,
                    'issue_date' => $item->issue_date?->toDateString(),
                    'due_date' => $item->due_date?->toDateString(),
                    'status' => $item->status,
                    'issue_type' => $item->issue_type,
                ]);

            return $this->success([
                'metrics' => [
                    'total_titles' => $totalBooks,
                    'total_copies' => $totalCopies,
                    'available_copies' => $availableCopies,
                    'issued_copies' => $issuedCopies,
                    'active_members' => $activeMembers,
                    'overdue_count' => $overdueLoans,
                    'total_fines_unpaid' => $pendingFinesAmount,
                ],
                'inventory' => [
                    'total_titles' => $totalBooks,
                    'total_copies' => $totalCopies,
                    'available_copies' => $availableCopies,
                    'issued_copies' => $issuedCopies,
                    'in_house_reading_copies' => $inHouseCopies,
                    'under_maintenance_copies' => $maintenanceCopies,
                    'lost_or_weeded_copies' => $lostCopies,
                    'utilization_rate' => $totalCopies > 0 ? round((($totalCopies - $availableCopies) / $totalCopies) * 100, 1) : 0.0,
                ],
                'circulation' => [
                    'active_members' => $activeMembers,
                    'active_loans' => $activeLoans,
                    'overdue_loans' => $overdueLoans,
                    'today_issues' => $todayIssues,
                    'today_returns' => $todayReturns,
                    'today_in_house_reading' => $todayInHouse,
                ],
                'holds_and_fines' => [
                    'pending_reservations' => $pendingReservations,
                    'ready_for_pickup' => $readyReservations,
                    'pending_fines_count' => $pendingFinesCount,
                    'pending_fines_amount' => $pendingFinesAmount,
                ],
                'recent_circulation' => $recentCirculation,
                'recent_issues' => $recentCirculation,
                'active_reservations' => $pendingReservations,
                'popular_books' => Book::withCount('issues')->orderByDesc('issues_count')->take(5)->get(['id', 'title', 'isbn']),
            ]);
        } catch (Throwable $e) {
            return $this->error('DASHBOARD_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Analytical statistics, category breakdown, and reading telemetry.
     */
    public function statistics(Request $request): JsonResponse
    {
        try {
            $categoriesBreakdown = BookCategory::withCount('books')
                ->orderByDesc('books_count')
                ->take(10)
                ->get(['id', 'name', 'code', 'slug', 'books_count']);

            $topBorrowed = Book::withCount('issues')
                ->orderByDesc('issues_count')
                ->take(10)
                ->get(['id', 'title', 'isbn', 'material_type', 'available_copies_count', 'issues_count']);

            return $this->success([
                'categories' => $categoriesBreakdown,
                'top_borrowed_books' => $topBorrowed,
            ]);
        } catch (Throwable $e) {
            return $this->error('STATISTICS_ERROR', $e->getMessage(), 500);
        }
    }

    // ==========================================
    // 2. BOOK CATALOG MANAGEMENT
    // ==========================================

    /**
     * Search and paginate books catalog with filters.
     */
    public function indexBooks(Request $request): JsonResponse
    {
        try {
            $query = Book::with(['category', 'publisher', 'authors'])
                ->withCount([
                    'copies as total_copies',
                    'copies as available_copies' => fn ($q) => $q->where('status', BookCopy::STATUS_AVAILABLE),
                ]);

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('subtitle', 'like', "%{$search}%")
                        ->orWhere('isbn', 'like', "%{$search}%")
                        ->orWhere('isbn13', 'like', "%{$search}%")
                        ->orWhere('classification_number', 'like', "%{$search}%")
                        ->orWhereHas('authors', fn ($aq) => $aq->where('name', 'like', "%{$search}%"))
                        ->orWhereHas('publisher', fn ($pq) => $pq->where('name', 'like', "%{$search}%"));
                });
            }

            if ($categoryId = $request->input('category_id')) {
                $query->where('category_id', $categoryId);
            }

            if ($publisherId = $request->input('publisher_id')) {
                $query->where('publisher_id', $publisherId);
            }

            if ($authorId = $request->input('author_id')) {
                $query->whereHas('authors', fn ($q) => $q->where('book_authors.id', $authorId));
            }

            if ($materialType = $request->input('material_type')) {
                $query->where('material_type', $materialType);
            }

            if ($request->has('is_reference_only')) {
                $query->where('is_reference_only', $request->boolean('is_reference_only'));
            }

            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }

            if ($request->boolean('available_only')) {
                $query->whereHas('copies', fn ($q) => $q->where('status', BookCopy::STATUS_AVAILABLE));
            }

            $sort = $request->input('sort', 'latest');
            match ($sort) {
                'title' => $query->orderBy('title'),
                'oldest' => $query->oldest('id'),
                'popular' => $query->orderByDesc('total_copies'),
                default => $query->latest('id'),
            };

            $perPage = min((int) $request->input('per_page', 15), 100);
            $books = $query->paginate($perPage);

            return $this->success(
                $books->items(),
                [
                    'current_page' => $books->currentPage(),
                    'per_page' => $books->perPage(),
                    'total' => $books->total(),
                    'last_page' => $books->lastPage(),
                ]
            );
        } catch (Throwable $e) {
            return $this->error('BOOKS_QUERY_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Retrieve single book dossier by ID, slug, or ISBN.
     */
    public function showBook(Request $request, string $identifier): JsonResponse
    {
        try {
            $book = Book::with(['category', 'publisher', 'authors', 'copies'])
                ->where(function ($q) use ($identifier) {
                    if (is_numeric($identifier)) {
                        $q->where('id', $identifier);
                    }
                    $q->orWhere('slug', $identifier)
                        ->orWhere('isbn', $identifier)
                        ->orWhere('isbn13', $identifier);
                })
                ->first();

            if (! $book) {
                return $this->error('BOOK_NOT_FOUND', 'The requested book was not found in the catalog.', 404);
            }

            $copiesSummary = [
                'total' => $book->copies->count(),
                'available' => $book->copies->where('status', BookCopy::STATUS_AVAILABLE)->count(),
                'issued' => $book->copies->where('status', BookCopy::STATUS_ISSUED)->count(),
                'in_house_reading' => $book->copies->where('status', BookCopy::STATUS_IN_HOUSE_READING)->count(),
                'under_maintenance' => $book->copies->where('status', BookCopy::STATUS_MAINTENANCE)->count(),
            ];

            $bookData = $book->toArray();
            $bookData['book'] = $book;
            $bookData['copies_summary'] = $copiesSummary;

            return $this->success($bookData);
        } catch (Throwable $e) {
            return $this->error('BOOK_RETRIEVAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Create a new book title in catalog.
     */
    public function storeBook(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:book_categories,id',
            'publisher_id' => 'nullable|exists:book_publishers,id',
            'author_ids' => 'nullable|array',
            'author_ids.*' => 'exists:book_authors,id',
            'isbn' => 'nullable|string|max:30',
            'isbn13' => 'nullable|string|max:30',
            'edition' => 'nullable|string|max:50',
            'classification_number' => 'nullable|string|max:50',
            'language' => 'nullable|string|max:50',
            'pages' => 'nullable|integer|min:1',
            'published_year' => 'nullable|integer|min:1800|max:'.(date('Y') + 1),
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'is_reference_only' => 'nullable|boolean',
            'material_type' => 'nullable|string|in:book,journal,periodical,magazine,reference',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($request, $user) {
            $data = $request->except(['author_ids']);
            $data['slug'] = Str::slug($request->title).'-'.Str::lower(Str::random(6));
            $data['language'] = $data['language'] ?? 'English';
            $data['material_type'] = $data['material_type'] ?? Book::MATERIAL_BOOK;
            $data['price'] = $data['price'] ?? 0.00;
            $data['is_active'] = $data['is_active'] ?? true;
            $data['created_by'] = $user?->id;

            $book = Book::create($data);

            if ($request->has('author_ids')) {
                $book->authors()->sync($request->author_ids);
            }

            AuditLogService::log(
                event: 'library_book_created',
                description: "Created new book title: '{$book->title}' (ID: {$book->id})",
                auditable: $book,
                userId: $user?->id
            );

            return $this->success($book->load(['category', 'publisher', 'authors']), null, 201);
        });
    }

    /**
     * Update an existing book catalog title.
     */
    public function updateBook(Request $request, string $identifier): JsonResponse
    {
        $book = Book::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if (! $book) {
            return $this->error('BOOK_NOT_FOUND', 'Book not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'title' => 'sometimes|required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'category_id' => 'nullable|exists:book_categories,id',
            'publisher_id' => 'nullable|exists:book_publishers,id',
            'author_ids' => 'nullable|array',
            'author_ids.*' => 'exists:book_authors,id',
            'isbn' => 'nullable|string|max:30',
            'isbn13' => 'nullable|string|max:30',
            'edition' => 'nullable|string|max:50',
            'classification_number' => 'nullable|string|max:50',
            'language' => 'nullable|string|max:50',
            'pages' => 'nullable|integer|min:1',
            'published_year' => 'nullable|integer|min:1800|max:'.(date('Y') + 1),
            'description' => 'nullable|string',
            'price' => 'nullable|numeric|min:0',
            'is_reference_only' => 'nullable|boolean',
            'material_type' => 'nullable|string|in:book,journal,periodical,magazine,reference',
            'is_active' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($book, $request, $user) {
            $data = $request->except(['author_ids']);
            $data['updated_by'] = $user?->id;

            $book->update($data);

            if ($request->has('author_ids')) {
                $book->authors()->sync($request->author_ids);
            }

            AuditLogService::log(
                event: 'library_book_updated',
                description: "Updated book title: '{$book->title}' (ID: {$book->id})",
                auditable: $book,
                userId: $user?->id
            );

            return $this->success($book->load(['category', 'publisher', 'authors']));
        });
    }

    /**
     * Soft delete a book from catalog.
     */
    public function destroyBook(Request $request, string $identifier): JsonResponse
    {
        $book = Book::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if (! $book) {
            return $this->error('BOOK_NOT_FOUND', 'Book not found.', 404);
        }

        $hasActiveLoans = $book->copies()
            ->whereIn('status', [BookCopy::STATUS_ISSUED, BookCopy::STATUS_IN_HOUSE_READING])
            ->exists();

        if ($hasActiveLoans) {
            return $this->error(
                'BOOK_HAS_ACTIVE_LOANS',
                'Cannot delete this book title because one or more copies are currently issued to members or in reading rooms.',
                422
            );
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($book, $user) {
            $book->update(['deleted_by' => $user?->id]);
            $book->copies()->update(['deleted_by' => $user?->id]);
            $book->copies()->delete();
            $book->delete();

            AuditLogService::log(
                event: 'library_book_deleted',
                description: "Soft deleted book title: '{$book->title}' (ID: {$book->id}) and its copies.",
                auditable: $book,
                userId: $user?->id
            );

            return $this->success([
                'deleted' => true,
                'id' => $book->id,
                'title' => $book->title,
            ]);
        });
    }

    // ==========================================
    // 3. PHYSICAL COPIES & BARCODE/RFID DESK
    // ==========================================

    /**
     * List all physical accession copies of a book.
     */
    public function copies(Request $request, string $identifier): JsonResponse
    {
        try {
            $book = Book::where('id', $identifier)
                ->orWhere('slug', $identifier)
                ->first();

            if (! $book) {
                return $this->error('BOOK_NOT_FOUND', 'Book not found.', 404);
            }

            $copies = $book->copies()
                ->latest('id')
                ->get();

            return $this->success($copies);
        } catch (Throwable $e) {
            return $this->error('COPIES_QUERY_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Add a physical copy to a book title.
     */
    public function storeCopy(Request $request, string $identifier): JsonResponse
    {
        $book = Book::where('id', $identifier)
            ->orWhere('slug', $identifier)
            ->first();

        if (! $book) {
            return $this->error('BOOK_NOT_FOUND', 'Book not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'accession_no' => 'nullable|string|max:50|unique:book_copies,accession_no',
            'barcode' => 'nullable|string|max:50|unique:book_copies,barcode',
            'rfid_tag' => 'nullable|string|max:100',
            'shelf_location' => 'nullable|string|max:150',
            'condition' => 'nullable|string|in:new,good,fair,damaged,worn,lost',
            'purchase_cost' => 'nullable|numeric|min:0',
            'acquisition_date' => 'nullable|date',
            'supplier_details' => 'nullable|string|max:255',
            'invoice_number' => 'nullable|string|max:100',
            'funding_source' => 'nullable|string|max:100',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($book, $request, $user) {
            $existingCount = $book->copies()->withTrashed()->count();
            $copyNumber = $existingCount + 1;

            $accessionNo = $request->accession_no;
            if (! $accessionNo) {
                $accessionNo = sprintf('ACC-%s-%05d', date('Y'), $book->id * 100 + $copyNumber);
            }

            $barcode = $request->barcode ?: $accessionNo;
            $condition = $request->condition ?? BookCopy::CONDITION_NEW;

            $copy = BookCopy::create([
                'book_id' => $book->id,
                'accession_no' => $accessionNo,
                'barcode' => $barcode,
                'rfid_tag' => $request->rfid_tag,
                'copy_number' => $copyNumber,
                'acquisition_date' => $request->acquisition_date ?? now()->toDateString(),
                'purchase_cost' => $request->purchase_cost ?? $book->price,
                'price' => $request->purchase_cost ?? $book->price,
                'supplier_details' => $request->supplier_details,
                'invoice_number' => $request->invoice_number,
                'funding_source' => $request->funding_source,
                'shelf_location' => $request->shelf_location,
                'book_condition' => $condition,
                'condition' => $condition,
                'status' => BookCopy::STATUS_AVAILABLE,
                'notes' => $request->notes,
                'created_by' => $user?->id,
            ]);

            // Accession History Record
            BookAccessionHistory::create([
                'book_copy_id' => $copy->id,
                'action' => 'accessioned',
                'old_condition' => null,
                'new_condition' => $condition,
                'old_status' => null,
                'new_status' => BookCopy::STATUS_AVAILABLE,
                'old_shelf_location' => null,
                'new_shelf_location' => $copy->shelf_location,
                'notes' => 'Initial copy accessioning into library database',
                'created_by' => $user?->id,
            ]);

            $book->updateCounters();

            AuditLogService::log(
                event: 'library_copy_accessioned',
                description: "Accessioned physical copy {$copy->accession_no} for book '{$book->title}'",
                auditable: $copy,
                userId: $user?->id
            );

            return $this->success($copy, null, 201);
        });
    }

    /**
     * Single physical copy lookup by accession_no, barcode, rfid_tag, or ID.
     * High performance endpoint designed for desktop barcode scanners and IoT gates.
     */
    public function showCopy(Request $request, string $identifier): JsonResponse
    {
        try {
            $copy = BookCopy::with(['book.authors', 'book.category', 'book.publisher'])
                ->where(function ($q) use ($identifier) {
                    if (is_numeric($identifier)) {
                        $q->where('id', $identifier);
                    }
                    $q->orWhere('accession_no', $identifier)
                        ->orWhere('barcode', $identifier)
                        ->orWhere('rfid_tag', $identifier);
                })
                ->first();

            if (! $copy) {
                return $this->error('COPY_NOT_FOUND', "Physical copy with identifier '{$identifier}' not found.", 404);
            }

            // If issued, attach current borrower info
            $currentLoan = null;
            if (in_array($copy->status, [BookCopy::STATUS_ISSUED, BookCopy::STATUS_IN_HOUSE_READING])) {
                $activeIssue = BookIssue::with(['libraryMember', 'user'])
                    ->where('book_copy_id', $copy->id)
                    ->where('status', BookIssue::STATUS_ISSUED)
                    ->latest('id')
                    ->first();

                if ($activeIssue) {
                    $currentLoan = [
                        'issue_id' => $activeIssue->id,
                        'issue_no' => $activeIssue->issue_no,
                        'issue_type' => $activeIssue->issue_type,
                        'reader_name' => $activeIssue->reader_name ?: $activeIssue->libraryMember?->name,
                        'reader_identifier' => $activeIssue->reader_identifier ?: $activeIssue->libraryMember?->member_number,
                        'due_date' => $activeIssue->due_date?->toDateString(),
                        'due_time' => $activeIssue->due_time,
                        'is_overdue' => $activeIssue->isOverdue(),
                    ];
                }
            }

            $copyData = $copy->toArray();
            $copyData['copy'] = $copy;
            $copyData['barcode_svg_uri'] = BarcodeService::generateBarcodeDataUri($copy->barcode, 36, 2, false);
            $copyData['current_loan'] = $currentLoan;

            return $this->success($copyData);
        } catch (Throwable $e) {
            return $this->error('COPY_RETRIEVAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Quick status & condition update for physical copy (e.g. marked damaged, under repair).
     */
    public function updateCopyStatus(Request $request, string $identifier): JsonResponse
    {
        $copy = BookCopy::where('id', $identifier)
            ->orWhere('accession_no', $identifier)
            ->orWhere('barcode', $identifier)
            ->first();

        if (! $copy) {
            return $this->error('COPY_NOT_FOUND', 'Physical copy not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'status' => 'required|string|in:available,issued,in_house_reading,reserved,under_maintenance,lost,written_off',
            'condition' => 'nullable|string|in:new,good,fair,damaged,worn,lost',
            'shelf_location' => 'nullable|string|max:150',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($copy, $request, $user) {
            $oldStatus = $copy->status;
            $oldCondition = $copy->book_condition;
            $oldShelf = $copy->shelf_location;

            $newStatus = $request->status;
            $newCondition = $request->condition ?? $oldCondition;
            $newShelf = $request->shelf_location ?? $oldShelf;

            $copy->update([
                'status' => $newStatus,
                'book_condition' => $newCondition,
                'condition' => $newCondition,
                'shelf_location' => $newShelf,
                'notes' => $request->notes ?: $copy->notes,
                'updated_by' => $user?->id,
            ]);

            BookAccessionHistory::create([
                'book_copy_id' => $copy->id,
                'action' => 'status_changed',
                'old_condition' => $oldCondition,
                'new_condition' => $newCondition,
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'old_shelf_location' => $oldShelf,
                'new_shelf_location' => $newShelf,
                'notes' => $request->notes ?: 'Copy status/condition update via API',
                'created_by' => $user?->id,
            ]);

            $copy->book->updateCounters();

            AuditLogService::log(
                event: 'library_copy_status_updated',
                description: "Updated copy {$copy->accession_no} status: {$oldStatus} -> {$newStatus}",
                auditable: $copy,
                userId: $user?->id
            );

            return $this->success($copy);
        });
    }

    /**
     * Soft delete physical copy.
     */
    public function destroyCopy(Request $request, string $identifier): JsonResponse
    {
        $copy = BookCopy::where('id', $identifier)
            ->orWhere('accession_no', $identifier)
            ->orWhere('barcode', $identifier)
            ->first();

        if (! $copy) {
            return $this->error('COPY_NOT_FOUND', 'Physical copy not found.', 404);
        }

        if (in_array($copy->status, [BookCopy::STATUS_ISSUED, BookCopy::STATUS_IN_HOUSE_READING])) {
            return $this->error('COPY_CURRENTLY_ISSUED', 'Cannot delete a physical copy that is currently issued.', 422);
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($copy, $user) {
            $copy->update(['deleted_by' => $user?->id]);
            $copy->delete();
            $copy->book->updateCounters();

            AuditLogService::log(
                event: 'library_copy_deleted',
                description: "Soft deleted copy {$copy->accession_no} (Book: {$copy->book->title})",
                auditable: $copy,
                userId: $user?->id
            );

            return $this->success(['deleted' => true, 'accession_no' => $copy->accession_no]);
        });
    }

    // ==========================================
    // 4. CIRCULATION OPERATIONS (LOANS & RETURNS)
    // ==========================================

    /**
     * List circulation loans with filters.
     */
    public function indexIssues(Request $request): JsonResponse
    {
        try {
            $query = BookIssue::with(['book:id,title,isbn,price', 'bookCopy:id,accession_no,barcode,shelf_location', 'libraryMember:id,name,member_number,student_id,phone']);

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('issue_no', 'like', "%{$search}%")
                        ->orWhere('reader_name', 'like', "%{$search}%")
                        ->orWhere('reader_phone', 'like', "%{$search}%")
                        ->orWhere('reader_identifier', 'like', "%{$search}%")
                        ->orWhereHas('book', fn ($bq) => $bq->where('title', 'like', "%{$search}%"))
                        ->orWhereHas('bookCopy', fn ($cq) => $cq->where('accession_no', 'like', "%{$search}%"))
                        ->orWhereHas('libraryMember', fn ($mq) => $mq->where('name', 'like', "%{$search}%")->orWhere('member_number', 'like', "%{$search}%"));
                });
            }

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            if ($issueType = $request->input('issue_type')) {
                $query->where('issue_type', $issueType);
            }

            if ($memberId = $request->input('library_member_id')) {
                $query->where('library_member_id', $memberId);
            }

            if ($request->boolean('overdue_only')) {
                $query->where('status', BookIssue::STATUS_ISSUED)
                    ->where('due_date', '<', now()->toDateString());
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $issues = $query->latest('id')->paginate($perPage);

            return $this->success(
                $issues->items(),
                [
                    'current_page' => $issues->currentPage(),
                    'per_page' => $issues->perPage(),
                    'total' => $issues->total(),
                    'last_page' => $issues->lastPage(),
                ]
            );
        } catch (Throwable $e) {
            return $this->error('ISSUES_QUERY_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Checkout / Issue a book copy to a member.
     */
    public function issueBook(Request $request): JsonResponse
    {
        $copyId = $request->input('copy_identifier') ?? $request->input('barcode') ?? $request->input('copy_id') ?? $request->input('accession_no');
        $memberId = $request->input('member_identifier') ?? $request->input('library_member_id') ?? $request->input('member_id') ?? $request->input('member_number') ?? $request->input('student_id');
        $loanDays = $request->input('loan_days') ?? $request->input('days');

        $request->merge([
            'copy_identifier' => $copyId !== null ? (string) $copyId : null,
            'member_identifier' => $memberId !== null ? (string) $memberId : null,
            'loan_days' => $loanDays,
        ]);

        $validator = Validator::make($request->all(), [
            'copy_identifier' => 'required|string', // copy_id, accession_no, or barcode
            'member_identifier' => 'required|string', // member_id, member_number, student_id, or email
            'loan_days' => 'nullable|integer|min:1|max:90',
            'bypass_deposit' => 'nullable|boolean',
            'bypass_reason' => 'nullable|string|max:255',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            // Resolve Copy
            $copyIdentifier = $request->copy_identifier;
            $copy = BookCopy::with('book')
                ->where(function ($q) use ($copyIdentifier) {
                    if (is_numeric($copyIdentifier)) {
                        $q->where('id', $copyIdentifier);
                    }
                    $q->orWhere('accession_no', $copyIdentifier)
                        ->orWhere('barcode', $copyIdentifier);
                })
                ->first();

            if (! $copy) {
                return $this->error('COPY_NOT_FOUND', "Physical copy '{$copyIdentifier}' not found.", 404);
            }

            if ($copy->status !== BookCopy::STATUS_AVAILABLE) {
                return $this->error('COPY_NOT_AVAILABLE', "Copy '{$copy->accession_no}' is not available for checkout (Status: {$copy->status}).", 422);
            }

            // Resolve Member
            $memberIdentifier = $request->member_identifier;
            $member = LibraryMember::where(function ($q) use ($memberIdentifier) {
                if (is_numeric($memberIdentifier)) {
                    $q->where('id', $memberIdentifier);
                }
                $q->orWhere('member_number', $memberIdentifier)
                    ->orWhere('student_id', $memberIdentifier)
                    ->orWhere('email', $memberIdentifier);
            })->first();

            if (! $member) {
                return $this->error('MEMBER_NOT_FOUND', "Library member '{$memberIdentifier}' not found.", 404);
            }

            if ($member->status !== LibraryMember::STATUS_ACTIVE) {
                return $this->error('MEMBER_NOT_ACTIVE', "Member '{$member->member_number}' account is {$member->status}.", 422);
            }

            $issue = BookCirculationService::issueBook(
                copy: $copy,
                member: $member,
                loanDays: $request->loan_days,
                bypassDeposit: $request->boolean('bypass_deposit'),
                bypassReason: $request->bypass_reason,
                notes: $request->notes,
                issuedBy: $user
            );

            AuditLogService::log(
                event: 'library_book_issued',
                description: "Issued book copy {$copy->accession_no} to {$member->name} ({$member->member_number}) under {$issue->issue_no}",
                auditable: $issue,
                userId: $user?->id
            );

            return $this->success($issue->load(['book', 'bookCopy', 'libraryMember']), null, 201);
        } catch (ValidationException $ve) {
            return $this->error('CIRCULATION_VALIDATION_ERROR', $ve->getMessage(), 422, $ve->errors());
        } catch (Throwable $e) {
            return $this->error('ISSUE_OPERATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Return an issued book, calculate overdue fine, and update copy condition.
     */
    public function returnBook(Request $request, int $id): JsonResponse
    {
        $issue = BookIssue::find($id);
        if (! $issue) {
            return $this->error('ISSUE_NOT_FOUND', 'Circulation issue record not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'return_condition' => 'nullable|string|in:new,good,fair,damaged,worn,lost',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $result = BookCirculationService::returnBook(
                issue: $issue,
                returnCondition: $request->return_condition ?? 'good',
                notes: $request->notes,
                returnedBy: $user
            );

            AuditLogService::log(
                event: 'library_book_returned',
                description: "Returned book loan {$issue->issue_no} (Copy: {$issue->bookCopy?->accession_no})",
                auditable: $issue,
                userId: $user?->id
            );

            $returnData = $result['issue']->toArray();
            $returnData['issue'] = $result['issue'];
            $returnData['fine'] = $result['fine'];
            $returnData['overdue_days'] = $result['overdue_days'];

            return $this->success($returnData);
        } catch (\InvalidArgumentException $iae) {
            return $this->error('ALREADY_RETURNED', $iae->getMessage(), 422);
        } catch (Throwable $e) {
            return $this->error('RETURN_OPERATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Renew an active book loan.
     */
    public function renewBook(Request $request, int $id): JsonResponse
    {
        $issue = BookIssue::find($id);
        if (! $issue) {
            return $this->error('ISSUE_NOT_FOUND', 'Circulation issue record not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'additional_days' => 'nullable|integer|min:1|max:60',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $renewed = BookCirculationService::renewBook(
                issue: $issue,
                additionalDays: $request->additional_days,
                renewedBy: $user
            );

            AuditLogService::log(
                event: 'library_book_renewed',
                description: "Renewed book loan {$issue->issue_no} until {$renewed->due_date->toDateString()}",
                auditable: $issue,
                userId: $user?->id
            );

            return $this->success($renewed);
        } catch (ValidationException $ve) {
            return $this->error('RENEWAL_LIMIT_REACHED', $ve->getMessage(), 422, $ve->errors());
        } catch (Throwable $e) {
            return $this->error('RENEW_OPERATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Submit loan renewal request.
     */
    public function requestLoanRenewal(Request $request, int $id): JsonResponse
    {
        $issue = BookIssue::find($id);
        if (! $issue) {
            return $this->error('ISSUE_NOT_FOUND', 'Circulation issue record not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'nullable|string|max:255',
            'requested_days' => 'nullable|integer|min:1|max:30',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $updated = BookCirculationService::requestLoanRenewal(
                issue: $issue,
                reason: $request->reason,
                requestedDays: $request->requested_days,
                requestedBy: $user
            );

            return $this->success($updated);
        } catch (ValidationException $ve) {
            return $this->error('RENEWAL_REQUEST_REJECTED', $ve->getMessage(), 422, $ve->errors());
        } catch (Throwable $e) {
            return $this->error('REQUEST_RENEWAL_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Review (approve or reject) a pending loan renewal request.
     */
    public function reviewLoanRenewal(Request $request, int $id): JsonResponse
    {
        $issue = BookIssue::find($id);
        if (! $issue) {
            return $this->error('ISSUE_NOT_FOUND', 'Circulation issue record not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'action' => 'required|string|in:approve,reject',
            'additional_days' => 'nullable|integer|min:1|max:60',
            'reason' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            if ($request->action === 'approve') {
                $reviewed = BookCirculationService::approveLoanRenewal(
                    issue: $issue,
                    additionalDays: $request->additional_days,
                    notes: $request->reason,
                    reviewedBy: $user
                );
            } else {
                $reviewed = BookCirculationService::rejectLoanRenewal(
                    issue: $issue,
                    rejectionReason: $request->reason ?: 'Renewal request rejected by librarian.',
                    reviewedBy: $user
                );
            }

            AuditLogService::log(
                event: 'library_renewal_reviewed',
                description: "Librarian {$request->action}d renewal for loan {$issue->issue_no}",
                auditable: $issue,
                userId: $user?->id
            );

            return $this->success($reviewed);
        } catch (Throwable $e) {
            return $this->error('REVIEW_RENEWAL_FAILED', $e->getMessage(), 500);
        }
    }

    // ==========================================
    // 5. IN-HOUSE READING ROOM FACILITY
    // ==========================================

    /**
     * Issue book(s) or journal(s) for in-house reading room study session.
     */
    public function issueInHouse(Request $request): JsonResponse
    {
        $copyIdentifiers = $request->input('copy_identifiers');
        if (! is_array($copyIdentifiers)) {
            $single = $request->input('barcode') ?? $request->input('copy_identifier') ?? $request->input('accession_no') ?? $request->input('copy_id');
            if ($single !== null) {
                $copyIdentifiers = [(string) $single];
            }
        }
        $memberId = $request->input('member_identifier') ?? $request->input('library_member_id') ?? $request->input('member_id') ?? $request->input('member_number');

        $request->merge([
            'copy_identifiers' => $copyIdentifiers,
            'member_identifier' => $memberId !== null ? (string) $memberId : null,
        ]);

        $validator = Validator::make($request->all(), [
            'copy_identifiers' => 'required|array|min:1',
            'copy_identifiers.*' => 'string',
            'member_identifier' => 'nullable|string',
            'reader_name' => 'nullable|string|max:150',
            'reader_phone' => 'nullable|string|max:50',
            'reader_identifier' => 'nullable|string|max:50',
            'seat_no' => 'nullable|string|max:50',
            'due_time' => 'nullable|string|max:10',
            'notes' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            // Resolve Copies
            $copyModels = [];
            foreach ($request->copy_identifiers as $cid) {
                $c = BookCopy::where('id', $cid)
                    ->orWhere('accession_no', $cid)
                    ->orWhere('barcode', $cid)
                    ->first();

                if (! $c) {
                    return $this->error('COPY_NOT_FOUND', "Physical copy '{$cid}' not found.", 404);
                }
                $copyModels[] = $c;
            }

            // Resolve Member if provided
            $member = null;
            if ($mId = $request->member_identifier) {
                $member = LibraryMember::where('id', $mId)
                    ->orWhere('member_number', $mId)
                    ->orWhere('student_id', $mId)
                    ->first();
            }

            $readerDetails = [
                'name' => $request->reader_name,
                'phone' => $request->reader_phone,
                'identifier' => $request->reader_identifier,
                'seat_no' => $request->seat_no,
            ];

            $issues = BookCirculationService::issueInHouseReading(
                copies: $copyModels,
                member: $member,
                readerDetails: $readerDetails,
                dueTime: $request->due_time,
                notes: $request->notes,
                issuedBy: $user
            );

            $firstIssue = $issues->first();
            $issuesLoaded = BookIssue::with(['book:id,title', 'bookCopy:id,accession_no,barcode'])
                ->whereIn('id', $issues->pluck('id'))
                ->get();

            $issueData = $firstIssue ? $firstIssue->toArray() : [];
            $issueData['session_token'] = $firstIssue?->inhouse_session_token;
            $issueData['issued_count'] = $issues->count();
            $issueData['issues'] = $issuesLoaded;
            $issueData['issue'] = $firstIssue;
            $issueData['is_in_house'] = true;

            return $this->success($issueData, null, 201);
        } catch (ValidationException $ve) {
            return $this->error('IN_HOUSE_VALIDATION_ERROR', $ve->getMessage(), 422, $ve->errors());
        } catch (Throwable $e) {
            return $this->error('IN_HOUSE_ISSUE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Return an in-house reading copy or entire session.
     */
    public function returnInHouse(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'issue_id' => 'nullable|integer',
            'session_token' => 'nullable|string',
            'return_condition' => 'nullable|string|in:new,good,fair,damaged,worn,lost',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            if ($issueId = $request->issue_id) {
                $issue = BookIssue::find($issueId);
                if (! $issue) {
                    return $this->error('ISSUE_NOT_FOUND', 'In-house issue record not found.', 404);
                }

                $returned = BookCirculationService::returnInHouseIssue(
                    issue: $issue,
                    returnCondition: $request->return_condition ?? 'good',
                    notes: $request->notes,
                    returnedBy: $user
                );

                $returnData = $returned->toArray();
                $returnData['returned_count'] = 1;
                $returnData['issue'] = $returned;

                return $this->success($returnData);
            }

            if ($token = $request->session_token) {
                $count = BookCirculationService::returnInHouseSession(
                    sessionToken: $token,
                    returnCondition: $request->return_condition ?? 'good',
                    notes: $request->notes,
                    returnedBy: $user
                );

                return $this->success(['returned_count' => $count, 'session_token' => $token]);
            }

            return $this->error('MISSING_IDENTIFIER', 'Please supply either issue_id or session_token.', 422);
        } catch (Throwable $e) {
            return $this->error('IN_HOUSE_RETURN_FAILED', $e->getMessage(), 500);
        }
    }

    // ==========================================
    // 6. BOOK RESERVATIONS & HOLDS
    // ==========================================

    /**
     * List book reservations.
     */
    public function indexReservations(Request $request): JsonResponse
    {
        try {
            $query = BookReservation::with(['book:id,title,isbn', 'bookCopy:id,accession_no,shelf_location', 'libraryMember:id,name,member_number,student_id']);

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            if ($bookId = $request->input('book_id')) {
                $query->where('book_id', $bookId);
            }

            if ($memberId = $request->input('library_member_id')) {
                $query->where('library_member_id', $memberId);
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $reservations = $query->latest('id')->paginate($perPage);

            return $this->success(
                $reservations->items(),
                [
                    'current_page' => $reservations->currentPage(),
                    'per_page' => $reservations->perPage(),
                    'total' => $reservations->total(),
                    'last_page' => $reservations->lastPage(),
                ]
            );
        } catch (Throwable $e) {
            return $this->error('RESERVATIONS_QUERY_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Place hold / reservation on a book title.
     */
    public function storeReservation(Request $request): JsonResponse
    {
        if (! $request->has('member_id') && $request->has('library_member_id')) {
            $request->merge(['member_id' => $request->input('library_member_id')]);
        }

        $validator = Validator::make($request->all(), [
            'book_id' => 'required|exists:books,id',
            'member_id' => 'required|exists:library_members,id',
            'notes' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $book = Book::findOrFail($request->book_id);
            $member = LibraryMember::findOrFail($request->member_id);

            $reservation = BookCirculationService::reserveBook(
                book: $book,
                member: $member,
                notes: $request->notes
            );

            AuditLogService::log(
                event: 'library_book_reserved',
                description: "Reserved book '{$book->title}' for member {$member->name} under {$reservation->reservation_no}",
                auditable: $reservation,
                userId: $user?->id
            );

            return $this->success($reservation->load(['book', 'libraryMember']), null, 201);
        } catch (ValidationException $ve) {
            return $this->error('RESERVATION_VALIDATION_ERROR', $ve->getMessage(), 422, $ve->errors());
        } catch (Throwable $e) {
            return $this->error('RESERVATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Cancel an active reservation.
     */
    public function cancelReservation(Request $request, int $id): JsonResponse
    {
        $reservation = BookReservation::find($id);
        if (! $reservation) {
            return $this->error('RESERVATION_NOT_FOUND', 'Reservation record not found.', 404);
        }

        $validator = Validator::make($request->all(), [
            'reason' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $cancelled = BookCirculationService::cancelReservation(
                reservation: $reservation,
                reason: $request->reason,
                cancelledBy: $user
            );

            AuditLogService::log(
                event: 'library_reservation_cancelled',
                description: "Cancelled reservation {$reservation->reservation_no} (Reason: {$request->reason})",
                auditable: $reservation,
                userId: $user?->id
            );

            return $this->success($cancelled);
        } catch (Throwable $e) {
            return $this->error('CANCEL_RESERVATION_FAILED', $e->getMessage(), 500);
        }
    }

    // ==========================================
    // 7. OVERDUE FINES & WAIVERS
    // ==========================================

    /**
     * List overdue fines with filters.
     */
    public function indexFines(Request $request): JsonResponse
    {
        try {
            $query = BookFine::with(['bookIssue.book:id,title', 'libraryMember:id,name,member_number,student_id']);

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            if ($memberId = $request->input('library_member_id')) {
                $query->where('library_member_id', $memberId);
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $fines = $query->latest('id')->paginate($perPage);

            return $this->success(
                $fines->items(),
                [
                    'current_page' => $fines->currentPage(),
                    'per_page' => $fines->perPage(),
                    'total' => $fines->total(),
                    'last_page' => $fines->lastPage(),
                ]
            );
        } catch (Throwable $e) {
            return $this->error('FINES_QUERY_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Record payment for an overdue fine.
     */
    public function payFine(Request $request, int $id): JsonResponse
    {
        $fine = BookFine::find($id);
        if (! $fine) {
            return $this->error('FINE_NOT_FOUND', 'Fine record not found.', 404);
        }

        if ($fine->status === BookFine::STATUS_PAID) {
            return $this->error('FINE_ALREADY_PAID', 'This fine has already been fully paid.', 422);
        }

        $validator = Validator::make($request->all(), [
            'amount' => 'required|numeric|min:0.01',
            'payment_method' => 'required|string|in:cash,upi,card,online,bank_transfer',
            'payment_reference' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($fine, $request, $user) {
            $paidNow = (float) $request->amount;
            $newPaid = $fine->paid_amount + $paidNow;
            $status = $newPaid >= $fine->payable_amount ? BookFine::STATUS_PAID : BookFine::STATUS_PARTIAL;

            $fine->update([
                'paid_amount' => $newPaid,
                'status' => $status,
                'paid_at' => now(),
                'payment_method' => $request->payment_method,
                'payment_reference' => $request->payment_reference,
                'collected_by_id' => $user?->id,
                'notes' => $request->notes ? trim(($fine->notes ? $fine->notes."\n" : '')."Payment: {$request->notes}") : $fine->notes,
                'updated_by' => $user?->id,
            ]);

            AuditLogService::log(
                event: 'library_fine_paid',
                description: "Recorded fine payment of ₹{$paidNow} for fine {$fine->fine_no} (Method: {$request->payment_method})",
                auditable: $fine,
                userId: $user?->id
            );

            return $this->success($fine);
        });
    }

    /**
     * Authorize fine waiver (librarian or admin).
     */
    public function waiveFine(Request $request, int $id): JsonResponse
    {
        $fine = BookFine::find($id);
        if (! $fine) {
            return $this->error('FINE_NOT_FOUND', 'Fine record not found.', 404);
        }

        if (! $request->has('waive_reason') && $request->has('waiver_reason')) {
            $request->merge(['waive_reason' => $request->input('waiver_reason')]);
        }

        $validator = Validator::make($request->all(), [
            'waive_reason' => 'required|string|max:255',
            'waived_amount' => 'nullable|numeric|min:0.01',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        return DB::transaction(function () use ($fine, $request, $user) {
            $amountToWaive = $request->waived_amount ? (float) $request->waived_amount : ($fine->payable_amount - $fine->paid_amount);

            $fine->update([
                'waived_amount' => $fine->waived_amount + $amountToWaive,
                'payable_amount' => max(0.00, $fine->payable_amount - $amountToWaive),
                'status' => BookFine::STATUS_WAIVED,
                'waived_by' => $user?->id,
                'waived_by_id' => $user?->id,
                'waived_at' => now(),
                'waiver_reason' => $request->waive_reason,
                'updated_by' => $user?->id,
            ]);

            AuditLogService::log(
                event: 'library_fine_waived',
                description: "Waived fine of ₹{$amountToWaive} for fine {$fine->fine_no} (Reason: {$request->waive_reason})",
                auditable: $fine,
                userId: $user?->id
            );

            return $this->success($fine);
        });
    }

    // ==========================================
    // 8. MEMBERS, CLEARANCES & ID CARDS
    // ==========================================

    /**
     * List registered library members.
     */
    public function indexMembers(Request $request): JsonResponse
    {
        try {
            $query = LibraryMember::with('user:id,name,email,phone');

            if ($search = $request->input('search')) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('member_number', 'like', "%{$search}%")
                        ->orWhere('student_id', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                });
            }

            if ($memberType = $request->input('member_type')) {
                $query->where('member_type', $memberType);
            }

            if ($status = $request->input('status')) {
                $query->where('status', $status);
            }

            $perPage = min((int) $request->input('per_page', 20), 100);
            $members = $query->latest('id')->paginate($perPage);

            return $this->success(
                $members->items(),
                [
                    'current_page' => $members->currentPage(),
                    'per_page' => $members->perPage(),
                    'total' => $members->total(),
                    'last_page' => $members->lastPage(),
                ]
            );
        } catch (Throwable $e) {
            return $this->error('MEMBERS_QUERY_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Single member profile dossier with active loans, reservations, fines, and clearance eligibility.
     */
    public function showMember(Request $request, string $identifier): JsonResponse
    {
        try {
            $member = LibraryMember::with(['user', 'activeIssues.book', 'activeIssues.bookCopy'])
                ->where(function ($q) use ($identifier) {
                    if (is_numeric($identifier)) {
                        $q->where('id', $identifier);
                    }
                    $q->orWhere('member_number', $identifier)
                        ->orWhere('student_id', $identifier)
                        ->orWhere('email', $identifier);
                })
                ->first();

            if (! $member) {
                return $this->error('MEMBER_NOT_FOUND', 'Library member not found.', 404);
            }

            $activeDeposit = $member->getActiveSecurityDeposit();
            $clearanceEligibility = $member->user_id ? LibraryClearanceService::checkEligibility($member->user_id) : null;
            $unpaidFines = (float) BookFine::where('library_member_id', $member->id)
                ->where('status', BookFine::STATUS_PENDING)
                ->sum('payable_amount');

            return $this->success([
                'member' => $member,
                'active_deposit' => $activeDeposit,
                'unpaid_fines_amount' => $unpaidFines,
                'clearance_eligibility' => $clearanceEligibility,
            ]);
        } catch (Throwable $e) {
            return $this->error('MEMBER_RETRIEVAL_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Check if member is eligible for clearance certificate.
     */
    public function checkClearance(Request $request, string $identifier): JsonResponse
    {
        try {
            $member = LibraryMember::where('id', $identifier)
                ->orWhere('member_number', $identifier)
                ->orWhere('student_id', $identifier)
                ->first();

            if (! $member) {
                return $this->error('MEMBER_NOT_FOUND', 'Library member not found.', 404);
            }

            if (! $member->user_id) {
                return $this->error('MEMBER_HAS_NO_USER_ACCOUNT', 'This library member is not associated with a system user account.', 422);
            }

            $eligibility = LibraryClearanceService::checkEligibility($member->user_id);
            $eligibility['unpaid_fines_amount'] = $eligibility['unpaid_fines'];

            return $this->success($eligibility);
        } catch (Throwable $e) {
            return $this->error('CLEARANCE_CHECK_ERROR', $e->getMessage(), 500);
        }
    }

    /**
     * Issue official Library Clearance (No Dues) Certificate for security deposit refund.
     */
    public function issueClearance(Request $request): JsonResponse
    {
        if (! $request->has('deposit_id')) {
            $mId = $request->input('library_member_id') ?? $request->input('member_id');
            if ($mId) {
                $member = LibraryMember::where('id', $mId)
                    ->orWhere('member_number', $mId)
                    ->orWhere('student_id', $mId)
                    ->first();
                if ($member && $member->user_id) {
                    $dep = LibraryDeposit::where('user_id', $member->user_id)
                        ->where('status', LibraryDeposit::STATUS_ACTIVE)
                        ->first();
                    if ($dep) {
                        $request->merge(['deposit_id' => $dep->id]);
                    }
                }
            }
        }

        $validator = Validator::make($request->all(), [
            'deposit_id' => 'required|exists:library_deposits,id',
            'remarks' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        try {
            $deposit = LibraryDeposit::findOrFail($request->deposit_id);

            $clearedDeposit = LibraryClearanceService::issueClearanceCertificate(
                deposit: $deposit,
                clearedBy: $user?->id ?? 1,
                remarks: $request->remarks
            );

            return $this->success([
                'clearance_issued' => true,
                'certificate_number' => $clearedDeposit->clearance_certificate_no,
                'certificate_no' => $clearedDeposit->clearance_certificate_no,
                'issue_date' => $clearedDeposit->clearance_issued_at?->toDateString() ?? now()->toDateString(),
                'deposit' => $clearedDeposit,
                'member' => $clearedDeposit->user?->libraryMember,
            ]);
        } catch (ValidationException $ve) {
            return $this->error('CLEARANCE_ELIGIBILITY_FAILED', $ve->getMessage(), 422, $ve->errors());
        } catch (Throwable $e) {
            return $this->error('CLEARANCE_ISSUE_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Download or stream official Library Card PDF.
     */
    public function downloadCard(Request $request, string $identifier): Response
    {
        $member = LibraryMember::where('id', $identifier)
            ->orWhere('member_number', $identifier)
            ->orWhere('student_id', $identifier)
            ->first();

        if (! $member) {
            abort(404, 'Library member not found.');
        }

        $withDeposit = filter_var($request->query('with_deposit', true), FILTER_VALIDATE_BOOLEAN);
        $withBooks = filter_var($request->query('with_books', true), FILTER_VALIDATE_BOOLEAN);

        return LibraryCardService::downloadCardPdf($member, $withDeposit, $withBooks);
    }

    // ==========================================
    // 9. TAXONOMY (CATEGORIES, AUTHORS, PUBLISHERS)
    // ==========================================

    public function categories(): JsonResponse
    {
        $categories = BookCategory::withCount('books')->orderBy('name')->get();

        return $this->success($categories);
    }

    public function storeCategory(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150|unique:book_categories,name',
            'code' => 'nullable|string|max:50',
            'ddc_classification' => 'nullable|string|max:50',
            'description' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        $category = BookCategory::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'code' => $request->code ?: Str::upper(Str::substr($request->name, 0, 4)),
            'ddc_classification' => $request->ddc_classification,
            'description' => $request->description,
            'is_active' => true,
            'created_by' => $user?->id,
        ]);

        return $this->success($category, null, 201);
    }

    public function authors(): JsonResponse
    {
        $authors = BookAuthor::withCount('books')->orderBy('name')->get();

        return $this->success($authors);
    }

    public function storeAuthor(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150',
            'country' => 'nullable|string|max:100',
            'biography' => 'nullable|string',
            'email' => 'nullable|email|max:150',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        $author = BookAuthor::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name).'-'.Str::lower(Str::random(4)),
            'country' => $request->country,
            'biography' => $request->biography,
            'email' => $request->email,
            'is_active' => true,
            'created_by' => $user?->id,
        ]);

        return $this->success($author, null, 201);
    }

    public function publishers(): JsonResponse
    {
        $publishers = BookPublisher::withCount('books')->orderBy('name')->get();

        return $this->success($publishers);
    }

    public function storePublisher(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'name' => 'required|string|max:150|unique:book_publishers,name',
            'contact_person' => 'nullable|string|max:150',
            'email' => 'nullable|email|max:150',
            'phone' => 'nullable|string|max:50',
            'address' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $user = $this->resolveUser($request);

        $publisher = BookPublisher::create([
            'name' => $request->name,
            'slug' => Str::slug($request->name),
            'contact_person' => $request->contact_person,
            'email' => $request->email,
            'phone' => $request->phone,
            'address' => $request->address,
            'is_active' => true,
            'created_by' => $user?->id,
        ]);

        return $this->success($publisher, null, 201);
    }

    // ==========================================
    // 10. STUDENT / MEMBER SELF-SERVICE
    // ==========================================

    /**
     * Resolve the current authenticated student's member record.
     */
    protected function resolveStudentMember(Request $request): ?LibraryMember
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return null;
        }

        return LibraryMember::where('user_id', $user->id)->first();
    }

    /**
     * Student's personal library account dashboard.
     */
    public function myAccount(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'User session or Bearer token is required.', 401);
        }

        $member = LibraryCardService::getOrCreateMemberForUser($user);

        $activeDeposit = $member->getActiveSecurityDeposit();
        $activeLoans = $member->activeIssues()->with(['book:id,title,cover_image', 'bookCopy:id,accession_no,shelf_location'])->get();
        $pendingReservations = BookReservation::with('book:id,title')->where('library_member_id', $member->id)->whereIn('status', [BookReservation::STATUS_PENDING, BookReservation::STATUS_READY])->get();
        $unpaidFines = (float) BookFine::where('library_member_id', $member->id)->where('status', BookFine::STATUS_PENDING)->sum('payable_amount');
        $clearanceEligibility = LibraryClearanceService::checkEligibility($user);

        $data = $member->toArray();
        $data['member'] = $member;
        $data['security_deposit_amount'] = $activeDeposit;
        $data['active_loans'] = $activeLoans;
        $data['active_reservations'] = $pendingReservations;
        $data['unpaid_fines'] = $unpaidFines;
        $data['clearance_status'] = $clearanceEligibility;

        return $this->success($data);
    }

    /**
     * Student's current active loans.
     */
    public function myLoans(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'User session or Bearer token is required.', 401);
        }

        $member = LibraryMember::where('user_id', $user->id)->first();
        if (! $member) {
            return $this->success([]);
        }

        $loans = $member->activeIssues()
            ->with(['book', 'bookCopy'])
            ->get()
            ->map(function ($issue) {
                return [
                    'id' => $issue->id,
                    'issue_no' => $issue->issue_no,
                    'book_title' => $issue->book->title,
                    'accession_no' => $issue->bookCopy?->accession_no,
                    'issue_date' => $issue->issue_date->toDateString(),
                    'due_date' => $issue->due_date->toDateString(),
                    'days_remaining' => (int) now()->diffInDays($issue->due_date, false),
                    'is_overdue' => $issue->isOverdue(),
                    'renewal_count' => $issue->renewal_count,
                    'max_renewals' => $issue->max_renewals_allowed,
                    'renewal_status' => $issue->renewal_status,
                ];
            });

        return $this->success($loans);
    }

    /**
     * Student's past reading history.
     */
    public function myHistory(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'User session or Bearer token is required.', 401);
        }

        $member = LibraryMember::where('user_id', $user->id)->first();
        if (! $member) {
            return $this->success([]);
        }

        $history = BookIssue::with(['book:id,title,cover_image', 'bookCopy:id,accession_no'])
            ->where('library_member_id', $member->id)
            ->where('status', BookIssue::STATUS_RETURNED)
            ->latest('return_date')
            ->paginate(15);

        return $this->success($history->items(), [
            'current_page' => $history->currentPage(),
            'total' => $history->total(),
        ]);
    }

    /**
     * Student self-reserve a book.
     */
    public function myReserveBook(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'User session or Bearer token is required.', 401);
        }

        $validator = Validator::make($request->all(), [
            'book_id' => 'required|exists:books,id',
            'notes' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return $this->error('VALIDATION_ERROR', 'Validation failed.', 422, $validator->errors()->toArray());
        }

        $member = LibraryCardService::getOrCreateMemberForUser($user);
        $book = Book::findOrFail($request->book_id);

        try {
            $reservation = BookCirculationService::reserveBook(
                book: $book,
                member: $member,
                notes: $request->notes
            );

            return $this->success($reservation->load('book:id,title'), null, 201);
        } catch (ValidationException $ve) {
            return $this->error('RESERVATION_VALIDATION_ERROR', $ve->getMessage(), 422, $ve->errors());
        } catch (Throwable $e) {
            return $this->error('RESERVATION_FAILED', $e->getMessage(), 500);
        }
    }

    /**
     * Student's active reservations.
     */
    public function myReservations(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            return $this->error('UNAUTHENTICATED', 'User session or Bearer token is required.', 401);
        }

        $member = LibraryMember::where('user_id', $user->id)->first();
        if (! $member) {
            return $this->success([]);
        }

        $reservations = BookReservation::with('book:id,title,cover_image')
            ->where('library_member_id', $member->id)
            ->latest('id')
            ->get();

        return $this->success($reservations);
    }

    /**
     * Student download own Library Card PDF.
     */
    public function myCard(Request $request): Response
    {
        $user = $this->resolveUser($request);
        if (! $user) {
            abort(401, 'Authentication required to download library card.');
        }

        $member = LibraryCardService::getOrCreateMemberForUser($user);

        return LibraryCardService::downloadCardPdf($member, true, true);
    }
}
