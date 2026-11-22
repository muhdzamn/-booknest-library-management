<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Actions;

use App\Enums\BorrowingStatus;
use App\Exceptions\BorrowingException;
use App\Models\Borrowing;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ReturnBookAction
{
    /**
     * Record that a borrowed book has come back. The loan row is kept (and
     * only stamped) so the full borrowing history remains auditable.
     *
     * @throws AuthorizationException when $librarian is not staff.
     * @throws BorrowingException when the loan was already returned.
     */
    public function handle(User $librarian, Borrowing $borrowing): Borrowing
    {
        return DB::transaction(function () use ($librarian, $borrowing): Borrowing {
            $lockedBorrowing = Borrowing::whereKey($borrowing->id)->lockForUpdate()->firstOrFail();

            // Role first, so non-staff learn nothing about the loan's state.
            Gate::forUser($librarian)->authorize('update', $lockedBorrowing);

            // Re-read under the lock: a return recorded a moment ago in
            // another tab or by another librarian is caught here.
            if ($lockedBorrowing->isReturned()) {
                throw BorrowingException::alreadyReturned($lockedBorrowing);
            }

            $lockedBorrowing->update([
                'returned_at' => now(),
                'status' => BorrowingStatus::Returned,
            ]);

            return $lockedBorrowing;
        });
    }
}
