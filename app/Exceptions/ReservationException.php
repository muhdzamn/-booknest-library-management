<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Exceptions;

use App\Models\Book;
use App\Models\Reservation;
use Exception;

/**
 * A reservation business rule was broken. The message is safe to show to
 * users, and $field names the form input it relates to (if any).
 */
class ReservationException extends Exception
{
    public function __construct(string $message, public readonly ?string $field = null)
    {
        parent::__construct($message);
    }

    public static function alreadyReserved(Book $book): self
    {
        return new self("You already have an active reservation for \"{$book->title}\".", 'book_id');
    }

    public static function alreadyBorrowing(Book $book): self
    {
        return new self("You already have \"{$book->title}\" on loan.", 'book_id');
    }

    public static function notPending(Reservation $reservation): self
    {
        return new self("This reservation has already been {$reservation->effectiveStatus()->value} and can no longer be reviewed.");
    }

    public static function cannotBeCancelled(Reservation $reservation): self
    {
        return new self("This reservation is {$reservation->effectiveStatus()->value} and can no longer be cancelled.");
    }

    public static function bookOnLoan(Book $book): self
    {
        return new self("\"{$book->title}\" is currently on loan. Approve this reservation after it has been returned.");
    }

    public static function bookHeldForAnotherStudent(Book $book): self
    {
        return new self("\"{$book->title}\" is already on hold for another student.");
    }
}
