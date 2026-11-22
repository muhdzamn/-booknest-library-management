<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Livewire\Reservations;

use App\Actions\ApproveReservationAction;
use App\Actions\CancelReservationAction;
use App\Actions\CreateReservationAction;
use App\Actions\RejectReservationAction;
use App\Concerns\InteractsWithAlerts;
use App\Enums\ReservationStatus;
use App\Exceptions\ReservationException;
use App\Livewire\Forms\ReservationForm;
use App\Models\Book;
use App\Models\Reservation;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Reservations page (/reservations).
 *
 * Staff (ReservationPolicy::viewAny) see every reservation and review
 * pending ones. Students request reservations and see only their own; the
 * scope is applied in the query, so other students' rows are never loaded.
 *
 * @property-read bool $canViewAll
 * @property-read LengthAwarePaginator<int, Reservation> $reservations
 * @property-read array{pending: int, approved: int, rejected: int, fulfilled: int} $stats
 * @property-read Collection<int, Book> $reservableBooks
 * @property-read Reservation|null $reviewingReservation
 * @property-read Reservation|null $cancellingReservation
 */
#[Title('Reservations')]
class ReservationManager extends Component
{
    use InteractsWithAlerts, WithPagination;

    public const PER_PAGE = 10;

    public ReservationForm $form;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Locked]
    public ?int $reviewingReservationId = null;

    #[Locked]
    public ?int $cancellingReservationId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        if (ReservationStatus::tryFrom($this->status) === null) {
            $this->status = '';
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'status');
        $this->resetPage();
    }

    #[Computed]
    public function canViewAll(): bool
    {
        return Gate::allows('viewAny', Reservation::class);
    }

    /**
     * Reservations visible to the current user: all for staff, otherwise
     * only the user's own.
     *
     * @return Builder<Reservation>
     */
    private function visibleReservations(): Builder
    {
        return Reservation::query()
            ->unless($this->canViewAll, fn (Builder $query) => $query->where('user_id', Auth::id()));
    }

    /**
     * Apply a status filter. "approved" means an unexpired hold, and
     * "expired" includes approved holds whose deadline has passed but which
     * have not been marked yet, so the list always matches what is shown.
     *
     * @param  Builder<Reservation>  $query
     */
    private function applyStatusFilter(Builder $query, ReservationStatus $status): void
    {
        match ($status) {
            ReservationStatus::Approved => $query->activeHold(),
            ReservationStatus::Expired => $query->where(fn (Builder $query) => $query
                ->where('status', ReservationStatus::Expired)
                ->orWhere(fn (Builder $query) => $query->staleHold())),
            default => $query->where('status', $status),
        };
    }

    /**
     * @return LengthAwarePaginator<int, Reservation>
     */
    #[Computed]
    public function reservations(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $status = ReservationStatus::tryFrom($this->status);

        return $this->visibleReservations()
            ->with(['user:id,name,email', 'book:id,title,author', 'reviewer:id,name'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query->whereHas('book', fn (Builder $book) => $book->where('title', 'like', $term)->orWhere('author', 'like', $term));

                    if ($this->canViewAll) {
                        $query->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term));
                    }
                });
            })
            ->when($status, fn (Builder $query) => $this->applyStatusFilter($query, $status))
            ->orderByRaw("CASE WHEN status = 'pending' THEN 0 ELSE 1 END")
            ->latest('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @return array{pending: int, approved: int, rejected: int, fulfilled: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'pending' => $this->visibleReservations()->pending()->count(),
            'approved' => $this->visibleReservations()->activeHold()->count(),
            'rejected' => $this->visibleReservations()->where('status', ReservationStatus::Rejected)->count(),
            'fulfilled' => $this->visibleReservations()->where('status', ReservationStatus::Fulfilled)->count(),
        ];
    }

    /**
     * Books the current student can still request: every book except those
     * they already have an active reservation for, with its current state.
     *
     * @return Collection<int, Book>
     */
    #[Computed]
    public function reservableBooks(): Collection
    {
        return Book::query()
            ->whereDoesntHave('reservations', fn (Builder $query) => $query->where('user_id', Auth::id())->active())
            ->withCirculationState()
            ->orderBy('title')
            ->get(['id', 'title', 'author']);
    }

    #[Computed]
    public function reviewingReservation(): ?Reservation
    {
        if ($this->reviewingReservationId === null) {
            return null;
        }

        return Reservation::with([
            'user:id,name,email',
            'book' => fn ($query) => $query->select(['id', 'title', 'author'])->withCirculationState(),
        ])->find($this->reviewingReservationId);
    }

    #[Computed]
    public function cancellingReservation(): ?Reservation
    {
        return $this->cancellingReservationId === null
            ? null
            : Reservation::with('book:id,title')->find($this->cancellingReservationId);
    }

    // --- Student: new reservation -------------------------------------------

    public function openRequestForm(): void
    {
        Gate::authorize('create', Reservation::class);

        $this->form->clear();

        Flux::modal('new-reservation')->show();
    }

    public function submitRequest(CreateReservationAction $createReservation): void
    {
        Gate::authorize('create', Reservation::class);

        $bookId = $this->form->validatedBookId();

        try {
            $reservation = $createReservation->handle(Auth::user(), $bookId);
        } catch (ReservationException $exception) {
            $this->addError('form.'.($exception->field ?? 'book_id'), $exception->getMessage());

            return;
        } catch (ModelNotFoundException) {
            $this->addError('form.book_id', 'The selected book does not exist.');

            return;
        }

        $this->form->clear();
        unset($this->reservations, $this->stats, $this->reservableBooks);

        Flux::modal('new-reservation')->close();

        $this->flash('success', "Your reservation for \"{$reservation->book->title}\" was submitted and is awaiting review.");
    }

    // --- Staff: review -------------------------------------------------------

    /**
     * Open the review modal with a pre-selected decision.
     */
    public function openReview(int $reservationId, string $decision = 'approve'): void
    {
        $reservation = Reservation::findOrFail($reservationId);

        $response = Gate::inspect('review', $reservation);

        if ($response->denied()) {
            $this->flash('danger', $response->message() ?? 'You cannot review this reservation.');

            return;
        }

        $this->form->clear();
        $this->form->decision = in_array($decision, ReservationForm::DECISIONS, true) ? $decision : 'approve';
        $this->reviewingReservationId = $reservationId;
        unset($this->reviewingReservation);

        Flux::modal('review-reservation')->show();
    }

    public function submitReview(ApproveReservationAction $approveReservation, RejectReservationAction $rejectReservation): void
    {
        $reservation = $this->reviewingReservationId === null ? null : Reservation::find($this->reviewingReservationId);

        if ($reservation === null) {
            Flux::modal('review-reservation')->close();
            $this->flash('danger', 'That reservation no longer exists.');

            return;
        }

        $review = $this->form->validatedReview();

        try {
            $reviewed = $review['decision'] === 'approve'
                ? $approveReservation->handle(Auth::user(), $reservation, $review['remarks'])
                : $rejectReservation->handle(Auth::user(), $reservation, (string) $review['remarks']);
        } catch (ReservationException $exception) {
            if ($exception->field !== null) {
                $this->addError('form.'.$exception->field, $exception->getMessage());

                return;
            }

            $this->closeReview();
            $this->flash('danger', $exception->getMessage());

            return;
        } catch (AuthorizationException $exception) {
            $this->closeReview();
            $this->flash('danger', $exception->getMessage());

            return;
        }

        $reviewed->load(['book:id,title', 'user:id,name']);
        $this->closeReview();

        $student = $reviewed->user->name ?? 'the student';

        $this->flash('success', $reviewed->status === ReservationStatus::Approved
            ? "Approved: \"{$reviewed->book->title}\" is on hold for {$student} until {$reviewed->expires_at?->format('j M Y')}."
            : "Rejected the reservation for \"{$reviewed->book->title}\".");
    }

    private function closeReview(): void
    {
        $this->reviewingReservationId = null;
        $this->form->clear();
        unset($this->reservations, $this->stats);

        Flux::modal('review-reservation')->close();
    }

    // --- Cancel --------------------------------------------------------------

    public function confirmCancel(int $reservationId): void
    {
        $reservation = Reservation::findOrFail($reservationId);

        $response = Gate::inspect('cancel', $reservation);

        if ($response->denied()) {
            $this->flash('danger', $response->message() ?? 'You cannot cancel this reservation.');

            return;
        }

        $this->cancellingReservationId = $reservationId;
        unset($this->cancellingReservation);

        Flux::modal('confirm-cancel')->show();
    }

    public function cancelReservation(CancelReservationAction $cancelReservation): void
    {
        $reservation = $this->cancellingReservationId === null ? null : Reservation::find($this->cancellingReservationId);

        $this->cancellingReservationId = null;
        Flux::modal('confirm-cancel')->close();

        if ($reservation === null) {
            $this->flash('danger', 'That reservation no longer exists.');

            return;
        }

        try {
            $cancelled = $cancelReservation->handle(Auth::user(), $reservation);
        } catch (AuthorizationException|ReservationException $exception) {
            $this->flash('danger', $exception->getMessage());

            return;
        }

        $cancelled->load('book:id,title');
        unset($this->reservations, $this->stats, $this->reservableBooks);

        $this->flash('success', "The reservation for \"{$cancelled->book->title}\" was cancelled.");
    }

    public function render(): View
    {
        return view('livewire.reservations.reservation-manager');
    }
}
