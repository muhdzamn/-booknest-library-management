<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class ReservationPolicy
{
    /**
     * Only staff see every reservation. Students see their own, because the
     * reservation list query is scoped to their user id.
     */
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Reservation $reservation): bool
    {
        return $user->isStaff() || $reservation->user_id === $user->id;
    }

    /**
     * Students request reservations; staff review them.
     */
    public function create(User $user): bool
    {
        return $user->role === UserRole::Student;
    }

    /**
     * Staff change a reservation's status. The Actions use this as the role
     * check and then re-check the reservation's state under a row lock.
     */
    public function update(User $user, Reservation $reservation): bool
    {
        return $user->isStaff();
    }

    /**
     * Staff may approve or reject a reservation that is still pending.
     */
    public function review(User $user, Reservation $reservation): Response
    {
        if (! $user->isStaff()) {
            return Response::deny('Only librarians and administrators can review reservations.');
        }

        return $reservation->isPending()
            ? Response::allow()
            : Response::deny("This reservation has already been {$reservation->effectiveStatus()->value}.");
    }

    /**
     * A student may withdraw their own pending request or active hold.
     * Staff may release an active hold (for example, a no-show); pending
     * requests are rejected by staff rather than cancelled.
     */
    public function cancel(User $user, Reservation $reservation): Response
    {
        if ($reservation->user_id === $user->id) {
            return $reservation->isPending() || $reservation->isActiveHold()
                ? Response::allow()
                : Response::deny("This reservation is {$reservation->effectiveStatus()->value} and can no longer be cancelled.");
        }

        if ($user->isStaff()) {
            return $reservation->isActiveHold()
                ? Response::allow()
                : Response::deny('Staff can only cancel approved holds. Reject pending requests instead.');
        }

        return Response::deny('You can only cancel your own reservations.');
    }

    public function delete(User $user, Reservation $reservation): bool
    {
        return $user->isAdmin();
    }
}
