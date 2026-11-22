<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Actions;

use App\Models\Book;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class DeleteBookAction
{
    /**
     * Delete a catalogue book on behalf of the given user.
     *
     * BookPolicy::delete() allows admins only and refuses any book with
     * borrowing or reservation history. The check runs inside the same
     * transaction as the delete, and borrowings.book_id is also restricted
     * at the database level as a last line of defence.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Book $book): void
    {
        DB::transaction(function () use ($user, $book): void {
            Gate::forUser($user)->authorize('delete', $book);

            $book->delete();
        });
    }
}
