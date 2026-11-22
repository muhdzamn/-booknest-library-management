<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Livewire\Forms;

use App\Enums\UserRole;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Holds and validates the "issue a book" fields. Availability and the
 * borrower's role are re-checked authoritatively by IssueBookAction.
 */
class BorrowingForm extends Form
{
    public const DEFAULT_LOAN_DAYS = 14;

    public const MAX_LOAN_DAYS = 60;

    /**
     * Select inputs arrive as strings, and Livewire turns an emptied field
     * into null, so these accept string, number or null.
     */
    public string|int|null $user_id = '';

    public string|int|null $book_id = '';

    /**
     * Due date as Y-m-d (from <input type="date">).
     */
    public ?string $due_at = '';

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', Rule::exists('users', 'id')->where('role', UserRole::Student->value)],
            'book_id' => ['required', 'integer', Rule::exists('books', 'id')],
            'due_at' => [
                'required',
                'date_format:Y-m-d',
                'after:today',
                'before_or_equal:'.now()->addDays(self::MAX_LOAN_DAYS)->toDateString(),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'user_id.exists' => 'Please choose a registered student.',
            'due_at.after' => 'The due date must be after today.',
            'due_at.before_or_equal' => 'Loans can be at most '.self::MAX_LOAN_DAYS.' days long.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'user_id' => 'student',
            'book_id' => 'book',
            'due_at' => 'due date',
        ];
    }

    /**
     * Reset to a blank form with the standard loan period pre-filled.
     */
    public function clear(): void
    {
        $this->reset();
        $this->resetErrorBag();
        $this->due_at = now()->addDays(self::DEFAULT_LOAN_DAYS)->toDateString();
    }

    /**
     * @return array{user_id: int, book_id: int, due_at: CarbonImmutable}
     */
    public function validatedAttributes(): array
    {
        $validated = $this->validate();

        return [
            'user_id' => (int) $validated['user_id'],
            'book_id' => (int) $validated['book_id'],
            'due_at' => CarbonImmutable::createFromFormat('Y-m-d', $validated['due_at'])->endOfDay(),
        ];
    }
}
