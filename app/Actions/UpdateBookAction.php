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
use Illuminate\Support\Facades\Gate;

class UpdateBookAction
{
    /**
     * Update a catalogue book on behalf of the given user.
     *
     * @param  array{category_id: int, title: string, author: string, author_id: int|null, publisher_id: int|null, year_published: int, price: string}  $attributes  Already-validated input.
     *
     * @throws AuthorizationException
     */
    public function handle(User $user, Book $book, array $attributes): Book
    {
        Gate::forUser($user)->authorize('update', $book);

        $book->update($attributes);

        return $book;
    }
}
