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
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RejectReservationAction
{
    /**
     * Reject a pending reservation. A reason is required so the student
     * understands the decision; it is kept in remarks with the reviewer.
     *
     * @throws AuthorizationException when $librarian is not staff.
     * @throws ReservationException when no reason is given or the request
     *                              is no longer pending.
     */
    public function handle(User $librarian, Reservation $reservation, string $remarks): Reservation
    {
        return DB::transaction(function () use ($librarian, $reservation, $remarks): Reservation {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            Gate::forUser($librarian)->authorize('update', $locked);

            if (blank($remarks)) {
                throw new ReservationException('Please give a reason for rejecting this reservation.', 'remarks');
            }

            if (! $locked->isPending()) {
                throw ReservationException::notPending($locked);
            }

            // Conditional update: only one submission can move it out of Pending.
            $changed = Reservation::whereKey($locked->id)->pending()->update([
                'status' => ReservationStatus::Rejected,
                'reviewed_by' => $librarian->id,
                'reviewed_at' => now(),
                'remarks' => trim($remarks),
            ]);

            if ($changed === 0) {
                throw ReservationException::notPending($locked->refresh());
            }

            return $locked->refresh();
        });
    }
}
