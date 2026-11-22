<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Models;

use Database\Factories\BookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A book is a catalogue record (a title), not a physical copy on the shelf.
 *
 * The Assignment 1 "author" string column is kept as the display value used by
 * the original CRUD screens. The structured author lives in authorProfile(),
 * because a relation named author() would be shadowed by that attribute.
 *
 * @property int $id
 * @property int $category_id
 * @property int|null $author_id
 * @property int|null $publisher_id
 * @property string $title
 * @property string $author
 * @property int $year_published
 * @property string $price
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Category $category
 * @property-read Author|null $authorProfile
 * @property-read Publisher|null $publisher
 * @property-read Collection<int, Borrowing> $borrowings
 * @property-read Borrowing|null $activeBorrowing
 * @property-read Reservation|null $activeHold
 * @property-read Collection<int, Reservation> $reservations
 * @property-read int|null $borrowings_count
 * @property-read int|null $reservations_count
 */
#[Fillable(['category_id', 'author_id', 'publisher_id', 'title', 'author', 'year_published', 'price'])]
class Book extends Model
{
    /** @use HasFactory<BookFactory> */
    use HasFactory;

    /**
     * Each book belongs to one category.
     *
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * The structured author record, when one has been linked.
     *
     * @return BelongsTo<Author, $this>
     */
    public function authorProfile(): BelongsTo
    {
        return $this->belongsTo(Author::class, 'author_id');
    }

    /**
     * The publisher record, when one has been linked.
     *
     * @return BelongsTo<Publisher, $this>
     */
    public function publisher(): BelongsTo
    {
        return $this->belongsTo(Publisher::class);
    }

    /**
     * Every loan recorded against this title.
     *
     * @return HasMany<Borrowing, $this>
     */
    public function borrowings(): HasMany
    {
        return $this->hasMany(Borrowing::class);
    }

    /**
     * The open loan for this title, if it is currently borrowed.
     *
     * @return HasOne<Borrowing, $this>
     */
    public function activeBorrowing(): HasOne
    {
        return $this->hasOne(Borrowing::class)->whereNull('returned_at');
    }

    /**
     * The unexpired approved reservation holding this book, if any.
     *
     * @return HasOne<Reservation, $this>
     */
    public function activeHold(): HasOne
    {
        return $this->hasOne(Reservation::class)->activeHold();
    }

    /**
     * Availability rule (no physical copies are modelled): each catalogue
     * book is one circulating copy. It is available to lend while it has no
     * open loan and no unexpired approved reservation (hold).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function available(Builder $query): void
    {
        $query->whereDoesntHave('borrowings', fn (Builder $loans) => $loans->whereNull('returned_at'))
            ->whereDoesntHave('reservations', fn (Builder $holds) => $holds->activeHold());
    }

    /**
     * Books that may be lent to the given student: no open loan, and either
     * no active hold or a hold that belongs to that student.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function availableFor(Builder $query, int $studentId): void
    {
        $query->whereDoesntHave('borrowings', fn (Builder $loans) => $loans->whereNull('returned_at'))
            ->whereDoesntHave('reservations', fn (Builder $holds) => $holds->activeHold()->where(
                fn (Builder $holder) => $holder->whereNull('user_id')->orWhere('user_id', '!=', $studentId)
            ));
    }

    /**
     * Add is_on_loan and is_on_hold flags for display (one query, no N+1).
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function withCirculationState(Builder $query): void
    {
        $query->withExists(['activeBorrowing as is_on_loan', 'activeHold as is_on_hold']);
    }

    public function hasOpenLoan(): bool
    {
        return $this->borrowings()->whereNull('returned_at')->exists();
    }

    /**
     * Available to anyone: no open loan and no active hold.
     */
    public function isAvailable(): bool
    {
        return ! $this->hasOpenLoan() && ! $this->reservations()->activeHold()->exists();
    }

    /**
     * Every reservation request made for this title.
     *
     * @return HasMany<Reservation, $this>
     */
    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    /**
     * Whether any borrowing or reservation has ever referenced this book.
     *
     * Uses withCount() results when they were loaded (list screens), so the
     * check costs no extra queries per row; otherwise it queries directly.
     */
    public function hasCirculationHistory(): bool
    {
        if (isset($this->attributes['borrowings_count'], $this->attributes['reservations_count'])) {
            return $this->attributes['borrowings_count'] + $this->attributes['reservations_count'] > 0;
        }

        return $this->borrowings()->exists() || $this->reservations()->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year_published' => 'integer',
            'price' => 'decimal:2',
        ];
    }
}
