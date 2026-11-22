<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Policies;

use App\Models\Book;
use App\Models\User;
use Illuminate\Auth\Access\Response;

class BookPolicy
{
    /**
     * The catalogue is public, as it was in Assignment 1.
     */
    public function viewAny(?User $user): bool
    {
        return true;
    }

    /**
     * Book details are public, as they were in Assignment 1.
     */
    public function view(?User $user, Book $book): bool
    {
        return true;
    }

    /**
     * Admins and librarians may add books to the catalogue.
     */
    public function create(User $user): bool
    {
        return $user->isStaff();
    }

    /**
     * Admins and librarians may edit catalogue details.
     */
    public function update(User $user, Book $book): bool
    {
        return $user->isStaff();
    }

    /**
     * Only admins may delete books, and never one with borrowing or
     * reservation history, so circulation records are never lost.
     */
    public function delete(User $user, Book $book): Response
    {
        if (! $user->isAdmin()) {
            return Response::deny('Only administrators can delete books.');
        }

        return $book->hasCirculationHistory()
            ? Response::deny('This book has borrowing or reservation history and cannot be deleted.')
            : Response::allow();
    }
}
