<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace Database\Factories;

use App\Enums\ReservationStatus;
use App\Models\Book;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Reservation>
 */
class ReservationFactory extends Factory
{
    /**
     * Define the model's default state: a pending request.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'book_id' => Book::factory(),
            'status' => ReservationStatus::Pending,
            'reviewed_by' => null,
            'reviewed_at' => null,
            'remarks' => null,
        ];
    }

    /**
     * Indicate that a librarian has approved the reservation, placing an
     * active hold on the book.
     */
    public function approved(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReservationStatus::Approved,
            'reviewed_by' => User::factory()->librarian(),
            'reviewed_at' => now(),
            'expires_at' => now()->addDays(Reservation::HOLD_DAYS)->endOfDay(),
        ]);
    }

    /**
     * Indicate that the reservation was approved but its hold has run out
     * (still marked "approved", as before ExpireStaleReservationsAction runs).
     */
    public function lapsed(): static
    {
        return $this->approved()->state(fn (array $attributes) => [
            'reviewed_at' => now()->subDays(Reservation::HOLD_DAYS + 2),
            'expires_at' => now()->subDay(),
        ]);
    }

    /**
     * Indicate that a librarian has rejected the reservation.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => ReservationStatus::Rejected,
            'reviewed_by' => User::factory()->librarian(),
            'reviewed_at' => now(),
            'remarks' => 'Not available this semester.',
        ]);
    }
}
