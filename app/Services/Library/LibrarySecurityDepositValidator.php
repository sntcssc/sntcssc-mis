<?php

namespace App\Services\Library;

use App\Models\Book;
use App\Models\BookCopy;
use App\Models\LibraryMember;

class LibrarySecurityDepositValidator
{
    /**
     * Validate whether a member can borrow a specific book copy.
     *
     * @param  LibraryMember  $member  Borrower member
     * @param  BookCopy  $copy  The specific physical copy to borrow
     * @param  bool  $bypassDeposit  Explicit staff bypass flag
     * @param  string|null  $bypassReason  Mandatory justification if bypassed
     * @return array{
     *     allowed: bool,
     *     bypassed: bool,
     *     reason: string|null,
     *     active_deposit: float,
     *     current_borrowed_value: float,
     *     new_book_price: float,
     *     total_borrowed_value_after: float,
     *     available_deposit_headroom: float
     * }
     */
    public static function validateForIssue(
        LibraryMember $member,
        BookCopy $copy,
        bool $bypassDeposit = false,
        ?string $bypassReason = null
    ): array {
        $book = $copy->book;
        $newBookPrice = (float) ($copy->purchase_cost > 0 ? $copy->purchase_cost : ($book->price ?? 0.00));
        $activeDeposit = $member->getActiveSecurityDeposit();
        $currentBorrowedValue = $member->getActiveBorrowedBooksValue();
        $totalAfter = round($currentBorrowedValue + $newBookPrice, 2);
        $headroom = round($activeDeposit - $currentBorrowedValue, 2);

        // 1. Check member status
        if ($member->status !== LibraryMember::STATUS_ACTIVE) {
            return [
                'allowed' => false,
                'bypassed' => false,
                'reason' => __('Library member is not active (current status: :status). Cannot issue books.', ['status' => ucfirst($member->status)]),
                'active_deposit' => $activeDeposit,
                'current_borrowed_value' => $currentBorrowedValue,
                'new_book_price' => $newBookPrice,
                'total_borrowed_value_after' => $totalAfter,
                'available_deposit_headroom' => $headroom,
            ];
        }

        // 2. Check maximum books quota
        $maxBooks = LibraryPolicyService::getMaxBooksAllowed();
        if ($member->active_issued_count >= $maxBooks) {
            return [
                'allowed' => false,
                'bypassed' => false,
                'reason' => __('Member has reached the maximum allowed limit of :limit borrowed books.', ['limit' => $maxBooks]),
                'active_deposit' => $activeDeposit,
                'current_borrowed_value' => $currentBorrowedValue,
                'new_book_price' => $newBookPrice,
                'total_borrowed_value_after' => $totalAfter,
                'available_deposit_headroom' => $headroom,
            ];
        }

        // 3. Check copy availability
        if ($copy->status !== BookCopy::STATUS_AVAILABLE) {
            return [
                'allowed' => false,
                'bypassed' => false,
                'reason' => __('Book copy with accession number :acc is currently :status and cannot be issued.', [
                    'acc' => $copy->accession_no,
                    'status' => ucfirst(str_replace('_', ' ', $copy->status)),
                ]),
                'active_deposit' => $activeDeposit,
                'current_borrowed_value' => $currentBorrowedValue,
                'new_book_price' => $newBookPrice,
                'total_borrowed_value_after' => $totalAfter,
                'available_deposit_headroom' => $headroom,
            ];
        }

        // 4. Check if book is reference only
        if ($book->is_reference_only) {
            return [
                'allowed' => false,
                'bypassed' => false,
                'reason' => __('This book (:title) is marked as Reference Only and cannot be taken outside the library.', ['title' => $book->title]),
                'active_deposit' => $activeDeposit,
                'current_borrowed_value' => $currentBorrowedValue,
                'new_book_price' => $newBookPrice,
                'total_borrowed_value_after' => $totalAfter,
                'available_deposit_headroom' => $headroom,
            ];
        }

        // 5. Check staff bypass privilege
        $hasMemberBypass = $member->bypass_deposit_check;
        $isExplicitBypass = $bypassDeposit && ! empty($bypassReason);

        if ($hasMemberBypass || $isExplicitBypass) {
            $effectiveBypassReason = $isExplicitBypass
                ? $bypassReason
                : ($member->deposit_bypass_reason ?: __('Authorized institutional bypass for staff/member.'));

            return [
                'allowed' => true,
                'bypassed' => true,
                'reason' => $effectiveBypassReason,
                'active_deposit' => $activeDeposit,
                'current_borrowed_value' => $currentBorrowedValue,
                'new_book_price' => $newBookPrice,
                'total_borrowed_value_after' => $totalAfter,
                'available_deposit_headroom' => $headroom,
            ];
        }

        // 6. Security Deposit Requirement Policy Check
        if (LibraryPolicyService::isSecurityDepositRequired()) {
            if ($activeDeposit <= 0) {
                return [
                    'allowed' => false,
                    'bypassed' => false,
                    'reason' => __('Member has no active library security deposit on record (₹0.00). A refundable security deposit is mandatory to borrow books, or librarian authorization is required to bypass.'),
                    'active_deposit' => $activeDeposit,
                    'current_borrowed_value' => $currentBorrowedValue,
                    'new_book_price' => $newBookPrice,
                    'total_borrowed_value_after' => $totalAfter,
                    'available_deposit_headroom' => $headroom,
                ];
            }

            // Total borrowed value (existing active + new book) must be <= active security deposit
            if ($totalAfter > $activeDeposit) {
                $deficit = round($totalAfter - $activeDeposit, 2);

                return [
                    'allowed' => false,
                    'bypassed' => false,
                    'reason' => __('Security Deposit Limit Exceeded! New book price (₹:book) plus currently borrowed books (₹:current) totals ₹:total, which exceeds the active security deposit of ₹:deposit by ₹:deficit.', [
                        'book' => number_format($newBookPrice, 2),
                        'current' => number_format($currentBorrowedValue, 2),
                        'total' => number_format($totalAfter, 2),
                        'deposit' => number_format($activeDeposit, 2),
                        'deficit' => number_format($deficit, 2),
                    ]),
                    'active_deposit' => $activeDeposit,
                    'current_borrowed_value' => $currentBorrowedValue,
                    'new_book_price' => $newBookPrice,
                    'total_borrowed_value_after' => $totalAfter,
                    'available_deposit_headroom' => $headroom,
                ];
            }
        }

        return [
            'allowed' => true,
            'bypassed' => false,
            'reason' => null,
            'active_deposit' => $activeDeposit,
            'current_borrowed_value' => $currentBorrowedValue,
            'new_book_price' => $newBookPrice,
            'total_borrowed_value_after' => $totalAfter,
            'available_deposit_headroom' => $headroom,
        ];
    }

