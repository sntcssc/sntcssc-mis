<?php

namespace App\Services\Library;

use App\Models\AppNotification;
use App\Models\Book;
use App\Models\BookCopy;
use App\Models\BookFine;
use App\Models\BookIssue;
use App\Models\BookReservation;
use App\Models\LibraryMember;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class BookCirculationService
{
    /**
     * Issue a book copy to a member with deposit verification and bypass authorization.
     */
    public static function issueBook(
        BookCopy $copy,
        LibraryMember $member,
        ?int $loanDays = null,
        bool $bypassDeposit = false,
        ?string $bypassReason = null,
        ?string $notes = null,
        ?User $issuedBy = null
    ): BookIssue {
        return DB::transaction(function () use ($copy, $member, $loanDays, $bypassDeposit, $bypassReason, $notes, $issuedBy) {
            // Lock copy for update
            $lockedCopy = BookCopy::lockForUpdate()->with('book')->findOrFail($copy->id);

            // Validate using Security Deposit & Quota rules
            $validation = LibrarySecurityDepositValidator::validateForIssue(
                $member,
                $lockedCopy,
                $bypassDeposit,
                $bypassReason
            );

            if (! $validation['allowed']) {
                throw ValidationException::withMessages([
                    'security_deposit' => $validation['reason'],
                ]);
            }

            $book = $lockedCopy->book;
            $bookPrice = (float) ($lockedCopy->purchase_cost > 0 ? $lockedCopy->purchase_cost : ($book->price ?? 0.00));
            $days = $loanDays ?? LibraryPolicyService::getDefaultLoanDays();
            $dueDate = LibraryPolicyService::calculateDueDate($days);

            // Generate unique issue_no
            $issueNo = self::generateIssueNo();

            $staffId = $issuedBy ? $issuedBy->id : auth()->id();

            $issue = BookIssue::create([
                'issue_no' => $issueNo,
                'book_id' => $book->id,
                'book_copy_id' => $lockedCopy->id,
                'library_member_id' => $member->id,
                'user_id' => $member->user_id,
                'issue_date' => now()->toDateString(),
                'due_date' => $dueDate->toDateString(),
                'status' => BookIssue::STATUS_ISSUED,
                'renewal_status' => BookIssue::RENEWAL_NONE,
                'renewal_count' => 0,
                'max_renewals_allowed' => LibraryPolicyService::getMaxRenewals(),
                'issued_by' => $staffId,
                'book_price_at_issue' => $bookPrice,
                'is_deposit_bypassed' => $validation['bypassed'],
                'bypass_reason' => $validation['bypassed'] ? $validation['reason'] : null,
                'notes' => $notes,
            ]);

            // Mark physical copy as issued
            $lockedCopy->markStatus(BookCopy::STATUS_ISSUED, "Issued to {$member->name} ({$member->member_number}) under {$issueNo}");

            // If member had an active reservation for this book, fulfill it
            $pendingReservation = BookReservation::where('library_member_id', $member->id)
                ->where('book_id', $book->id)
                ->whereIn('status', [BookReservation::STATUS_PENDING, BookReservation::STATUS_READY])
                ->latest()
                ->first();

            if ($pendingReservation) {
                $pendingReservation->update([
                    'status' => BookReservation::STATUS_FULFILLED,
                    'fulfilled_at' => now(),
                    'book_copy_id' => $lockedCopy->id,
                    'notes' => "Fulfilled through checkout {$issueNo}",
                ]);
            }

            // In-App Notification to member if linked to User
            if ($member->user_id) {
                AppNotification::create([
                    'user_id' => $member->user_id,
                    'type' => 'library_book_issued',
                    'category' => AppNotification::CATEGORY_SYSTEM,
                    'title' => __('Book Issued'),
                    'message' => __('":title" (Copy: :acc) has been issued to you. Due date is :due.', [
                        'title' => $book->title,
                        'acc' => $lockedCopy->accession_no,
                        'due' => $dueDate->format('d M Y'),
                    ]),
                    'channel' => AppNotification::CHANNEL_DATABASE,
                    'data' => [
                        'issue_id' => $issue->id,
                        'issue_no' => $issue->issue_no,
                        'due_date' => $dueDate->toDateString(),
                    ],
                ]);
            }

            return $issue;
        });
    }

    /**
     * Return an issued book, calculate overdue fines, update copy condition, and check reservation queues.
     *
     * @return array{issue: BookIssue, fine: BookFine|null}
     */
    public static function returnBook(
        BookIssue $issue,
        string $returnCondition = 'good',
        ?string $notes = null,
        ?User $returnedBy = null
    ): array {
        return DB::transaction(function () use ($issue, $returnCondition, $notes, $returnedBy) {
            $lockedIssue = BookIssue::lockForUpdate()->with(['book', 'bookCopy', 'libraryMember'])->findOrFail($issue->id);

            if ($lockedIssue->status === BookIssue::STATUS_RETURNED) {
                throw new \InvalidArgumentException(__('This book loan has already been closed and marked returned.'));
            }

            $copy = $lockedIssue->bookCopy;
            $member = $lockedIssue->libraryMember;
            $staffId = $returnedBy ? $returnedBy->id : auth()->id();

            // Assess fines
            $fineAssessment = LibraryPolicyService::assessIssueFine($lockedIssue);
            $fineRecord = null;

            if ($fineAssessment['fine_amount'] > 0) {
                $fineRecord = BookFine::create([
                    'fine_no' => self::generateFineNo(),
                    'book_issue_id' => $lockedIssue->id,
                    'library_member_id' => $member->id,
                    'user_id' => $member->user_id,
                    'overdue_days' => $fineAssessment['overdue_days'],
                    'fine_rate_per_day' => $fineAssessment['fine_rate'],
                    'calculated_fine' => $fineAssessment['fine_amount'],
                    'waived_amount' => 0.00,
                    'payable_amount' => $fineAssessment['fine_amount'],
                    'paid_amount' => 0.00,
                    'status' => BookFine::STATUS_PENDING,
                    'notes' => $notes,
                ]);
            }

            // Update Issue Record
            $lockedIssue->update([
                'status' => BookIssue::STATUS_RETURNED,
                'return_date' => now()->toDateString(),
                'return_condition' => $returnCondition,
                'returned_by' => $staffId,
                'notes' => $notes ?: $lockedIssue->notes,
            ]);

            // Update Physical Copy Condition if changed
            if ($copy->book_condition !== $returnCondition) {
                $copy->markCondition($returnCondition, "Condition upon return from {$member->name}: {$returnCondition}");
            }

            // Check reservation queue for this book
            $nextReservation = BookReservation::where('book_id', $copy->book_id)
                ->where('status', BookReservation::STATUS_PENDING)
                ->orderBy('reserved_at', 'asc')
                ->first();

            if ($nextReservation) {
                // Reserve copy for waiting user and notify them
                $copy->markStatus(BookCopy::STATUS_RESERVED, "Held for reservation {$nextReservation->reservation_no}");
                $expiryDays = LibraryPolicyService::getReservationExpiryDays();
                $nextReservation->update([
                    'status' => BookReservation::STATUS_READY,
                    'book_copy_id' => $copy->id,
                    'expires_at' => now()->addDays($expiryDays),
                ]);

                if ($nextReservation->user_id) {
                    AppNotification::create([
                        'user_id' => $nextReservation->user_id,
                        'type' => 'library_book_ready',
                        'category' => AppNotification::CATEGORY_SYSTEM,
                        'title' => __('Reserved Book Ready for Pickup'),
                        'message' => __('Your reserved book ":title" is now available at the library desk. Please collect it within :days days.', [
                            'title' => $copy->book->title,
                            'days' => $expiryDays,
                        ]),
                        'channel' => AppNotification::CHANNEL_DATABASE,
                        'data' => [
                            'reservation_id' => $nextReservation->id,
                            'book_id' => $copy->book_id,
                            'copy_id' => $copy->id,
                        ],
                    ]);
                }
            } else {
                $copy->markStatus(BookCopy::STATUS_AVAILABLE, "Returned by {$member->name} and made available in circulation.");
            }

            $member->refreshIssueCounters();

            return [
                'issue' => $lockedIssue,
                'fine' => $fineRecord,
                'overdue_days' => $fineAssessment['overdue_days'],
            ];
        });
    }

    /**
     * Renew an active book loan, extending due date by configured loan days (15 days).
     */
    public static function renewBook(
        BookIssue $issue,
        ?int $additionalDays = null,
        ?User $renewedBy = null
    ): BookIssue {
        return DB::transaction(function () use ($issue, $additionalDays, $renewedBy) {
            $lockedIssue = BookIssue::lockForUpdate()->with(['book', 'libraryMember'])->findOrFail($issue->id);

            if ($lockedIssue->status === BookIssue::STATUS_RETURNED) {
                throw new \InvalidArgumentException(__('Cannot renew a book loan that has already been returned.'));
            }

            if ($lockedIssue->renewal_count >= $lockedIssue->max_renewals_allowed) {
                throw ValidationException::withMessages([
                    'renewal' => __('Maximum renewal limit of :max renewals has been reached for this book.', ['max' => $lockedIssue->max_renewals_allowed]),
                ]);
            }

            // Check if another member is waiting in reservation queue
            $waitingReservations = BookReservation::where('book_id', $lockedIssue->book_id)
                ->where('status', BookReservation::STATUS_PENDING)
                ->where('library_member_id', '!=', $lockedIssue->library_member_id)
                ->exists();

            if ($waitingReservations) {
                throw ValidationException::withMessages([
                    'renewal' => __('This book cannot be renewed because another library member is waiting in the reservation queue.'),
                ]);
            }

            $days = $additionalDays ?? LibraryPolicyService::getDefaultLoanDays();
            $newDueDate = $lockedIssue->due_date->copy()->addDays($days);
            $staffId = $renewedBy ? $renewedBy->id : auth()->id();

            $lockedIssue->update([
                'status' => BookIssue::STATUS_RENEWED,
                'renewal_count' => $lockedIssue->renewal_count + 1,
                'due_date' => $newDueDate->toDateString(),
                'last_renewed_at' => now(),
                'renewal_status' => BookIssue::RENEWAL_APPROVED,
                'renewal_reviewed_by' => $staffId,
                'renewal_reviewed_at' => now(),
                'renewal_rejection_reason' => null,
            ]);

            return $lockedIssue;
        });
    }

    /**
     * Submit a loan renewal request from a student or member.
     */
    public static function requestLoanRenewal(
        BookIssue $issue,
        ?string $reason = null,
        ?int $requestedDays = null,
        ?User $requestedBy = null
    ): BookIssue {
        return DB::transaction(function () use ($issue, $reason, $requestedDays, $requestedBy) {
            $lockedIssue = BookIssue::lockForUpdate()->with(['book', 'libraryMember'])->findOrFail($issue->id);

            if ($lockedIssue->status === BookIssue::STATUS_RETURNED) {
                throw new \InvalidArgumentException(__('Cannot request renewal for a book loan that has already been returned.'));
            }

            if ($lockedIssue->renewal_count >= $lockedIssue->max_renewals_allowed) {
                throw ValidationException::withMessages([
                    'renewal' => __('Maximum renewal limit of :max renewals has been reached for this book.', ['max' => $lockedIssue->max_renewals_allowed]),
                ]);
            }

            if ($lockedIssue->renewal_status === BookIssue::RENEWAL_REQUESTED) {
                throw ValidationException::withMessages([
                    'renewal' => __('A renewal request for this book loan is already pending review by the librarian.'),
                ]);
            }

            // Check if another member is waiting in reservation queue
            $waitingReservations = BookReservation::where('book_id', $lockedIssue->book_id)
                ->where('status', BookReservation::STATUS_PENDING)
                ->where('library_member_id', '!=', $lockedIssue->library_member_id)
                ->exists();

            if ($waitingReservations) {
                throw ValidationException::withMessages([
                    'renewal' => __('This book cannot be renewed because another library member is waiting in the reservation queue.'),
                ]);
            }

            $days = $requestedDays ?? LibraryPolicyService::getDefaultLoanDays();

            // If renewal approval is NOT required by policy, renew immediately
            if (! LibraryPolicyService::isRenewalApprovalRequired()) {
                return self::renewBook($lockedIssue, $days, $requestedBy);
            }

            $lockedIssue->update([
                'renewal_status' => BookIssue::RENEWAL_REQUESTED,
                'renewal_requested_at' => now(),
                'renewal_requested_days' => $days,
                'renewal_request_reason' => $reason,
                'renewal_rejection_reason' => null,
            ]);

            return $lockedIssue;
        });
    }

    /**
     * Approve a pending loan renewal request (by Librarian or Admin).
     */
    public static function approveLoanRenewal(
        BookIssue $issue,
        ?int $additionalDays = null,
        ?string $notes = null,
        ?User $reviewedBy = null
    ): BookIssue {
        return DB::transaction(function () use ($issue, $additionalDays, $notes, $reviewedBy) {
            $lockedIssue = BookIssue::lockForUpdate()->with(['book', 'libraryMember'])->findOrFail($issue->id);

            if ($lockedIssue->status === BookIssue::STATUS_RETURNED) {
                throw new \InvalidArgumentException(__('Cannot approve renewal for a book loan that has already been returned.'));
            }

            if ($lockedIssue->renewal_count >= $lockedIssue->max_renewals_allowed) {
                throw ValidationException::withMessages([
                    'renewal' => __('Maximum renewal limit of :max renewals has been reached for this book.', ['max' => $lockedIssue->max_renewals_allowed]),
                ]);
            }

            // Check if another member is waiting in reservation queue
            $waitingReservations = BookReservation::where('book_id', $lockedIssue->book_id)
                ->where('status', BookReservation::STATUS_PENDING)
                ->where('library_member_id', '!=', $lockedIssue->library_member_id)
                ->exists();

            if ($waitingReservations) {
                throw ValidationException::withMessages([
                    'renewal' => __('This book cannot be renewed because another library member is waiting in the reservation queue.'),
                ]);
            }

            $days = $additionalDays ?? ($lockedIssue->renewal_requested_days ?: LibraryPolicyService::getDefaultLoanDays());
            $newDueDate = $lockedIssue->due_date->copy()->addDays($days);
            $staffId = $reviewedBy ? $reviewedBy->id : auth()->id();

            $lockedIssue->update([
                'status' => BookIssue::STATUS_RENEWED,
                'renewal_count' => $lockedIssue->renewal_count + 1,
                'due_date' => $newDueDate->toDateString(),
                'last_renewed_at' => now(),
                'renewal_status' => BookIssue::RENEWAL_APPROVED,
                'renewal_reviewed_by' => $staffId,
                'renewal_reviewed_at' => now(),
                'renewal_rejection_reason' => null,
                'notes' => $notes ? trim(($lockedIssue->notes ? $lockedIssue->notes."\n" : '')."Renewal Approved: {$notes}") : $lockedIssue->notes,
            ]);

            return $lockedIssue;
        });
    }

    /**
     * Reject a pending loan renewal request (by Librarian or Admin).
     */
    public static function rejectLoanRenewal(
        BookIssue $issue,
        string $rejectionReason,
        ?User $reviewedBy = null
    ): BookIssue {
        return DB::transaction(function () use ($issue, $rejectionReason, $reviewedBy) {
            $lockedIssue = BookIssue::lockForUpdate()->with(['book', 'libraryMember'])->findOrFail($issue->id);

            if ($lockedIssue->renewal_status !== BookIssue::RENEWAL_REQUESTED) {
                throw new \InvalidArgumentException(__('Only pending renewal requests can be rejected.'));
            }

            $staffId = $reviewedBy ? $reviewedBy->id : auth()->id();

            $lockedIssue->update([
                'renewal_status' => BookIssue::RENEWAL_REJECTED,
                'renewal_reviewed_by' => $staffId,
                'renewal_reviewed_at' => now(),
                'renewal_rejection_reason' => $rejectionReason,
            ]);

            return $lockedIssue;
        });
    }

    /**
     * Reserve a book from catalog for a member.
     */
    public static function reserveBook(
        Book $book,
        LibraryMember $member,
        BookCopy|string|null $copy = null,
        ?string $notes = null
    ): BookReservation {
        if (is_string($copy)) {
            $notes = $copy;
            $copy = null;
        }

        return DB::transaction(function () use ($book, $member, $copy, $notes) {
            $validation = LibrarySecurityDepositValidator::validateForReservation($member, $book);

            if (! $validation['allowed']) {
                throw ValidationException::withMessages([
                    'reservation' => $validation['reason'],
                ]);
            }

            $expiryDays = LibraryPolicyService::getReservationExpiryDays();

            return BookReservation::create([
                'reservation_no' => self::generateReservationNo(),
                'book_id' => $book->id,
                'book_copy_id' => $copy?->id,
                'library_member_id' => $member->id,
                'user_id' => $member->user_id,
                'reserved_at' => now(),
                'expires_at' => now()->addDays($expiryDays),
                'status' => BookReservation::STATUS_PENDING,
                'notes' => $notes,
            ]);
        });
    }

    /**
     * Cancel an active reservation.
     */
    public static function cancelReservation(
        BookReservation $reservation,
        string $reason,
        ?User $cancelledBy = null
    ): BookReservation {
        return DB::transaction(function () use ($reservation, $reason) {
            $res = BookReservation::lockForUpdate()->findOrFail($reservation->id);

            // If a copy was placed on hold for this reservation, free it up
            if ($res->book_copy_id && $res->status === BookReservation::STATUS_READY) {
                $copy = BookCopy::find($res->book_copy_id);
                if ($copy && $copy->status === BookCopy::STATUS_RESERVED) {
                    $copy->markStatus(BookCopy::STATUS_AVAILABLE, "Hold released following reservation cancellation ({$res->reservation_no}).");
                }
            }

            $res->update([
                'status' => BookReservation::STATUS_CANCELLED,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $res;
        });
    }

    /**
     * Fulfill a pending reservation by issuing an available physical copy to the reserved member.
     */
    public static function fulfillReservation(
        BookReservation $reservation,
        BookCopy $copy,
        int $loanDurationDays = 15,
        bool $bypassDeposit = false,
        ?string $bypassJustification = null,
        ?User $issuedBy = null
    ): BookIssue {
        return DB::transaction(function () use ($reservation, $copy, $loanDurationDays, $bypassDeposit, $bypassJustification, $issuedBy) {
            $res = BookReservation::lockForUpdate()->with(['libraryMember'])->findOrFail($reservation->id);
            $member = $res->libraryMember;

            $issue = self::issueBook(
                $copy,
                $member,
                $loanDurationDays,
                $bypassDeposit,
                $bypassJustification,
                "Fulfilled from Reservation #{$res->reservation_no}",
                $issuedBy
            );

            $res->update([
                'status' => BookReservation::STATUS_FULFILLED,
                'fulfilled_at' => now(),
                'book_copy_id' => $copy->id,
            ]);

            return $issue;
        });
    }

    /**
     * Issue one or multiple books or journals to a student, member, or visitor for in-house reading.
     * Bypasses security deposit criteria completely and sets due time for today.
     *
     * @param  array<BookCopy|int>  $copies  One or multiple physical copies or journals
     * @param  LibraryMember|null  $member  Optional registered member
     * @param  array{name?: string|null, phone?: string|null, identifier?: string|null, seat_no?: string|null}  $readerDetails
     * @param  string|null  $dueTime  Configurable due time today (default 17:00 / closing time)
     * @return Collection<BookIssue>
     */
    public static function issueInHouseReading(
        array $copies,
        ?LibraryMember $member = null,
        array $readerDetails = [],
        ?string $dueTime = null,
        ?string $notes = null,
        ?User $issuedBy = null
    ): Collection {
        if (empty($copies)) {
            throw ValidationException::withMessages([
                'copies' => __('Please select at least one book or journal copy to issue for in-house reading.'),
            ]);
        }

        $maxItems = LibraryPolicyService::getInhouseReadingMaxItems();
        if (count($copies) > $maxItems) {
            throw ValidationException::withMessages([
                'copies' => __('A maximum of :max books or journals can be served in a single in-house reading session.', ['max' => $maxItems]),
            ]);
        }

        $staffId = $issuedBy ? $issuedBy->id : auth()->id();
        $closingTime = $dueTime ?: LibraryPolicyService::getInhouseReadingEndTime();
        $sessionToken = 'INH-'.now()->format('Ymd').'-'.strtoupper(Str::random(6));

        return DB::transaction(function () use ($copies, $member, $readerDetails, $closingTime, $sessionToken, $notes, $staffId) {
            $readerName = $readerDetails['name'] ?? ($member?->name ?? 'Reading Room Visitor');
            $readerPhone = $readerDetails['phone'] ?? ($member?->phone ?? null);
            $readerId = $readerDetails['identifier'] ?? ($member?->student_id ?: $member?->member_number ?? null);
            $seatNo = $readerDetails['seat_no'] ?? null;

            // Resolve or create member record if null
            $activeMember = $member;
            if (! $activeMember) {
                $memNumber = 'LIB-INH-'.now()->format('ymd').'-'.strtoupper(Str::random(4));
                $activeMember = LibraryMember::create([
                    'member_number' => $memNumber,
                    'name' => $readerName,
                    'phone' => $readerPhone,
                    'student_id' => $readerId,
                    'member_type' => 'walk_in',
                    'joining_date' => now()->toDateString(),
                    'bypass_deposit_check' => true,
                    'deposit_bypass_reason' => 'In-House Library Reading Room Facility',
                    'status' => LibraryMember::STATUS_ACTIVE,
                ]);
            }

            $issuedRecords = collect();

            foreach ($copies as $copyItem) {
                $copyId = $copyItem instanceof BookCopy ? $copyItem->id : (int) $copyItem;
                $lockedCopy = BookCopy::lockForUpdate()->with('book')->findOrFail($copyId);

                if ($lockedCopy->status !== BookCopy::STATUS_AVAILABLE) {
                    throw ValidationException::withMessages([
                        'copies' => __('Copy ":acc" (:title) is currently not available for issue (Status: :status).', [
                            'acc' => $lockedCopy->accession_no,
                            'title' => $lockedCopy->book?->title ?? 'Item',
                            'status' => ucfirst(str_replace('_', ' ', $lockedCopy->status)),
                        ]),
                    ]);
                }

                $book = $lockedCopy->book;
                $bookPrice = (float) ($lockedCopy->purchase_cost > 0 ? $lockedCopy->purchase_cost : ($book?->price ?? 0.00));
                $issueNo = self::generateIssueNo();

                $issue = BookIssue::create([
                    'issue_no' => $issueNo,
                    'book_id' => $book->id,
                    'book_copy_id' => $lockedCopy->id,
                    'library_member_id' => $activeMember->id,
                    'user_id' => $activeMember->user_id,
                    'issue_date' => now()->toDateString(),
                    'due_date' => now()->toDateString(), // Due today!
                    'status' => BookIssue::STATUS_ISSUED,
                    'issue_type' => BookIssue::TYPE_IN_HOUSE,
                    'inhouse_session_token' => $sessionToken,
                    'reader_name' => $readerName,
                    'reader_phone' => $readerPhone,
                    'reader_identifier' => $readerId,
                    'reading_room_seat_no' => $seatNo,
                    'due_time' => $closingTime,
                    'renewal_count' => 0,
                    'max_renewals_allowed' => 0,
                    'issued_by' => $staffId,
                    'book_price_at_issue' => $bookPrice,
                    'is_deposit_bypassed' => true, // Bypassed without checking security deposit criteria
                    'bypass_reason' => 'In-House Library Reading Room Facility (Day Use)',
                    'notes' => $notes ?: ($seatNo ? "Reading Room {$seatNo}" : 'In-House Reading'),
                ]);

                // Mark physical copy as in-house reading
                $lockedCopy->markStatus(
                    BookCopy::STATUS_IN_HOUSE_READING,
                    "Issued for in-house reading to {$readerName} ({$seatNo}) under {$issueNo}"
                );

                $issuedRecords->push($issue);
            }

            return $issuedRecords;
        });
    }

    /**
     * Return an in-house reading copy.
     */
    public static function returnInHouseIssue(
        BookIssue $issue,
        string $returnCondition = 'good',
        ?string $notes = null,
        ?User $returnedBy = null
    ): BookIssue {
        return DB::transaction(function () use ($issue, $returnCondition, $notes, $returnedBy) {
            $lockedIssue = BookIssue::lockForUpdate()->with(['book', 'bookCopy', 'libraryMember'])->findOrFail($issue->id);

            if ($lockedIssue->status === BookIssue::STATUS_RETURNED) {
                return $lockedIssue;
            }

            $copy = $lockedIssue->bookCopy;
            $staffId = $returnedBy ? $returnedBy->id : auth()->id();

            $lockedIssue->update([
                'status' => BookIssue::STATUS_RETURNED,
                'return_date' => now()->toDateString(),
                'returned_time' => now(),
                'return_condition' => $returnCondition,
                'returned_by' => $staffId,
                'notes' => $notes ?: $lockedIssue->notes,
            ]);

            if ($copy) {
                $copy->markStatus(BookCopy::STATUS_AVAILABLE, 'Returned from in-house reading room');
                if ($copy->book_condition !== $returnCondition) {
                    $copy->markCondition($returnCondition, "Condition upon return: {$returnCondition}");
                }
            }

            return $lockedIssue;
        });
    }

    /**
     * Return all in-house reading copies belonging to an inhouse session token.
     */
    public static function returnInHouseSession(
        string $sessionToken,
        string $returnCondition = 'good',
        ?string $notes = null,
        ?User $returnedBy = null
    ): int {
        return DB::transaction(function () use ($sessionToken, $returnCondition, $notes, $returnedBy) {
            $issues = BookIssue::where('inhouse_session_token', $sessionToken)
                ->where('status', BookIssue::STATUS_ISSUED)
                ->get();

            $returnedCount = 0;
            foreach ($issues as $issue) {
                self::returnInHouseIssue($issue, $returnCondition, $notes, $returnedBy);
                $returnedCount++;
            }

            return $returnedCount;
        });
    }

    /**
     * Generate unique sequential Issue Number.
     */
    public static function generateIssueNo(): string
    {
        $year = now()->format('Y');
        $last = BookIssue::withTrashed()
            ->where('issue_no', 'like', "ISS-{$year}-%")
            ->orderBy('id', 'desc')
            ->first();

        $seq = 1;
        if ($last && preg_match("/ISS-{$year}-(\d+)/", $last->issue_no, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('ISS-%s-%05d', $year, $seq);
    }

    /**
     * Generate unique sequential Fine Number.
     */
    public static function generateFineNo(): string
    {
        $year = now()->format('Y');
        $last = BookFine::withTrashed()
            ->where('fine_no', 'like', "FINE-{$year}-%")
            ->orderBy('id', 'desc')
            ->first();

        $seq = 1;
        if ($last && preg_match("/FINE-{$year}-(\d+)/", $last->fine_no, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('FINE-%s-%05d', $year, $seq);
    }

    /**
     * Generate unique sequential Reservation Number.
     */
    public static function generateReservationNo(): string
    {
        $year = now()->format('Y');
        $last = BookReservation::withTrashed()
            ->where('reservation_no', 'like', "RES-{$year}-%")
            ->orderBy('id', 'desc')
            ->first();

        $seq = 1;
        if ($last && preg_match("/RES-{$year}-(\d+)/", $last->reservation_no, $matches)) {
            $seq = ((int) $matches[1]) + 1;
        }

        return sprintf('RES-%s-%05d', $year, $seq);
    }
}
