<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Enums;

/**
 * Reservation lifecycle:
 *
 *   Pending ──approve──▶ Approved ──book issued to the holder──▶ Fulfilled
 *      │                    ├──student/staff cancels────────────▶ Cancelled
 *      │                    └──expires_at passes────────────────▶ Expired
 *      ├──reject──▶ Rejected
 *      └──student cancels──▶ Cancelled
 *
 * Only Pending and Approved are "active". An Approved reservation is a hold
 * that reserves the book for its student until expires_at.
 */
enum ReservationStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Fulfilled = 'fulfilled';
    case Expired = 'expired';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved (on hold)',
            default => ucfirst($this->value),
        };
    }

    /**
     * Flux badge colour for this status.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'amber',
            self::Approved => 'blue',
            self::Rejected => 'red',
            self::Fulfilled => 'green',
            self::Cancelled, self::Expired => 'zinc',
        };
    }
}
