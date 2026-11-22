<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Actions;

use App\Exceptions\ReservationException;
use App\Models\Book;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class CreateReservationAction
{
    public function __construct(private ExpireStaleReservationsAction $expireStaleReservations) {}

    /**
     * Record a student's request to reserve a book. Books may be reserved
     * whether or not they are currently on loan; the request starts Pending.
     *
     * @throws AuthorizationException when $student is not a student.
     * @throws ModelNotFoundException when the book no longer exists.
     * @throws ReservationException when a reservation rule is broken.
     */
    public function handle(User $student, int $bookId): Reservation
    {
        Gate::forUser($student)->authorize('create', Reservation::class);

        try {
            return DB::transaction(function () use ($student, $bookId): Reservation {
                $book = Book::whereKey($bookId)->lockForUpdate()->firstOrFail();

                $this->expireStaleReservations->handle($book);

                if ($book->borrowings()->where('user_id', $student->id)->whereNull('returned_at')->exists()) {
                    throw ReservationException::alreadyBorrowing($book);
                }

                if ($book->reservations()->where('user_id', $student->id)->active()->exists()) {
                    throw ReservationException::alreadyReserved($book);
                }

                return $book->reservations()->create(['user_id' => $student->id]);
            });
        } catch (UniqueConstraintViolationException) {
            // A duplicate submission committed first.
            throw ReservationException::alreadyReserved(Book::findOrFail($bookId));
        }
    }
}
