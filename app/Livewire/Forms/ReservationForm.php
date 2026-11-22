<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Livewire\Forms;

use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Fields for the two reservation forms: a student's new request (book_id)
 * and a staff review (decision + remarks). Business rules are re-checked
 * by the reservation Actions.
 */
class ReservationForm extends Form
{
    public const DECISIONS = ['approve', 'reject'];

    /**
     * Select inputs arrive as strings; Livewire turns an emptied one into null.
     */
    public string|int|null $book_id = '';

    public string $decision = 'approve';

    public ?string $remarks = '';

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['book_id' => 'book'];
    }

    /**
     * Validate a new reservation request and return the chosen book id.
     */
    public function validatedBookId(): int
    {
        $validated = $this->validate([
            'book_id' => ['required', 'integer', Rule::exists('books', 'id')],
        ], [
            'book_id.exists' => 'The selected book does not exist.',
        ]);

        return (int) $validated['book_id'];
    }

    /**
     * Validate a review. Remarks are optional when approving and required
     * (as the reason shown to the student) when rejecting.
     *
     * @return array{decision: 'approve'|'reject', remarks: string|null}
     */
    public function validatedReview(): array
    {
        $validated = $this->validate([
            'decision' => ['required', Rule::in(self::DECISIONS)],
            'remarks' => ['nullable', 'string', 'max:500', Rule::requiredIf($this->decision === 'reject')],
        ], [
            'remarks.required' => 'Please give a reason for rejecting this reservation.',
        ]);

        return [
            'decision' => $validated['decision'],
            'remarks' => filled($validated['remarks'] ?? null) ? trim($validated['remarks']) : null,
        ];
    }

    public function clear(): void
    {
        $this->reset();
        $this->resetErrorBag();
    }
}
