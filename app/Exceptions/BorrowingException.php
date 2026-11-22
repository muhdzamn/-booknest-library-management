<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Exceptions;

use App\Models\Book;
use App\Models\Borrowing;
use Exception;

/**
 * A circulation business rule was broken. The message is safe to show to
 * staff, and $field names the form input it relates to (if any).
 */
class BorrowingException extends Exception
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function bookUnavailable(Book $book): self
    {
        return new self("\"{$book->title}\" is already on loan and cannot be issued until it is returned.", 'book_id');
    }

    public static function heldForAnotherStudent(Book $book): self
    {
        return new self("\"{$book->title}\" is on hold for another student's approved reservation.", 'book_id');
    }

    public static function borrowerMustBeStudent(): self
    {
        return new self('Books can only be issued to registered students.', 'user_id');
    }

    public static function dueDateMustBeInFuture(): self
    {
        return new self('The due date must be after today.', 'due_at');
    }

    public static function alreadyReturned(Borrowing $borrowing): self
    {
        return new self("This loan was already returned on {$borrowing->returned_at?->format('j M Y')}.");
    }
}
