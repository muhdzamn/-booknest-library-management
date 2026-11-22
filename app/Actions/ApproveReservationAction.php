<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Actions;

use App\Enums\ReservationStatus;
use App\Exceptions\ReservationException;
use App\Models\Book;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ApproveReservationAction
{
    public function __construct(private ExpireStaleReservationsAction $expireStaleReservations) {}

    /**
     * Approve a pending reservation, placing the book on hold for the student
     * until expires_at (Reservation::HOLD_DAYS). Approval does not lend the
     * book: the hold is fulfilled when staff issue it to that student, and
     * lapses on its own if they do not collect it in time.
     *
     * @throws AuthorizationException when $librarian is not staff.
     * @throws ReservationException when the request is no longer pending or
     *                              the book is not available to hold.
     */
    public function handle(User $librarian, Reservation $reservation, ?string $remarks = null): Reservation
    {
        try {
            return DB::transaction(function () use ($librarian, $reservation, $remarks): Reservation {
                $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

                // Role first, so non-staff learn nothing about the request's state.
                Gate::forUser($librarian)->authorize('update', $locked);

                if (! $locked->isPending()) {
                    throw ReservationException::notPending($locked);
                }

                if ($locked->user_id === null) {
                    throw new ReservationException('The student who made this reservation no longer has an account.');
                }

                $book = Book::whereKey($locked->book_id)->lockForUpdate()->firstOrFail();

                $this->expireStaleReservations->handle($book);

                if ($book->hasOpenLoan()) {
                    throw ReservationException::bookOnLoan($book);
                }

                if ($book->reservations()->activeHold()->exists()) {
                    throw ReservationException::bookHeldForAnotherStudent($book);
                }

                // Conditional update: only one submission can move it out of Pending.
                $changed = Reservation::whereKey($locked->id)->pending()->update([
                    'status' => ReservationStatus::Approved,
                    'reviewed_by' => $librarian->id,
                    'reviewed_at' => now(),
                    'expires_at' => now()->addDays(Reservation::HOLD_DAYS)->endOfDay(),
                    'remarks' => filled($remarks) ? trim($remarks) : null,
                ]);

                if ($changed === 0) {
                    throw ReservationException::notPending($locked->refresh());
                }

                return $locked->refresh();
            });
        } catch (UniqueConstraintViolationException) {
            // Another hold on the same book committed first.
            throw ReservationException::bookHeldForAnotherStudent($reservation->book);
        }
    }
}
