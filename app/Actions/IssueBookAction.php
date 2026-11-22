<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Actions;

use App\Enums\BorrowingStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Exceptions\BorrowingException;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class IssueBookAction
{
    public function __construct(private ExpireStaleReservationsAction $expireStaleReservations) {}

    /**
     * Lend a book to a student.
     *
     * Availability rule: a catalogue book is one circulating copy, so it can
     * only be issued while it has no open loan. This is checked under a row
     * lock and guaranteed by the borrowings_one_active_loan_per_book index.
     *
     * Reservations: while another student holds an unexpired approved
     * reservation, the book can only be issued to that student. Issuing it
     * to a student marks their own active reservation for it as Fulfilled,
     * in the same transaction as the loan.
     *
     * @throws AuthorizationException when $librarian is not staff.
     * @throws BorrowingException when a circulation rule is broken.
     */
    public function handle(User $librarian, User $student, Book $book, CarbonInterface $dueDate): Borrowing
    {
        Gate::forUser($librarian)->authorize('create', Borrowing::class);

        if ($student->role !== UserRole::Student) {
            throw BorrowingException::borrowerMustBeStudent();
        }

        $dueAt = $dueDate->toImmutable()->endOfDay();

        if (! $dueAt->isAfter(now()->endOfDay())) {
            throw BorrowingException::dueDateMustBeInFuture();
        }

        try {
            return DB::transaction(function () use ($student, $book, $dueAt): Borrowing {
                $lockedBook = Book::whereKey($book->id)->lockForUpdate()->firstOrFail();

                if ($lockedBook->hasOpenLoan()) {
                    throw BorrowingException::bookUnavailable($lockedBook);
                }

                $this->expireStaleReservations->handle($lockedBook);

                $hold = $lockedBook->reservations()->activeHold()->first();

                if ($hold !== null && $hold->user_id !== $student->id) {
                    throw BorrowingException::heldForAnotherStudent($lockedBook);
                }

                $borrowing = $lockedBook->borrowings()->create([
                    'user_id' => $student->id,
                    'borrowed_at' => now(),
                    'due_at' => $dueAt,
                    'returned_at' => null,
                    'status' => BorrowingStatus::Borrowed,
                ]);

                $lockedBook->reservations()
                    ->where('user_id', $student->id)
                    ->active()
                    ->update(['status' => ReservationStatus::Fulfilled]);

                return $borrowing;
            });
        } catch (UniqueConstraintViolationException) {
            // Another issue of the same book committed first.
            throw BorrowingException::bookUnavailable($book);
        }
    }
}
