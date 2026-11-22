<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Policies;

use App\Models\Borrowing;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BorrowingPolicy
{
    /**
     * Only staff see the full circulation list. Students see their own loans
     * through $user->borrowings, which is scoped by ownership.
     */
    public function viewAny(User $user): bool
    {
        return $user->isStaff();
    }

    public function view(User $user, Borrowing $borrowing): bool
    {
        return $user->isStaff() || $borrowing->user_id === $user->id;
    }

    /**
     * Loans are issued at the desk by staff.
     */
    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Staff record returns and correct due dates.
     */
    public function update(User $user, Borrowing $borrowing): bool
    {
        return $user->isStaff();
    }

    /**
     * Staff record a return, once, for a loan that is still open.
     * ReturnBookAction re-checks the open state under a row lock.
     */
    public function return(User $user, Borrowing $borrowing): Response
    {
        if (! $user->isStaff()) {
            return Response::deny('Only librarians and administrators can record returns.');
        }

        return $borrowing->isReturned()
            ? Response::deny('This loan has already been returned.')
            : Response::allow();
    }

    public function delete(User $user, Borrowing $borrowing): bool
    {
        return $user->isAdmin();
    }
}
