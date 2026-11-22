<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Actions;

use App\Enums\ReservationStatus;
use App\Models\Book;
use App\Models\Reservation;

class ExpireStaleReservationsAction
{
    /**
     * Mark approved reservations whose hold has run out as "expired".
     *
     * Availability never depends on this having run (queries ignore holds
     * past expires_at). It exists so stale rows stop counting towards the
     * partial unique indexes, and so history shows the true outcome. The
     * reservation Actions call it inside their transactions; it is safe to
     * run at any time, any number of times.
     *
     * @return int The number of reservations marked as expired.
     */
    public function handle(?Book $book = null): int
    {
        return Reservation::query()
            ->staleHold()
            ->when($book, fn ($query) => $query->where('book_id', $book->id))
            ->update(['status' => ReservationStatus::Expired]);
    }
}
