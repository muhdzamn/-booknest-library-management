<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Models;

use App\Enums\BorrowingStatus;
use Database\Factories\BorrowingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $user_id
 * @property int $book_id
 * @property Carbon $borrowed_at
 * @property Carbon $due_at
 * @property Carbon|null $returned_at
 * @property BorrowingStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Book $book
 */
#[Fillable(['user_id', 'book_id', 'borrowed_at', 'due_at', 'returned_at', 'status'])]
class Borrowing extends Model
{
    /** @use HasFactory<BorrowingFactory> */
    use HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'borrowed',
    ];

    /**
     * The student who borrowed the book. Null when that account was later
     * deleted; the loan itself is kept for auditing.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Loans that are still open. returned_at is the source of truth; it is
     * also what the one-active-loan-per-book database index is built on.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereNull('returned_at');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function returned(Builder $query): void
    {
        $query->whereNotNull('returned_at');
    }

    /**
     * Open loans whose due date has passed.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function overdue(Builder $query): void
    {
        $query->whereNull('returned_at')->where('due_at', '<', now());
    }

    public function isReturned(): bool
    {
        return $this->returned_at !== null;
    }

    /**
     * Overdue is derived, never stored, so it cannot drift from the dates.
     */
    public function isOverdue(): bool
    {
        return ! $this->isReturned() && $this->due_at->isPast();
    }

    /**
     * Whole days past the due date for an open loan, otherwise 0.
     */
    public function daysOverdue(): int
    {
        return $this->isOverdue() ? (int) $this->due_at->startOfDay()->diffInDays(now()->startOfDay()) : 0;
    }

    /**
     * The catalogue book that was borrowed.
     *
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'borrowed_at' => 'datetime',
            'due_at' => 'datetime',
            'returned_at' => 'datetime',
            'status' => BorrowingStatus::class,
        ];
    }
}
