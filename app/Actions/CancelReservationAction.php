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

class CancelReservationAction
{
    /**
     * Cancel a reservation so it stops blocking the book. Students may cancel
     * their own pending request or active hold; staff may release an active
     * hold (see ReservationPolicy::cancel). The row is kept as history.
     *
     * @throws AuthorizationException when $user may not see or cancel it.
     * @throws ReservationException when it is no longer active.
     */
    public function handle(User $user, Reservation $reservation): Reservation
    {
        return DB::transaction(function () use ($user, $reservation): Reservation {
            $locked = Reservation::whereKey($reservation->id)->lockForUpdate()->firstOrFail();

            // Hide other students' reservations entirely.
            Gate::forUser($user)->authorize('view', $locked);

            if (! $locked->isPending() && ! $locked->isActiveHold()) {
                throw ReservationException::cannotBeCancelled($locked);
            }

            Gate::forUser($user)->authorize('cancel', $locked);

            $changed = Reservation::whereKey($locked->id)
                ->active()
                ->update(['status' => ReservationStatus::Cancelled]);

            if ($changed === 0) {
                throw ReservationException::cannotBeCancelled($locked->refresh());
            }

            return $locked->refresh();
        });
    }
}
