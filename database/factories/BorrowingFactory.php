<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace Database\Factories;

use App\Enums\BorrowingStatus;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Borrowing>
 */
class BorrowingFactory extends Factory
{
    /**
     * Define the model's default state: an active 14-day loan.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $borrowedAt = now()->subDays(fake()->numberBetween(0, 10));

        return [
            'user_id' => User::factory(),
            'book_id' => Book::factory(),
            'borrowed_at' => $borrowedAt,
            'due_at' => $borrowedAt->addDays(14),
            'returned_at' => null,
            'status' => BorrowingStatus::Borrowed,
        ];
    }

    /**
     * Indicate that the book has been returned.
     */
    public function returned(): static
    {
        return $this->state(fn (array $attributes) => [
            'returned_at' => now(),
            'status' => BorrowingStatus::Returned,
        ]);
    }
}
