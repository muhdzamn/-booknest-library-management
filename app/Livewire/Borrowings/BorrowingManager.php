<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Livewire\Borrowings;

use App\Actions\IssueBookAction;
use App\Actions\ReturnBookAction;
use App\Concerns\InteractsWithAlerts;
use App\Enums\UserRole;
use App\Exceptions\BorrowingException;
use App\Livewire\Forms\BorrowingForm;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\User;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Circulation desk (/borrowings).
 *
 * Staff (BorrowingPolicy::viewAny) see and manage every loan. Students see a
 * read-only list scoped to their own loans; the scope is applied in the
 * query, so other students' records are never loaded.
 *
 * @property-read bool $canViewAll
 * @property-read LengthAwarePaginator<int, Borrowing> $borrowings
 * @property-read array{active: int, overdue: int, returned: int} $stats
 * @property-read Collection<int, User> $students
 * @property-read Collection<int, Book> $availableBooks
 * @property-read Borrowing|null $returningBorrowing
 */
#[Title('Borrowings')]
class BorrowingManager extends Component
{
    use InteractsWithAlerts, WithPagination;

    /**
     * @var list<string>
     */
    public const STATUS_FILTERS = ['active', 'overdue', 'returned'];

    public const PER_PAGE = 10;

    public BorrowingForm $form;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Locked]
    public ?int $returningBorrowingId = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        if (! in_array($this->status, self::STATUS_FILTERS, true)) {
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
        return Gate::allows('viewAny', Borrowing::class);
    }

    /**
     * Loans visible to the current user: all of them for staff, otherwise
     * only the user's own.
     *
     * @return Builder<Borrowing>
     */
    private function visibleBorrowings(): Builder
    {
        return Borrowing::query()
            ->unless($this->canViewAll, fn (Builder $query) => $query->where('user_id', Auth::id()));
    }

    /**
     * @return LengthAwarePaginator<int, Borrowing>
     */
    #[Computed]
    public function borrowings(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return $this->visibleBorrowings()
            ->with(['user:id,name,email', 'book:id,title,author'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query->whereHas('book', fn (Builder $book) => $book->where('title', 'like', $term)->orWhere('author', 'like', $term));

                    if ($this->canViewAll) {
                        $query->orWhereHas('user', fn (Builder $user) => $user->where('name', 'like', $term)->orWhere('email', 'like', $term));
                    }
                });
            })
            ->when($this->status === 'active', fn (Builder $query) => $query->active())
            ->when($this->status === 'overdue', fn (Builder $query) => $query->overdue())
            ->when($this->status === 'returned', fn (Builder $query) => $query->returned())
            ->orderByRaw('returned_at IS NOT NULL')
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);
    }

    /**
     * @return array{active: int, overdue: int, returned: int}
     */
    #[Computed]
    public function stats(): array
    {
        return [
            'active' => $this->visibleBorrowings()->active()->count(),
            'overdue' => $this->visibleBorrowings()->overdue()->count(),
            'returned' => $this->visibleBorrowings()->returned()->count(),
        ];
    }

    /**
     * @return Collection<int, User>
     */
    #[Computed]
    public function students(): Collection
    {
        return User::where('role', UserRole::Student)->orderBy('name')->get(['id', 'name', 'email']);
    }

    /**
     * Books that may be issued right now: no open loan, and not on hold for
     * a different student than the one selected in the issue form.
     *
     * @return Collection<int, Book>
     */
    #[Computed]
    public function availableBooks(): Collection
    {
        $studentId = filled($this->form->user_id) ? (int) $this->form->user_id : null;

        return Book::query()
            ->when(
                $studentId,
                fn (Builder $query) => $query->availableFor($studentId),
                fn (Builder $query) => $query->available(),
            )
            ->with('activeHold:id,book_id,user_id')
            ->orderBy('title')
            ->get(['id', 'title', 'author']);
    }

    #[Computed]
    public function returningBorrowing(): ?Borrowing
    {
        return $this->returningBorrowingId === null
            ? null
            : Borrowing::with(['user:id,name', 'book:id,title'])->find($this->returningBorrowingId);
    }

    /**
     * Open the issue form.
     */
    public function openIssueForm(): void
    {
        Gate::authorize('create', Borrowing::class);

        $this->form->clear();

        Flux::modal('issue-book')->show();
    }

    /**
     * Issue the chosen book to the chosen student.
     */
    public function issue(IssueBookAction $issueBook): void
    {
        Gate::authorize('create', Borrowing::class);

        $attributes = $this->form->validatedAttributes();

        try {
            $borrowing = $issueBook->handle(
                Auth::user(),
                User::findOrFail($attributes['user_id']),
                Book::findOrFail($attributes['book_id']),
                $attributes['due_at'],
            );
        } catch (BorrowingException $exception) {
            $this->addError('form.'.($exception->field ?? 'book_id'), $exception->getMessage());
            unset($this->availableBooks);

            return;
        }

        $borrowing->load(['user:id,name', 'book:id,title']);

        $this->form->clear();
        unset($this->borrowings, $this->stats, $this->availableBooks);

        Flux::modal('issue-book')->close();

        $this->flash('success', "\"{$borrowing->book->title}\" was issued to {$borrowing->user?->name}, due {$borrowing->due_at->format('j M Y')}.");
    }

    /**
     * Ask for confirmation before recording a return.
     */
    public function confirmReturn(int $borrowingId): void
    {
        $borrowing = Borrowing::findOrFail($borrowingId);

        $response = Gate::inspect('return', $borrowing);

        if ($response->denied()) {
            $this->flash('danger', $response->message() ?? 'You cannot record this return.');

            return;
        }

        $this->returningBorrowingId = $borrowingId;
        unset($this->returningBorrowing);

        Flux::modal('confirm-return')->show();
    }

    /**
     * Record the confirmed return through the Action.
     */
    public function returnBook(ReturnBookAction $returnBook): void
    {
        $borrowing = $this->returningBorrowingId === null ? null : Borrowing::find($this->returningBorrowingId);

        $this->returningBorrowingId = null;
        Flux::modal('confirm-return')->close();

        if ($borrowing === null) {
            $this->flash('danger', 'That loan no longer exists.');

            return;
        }

        try {
            $returned = $returnBook->handle(Auth::user(), $borrowing);
        } catch (AuthorizationException|BorrowingException $exception) {
            $this->flash('danger', $exception->getMessage());

            return;
        }

        $returned->load('book:id,title');

        unset($this->borrowings, $this->stats, $this->availableBooks);

        $lateNote = $returned->returned_at->greaterThan($returned->due_at) ? ' It was returned late.' : '';

        $this->flash('success', "\"{$returned->book->title}\" was returned.{$lateNote}");
    }

    public function render(): View
    {
        return view('livewire.borrowings.borrowing-manager');
    }
}
