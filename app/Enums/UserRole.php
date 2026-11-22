<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Enums;

enum UserRole: string
{
    case Admin = 'admin';
    case Librarian = 'librarian';
    case Student = 'student';

    /**
     * Staff roles manage the catalogue and circulation records.
     */
    public function isStaff(): bool
    {
        return $this === self::Admin || $this === self::Librarian;
    }
}