    /**
     * Validate whether a student or member can place a reservation for a book.
     *
     * @return array{allowed: bool, reason: string|null, active_deposit: float, current_borrowed_value: float, book_price: float}
     */
    public static function validateForReservation(LibraryMember $member, Book $book): array
    {
        $bookPrice = (float) $book->price;
        $activeDeposit = $member->getActiveSecurityDeposit();
        $currentBorrowedValue = $member->getActiveBorrowedBooksValue();
        $totalAfter = round($currentBorrowedValue + $bookPrice, 2);

        if ($member->status !== LibraryMember::STATUS_ACTIVE) {
            return [
                'allowed' => false,
                'reason' => __('Your library membership account is not active. Please contact the library desk.'),
                'active_deposit' => $activeDeposit,
                'current_borrowed_value' => $currentBorrowedValue,
                'book_price' => $bookPrice,
            ];
        }

        // Check if member already has active reservation for this book
        $alreadyReserved = $member->reservations()
            ->where('book_id', $book->id)
            ->whereIn('status', ['pending', 'ready_for_pickup'])
            ->exists();

        if ($alreadyReserved) {
            return [
                'allowed' => false,
                'reason' => __('You already have an active reservation for this book.'),
                'active_deposit' => $activeDeposit,
                'current_borrowed_value' => $currentBorrowedValue,
                'book_price' => $bookPrice,
            ];
        }

        // Check member bypass
        if ($member->bypass_deposit_check) {
            return [
                'allowed' => true,
                'reason' => null,
                'active_deposit' => $activeDeposit,
                'current_borrowed_value' => $currentBorrowedValue,
                'book_price' => $bookPrice,
            ];
        }

        // Deposit check
        if (LibraryPolicyService::isSecurityDepositRequired()) {
            if ($activeDeposit <= 0) {
                return [
                    'allowed' => false,
                    'reason' => __('You do not have an active library security deposit (₹0.00). Please submit a refundable security deposit to reserve and borrow books.'),
                    'active_deposit' => $activeDeposit,
                    'current_borrowed_value' => $currentBorrowedValue,
                    'book_price' => $bookPrice,
                ];
            }

            if ($totalAfter > $activeDeposit) {
                $deficit = round($totalAfter - $activeDeposit, 2);

                return [
                    'allowed' => false,
                    'reason' => __('Security Deposit Insufficient! Book value (₹:price) plus currently borrowed books (₹:borrowed) = ₹:total exceeds your deposit of ₹:deposit by ₹:deficit. Please increase deposit or return existing books.', [
                        'price' => number_format($bookPrice, 2),
                        'borrowed' => number_format($currentBorrowedValue, 2),
                        'total' => number_format($totalAfter, 2),
                        'deposit' => number_format($activeDeposit, 2),
                        'deficit' => number_format($deficit, 2),
                    ]),
                    'active_deposit' => $activeDeposit,
                    'current_borrowed_value' => $currentBorrowedValue,
                    'book_price' => $bookPrice,
                ];
            }
        }

        return [
            'allowed' => true,
            'reason' => null,
            'active_deposit' => $activeDeposit,
            'current_borrowed_value' => $currentBorrowedValue,
            'book_price' => $bookPrice,
        ];
    }

    /**
     * Helper for issue eligibility with 'eligible' key.
     */
    public static function validateIssueEligibility(BookCopy $copy, LibraryMember $member, bool $bypassDeposit = false, ?string $bypassReason = null): array
    {
        $res = static::validateForIssue($member, $copy, $bypassDeposit, $bypassReason);
        $res['eligible'] = $res['allowed'];

        return $res;
    }

    /**
     * Helper for reservation eligibility with 'eligible' key.
     */
    public static function validateReservationEligibility(LibraryMember $member, Book $book): array
    {
        $res = static::validateForReservation($member, $book);
        $res['eligible'] = $res['allowed'];

        return $res;
    }
}
