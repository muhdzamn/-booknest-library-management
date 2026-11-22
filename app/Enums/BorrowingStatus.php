<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Enums;

/**
 * "Overdue" is intentionally not stored: it is derived from due_at while the
 * status is still Borrowed, so it can never drift out of sync with the dates.
 */
enum BorrowingStatus: string
{
    case Borrowed = 'borrowed';
    case Returned = 'returned';
}
