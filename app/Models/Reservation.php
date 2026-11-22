<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Models;

use App\Enums\ReservationStatus;
use Database\Factories\ReservationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Review fields (status, reviewed_by, reviewed_at, expires_at, remarks) are
 * not fillable: only the reservation Actions set them, never request input.
 *
 * See ReservationStatus for the full lifecycle. An approved reservation is a
 * hold on the book until expires_at; after that it no longer counts, even
 * before ExpireStaleReservationsAction has marked it "expired".
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $book_id
 * @property ReservationStatus $status
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 * @property Carbon|null $expires_at
 * @property string|null $remarks
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $user
 * @property-read Book $book
 * @property-read User|null $reviewer
 */
#[Fillable(['user_id', 'book_id'])]
class Reservation extends Model
{
    /** @use HasFactory<ReservationFactory> */
    use HasFactory;

    /**
     * How long an approved reservation holds the book for its student.
     */
    public const HOLD_DAYS = 3;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
    ];

    /**
     * The student who requested the reservation. Null when that account was
     * later deleted; the reservation itself is kept as history.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The catalogue book being reserved.
     *
     * @return BelongsTo<Book, $this>
     */
    public function book(): BelongsTo
    {
        return $this->belongsTo(Book::class);
    }

    /**
     * The staff member who approved or rejected the reservation.
     *
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pending(Builder $query): void
    {
        $query->where('status', ReservationStatus::Pending);
    }

    /**
     * Approved reservations whose hold has not yet run out.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function activeHold(Builder $query): void
    {
        $query->where('status', ReservationStatus::Approved)->where('expires_at', '>', now());
    }

    /**
     * Approved reservations whose hold has run out but are not yet marked.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function staleHold(Builder $query): void
    {
        $query->where('status', ReservationStatus::Approved)->where('expires_at', '<=', now());
    }

    /**
     * Pending requests and unexpired holds.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where(fn (Builder $query) => $query->pending()->orWhere(fn (Builder $query) => $query->activeHold()));
    }

    /**
     * The status to show: an approved hold past its deadline reads "Expired"
     * even before it has been marked as such.
     */
    public function effectiveStatus(): ReservationStatus
    {
        return $this->status === ReservationStatus::Approved && $this->expires_at?->isPast()
            ? ReservationStatus::Expired
            : $this->status;
    }

    public function isPending(): bool
    {
        return $this->status === ReservationStatus::Pending;
    }

    public function isActiveHold(): bool
    {
        return $this->effectiveStatus() === ReservationStatus::Approved;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ReservationStatus::class,
            'reviewed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }
}
