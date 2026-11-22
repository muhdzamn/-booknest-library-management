<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Livewire;

use App\Enums\UserRole;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\Category;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * BookNest dashboard (/dashboard). Read-only.
 *
 * Every figure comes from the same model scopes the modules use
 * (Book::available, Borrowing::active/overdue/returned, Reservation::
 * pending/activeHold), so the dashboard always agrees with them.
 *
 * Staff see library-wide figures and activity. Students see the public
 * catalogue figures plus only their own borrowings and reservations: their
 * queries are scoped to their user id, so no other student's data is loaded.
 *
 * @property-read bool $isStaff
 * @property-read array{books: int, categories: int, available: int} $catalogueStats
 * @property-read array{active: int, overdue: int, returned: int, pending: int, onHold: int} $circulationStats
 * @property-read array<string, int> $accountStats
 * @property-read Collection<int, Borrowing> $recentBorrowings
 * @property-read Collection<int, Reservation> $recentReservations
 * @property-read Collection<int, Borrowing> $overdueBorrowings
 * @property-read Collection<int, Reservation> $myHolds
 */
#[Title('Dashboard')]
class Dashboard extends Component
{
    public const RECENT_LIMIT = 5;

    /**
     * Staff = may view every borrowing and reservation (the same policy
     * checks the Borrowings and Reservations pages use).
     */
    #[Computed]
    public function isStaff(): bool
    {
        return Gate::allows('viewAny', Borrowing::class) && Gate::allows('viewAny', Reservation::class);
    }

    /**
     * Catalogue figures. These are public information (the Assignment 1
     * catalogue at /books is public), so every role sees them.
     *
     * @return array{books: int, categories: int, available: int}
     */
    #[Computed]
    public function catalogueStats(): array
    {
        return [
            'books' => Book::count(),
            'categories' => Category::count(),
            'available' => Book::available()->count(),
        ];
    }

    /**
     * @return Builder<Borrowing>
     */
    private function visibleBorrowings(): Builder
    {
        return Borrowing::query()->unless($this->isStaff, fn (Builder $query) => $query->where('user_id', Auth::id()));
    }

    /**
     * @return Builder<Reservation>
     */
    private function visibleReservations(): Builder
    {
        return Reservation::query()->unless($this->isStaff, fn (Builder $query) => $query->where('user_id', Auth::id()));
    }

    /**
     * Library-wide for staff; the student's own for students.
     *
     * @return array{active: int, overdue: int, returned: int, pending: int, onHold: int}
     */
    #[Computed]
    public function circulationStats(): array
    {
        return [
            'active' => $this->visibleBorrowings()->active()->count(),
            'overdue' => $this->visibleBorrowings()->overdue()->count(),
            'returned' => $this->visibleBorrowings()->returned()->count(),
            'pending' => $this->visibleReservations()->pending()->count(),
            'onHold' => $this->visibleReservations()->activeHold()->count(),
        ];
    }

    /**
     * Account totals by role, for administrators only.
     *
     * @return array<string, int>
     */
    #[Computed]
    public function accountStats(): array
    {
        if (! Auth::user()->isAdmin()) {
            return [];
        }

        $counts = User::query()->selectRaw('role, count(*) as total')->groupBy('role')->pluck('total', 'role');

        return collect(UserRole::cases())
            ->mapWithKeys(fn (UserRole $role) => [$role->value => (int) ($counts[$role->value] ?? 0)])
            ->all();
    }

    /**
     * Latest loan activity (issued or returned), most recent first.
     *
     * @return Collection<int, Borrowing>
     */
    #[Computed]
    public function recentBorrowings(): Collection
    {
        return $this->visibleBorrowings()
            ->with(['book:id,title', 'user:id,name'])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * Latest reservation activity (requested or reviewed), most recent first.
     *
     * @return Collection<int, Reservation>
     */
    #[Computed]
    public function recentReservations(): Collection
    {
        return $this->visibleReservations()
            ->with(['book:id,title', 'user:id,name'])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * Most overdue loans first; staff "needs attention" list.
     *
     * @return Collection<int, Borrowing>
     */
    #[Computed]
    public function overdueBorrowings(): Collection
    {
        if (! $this->isStaff) {
            return new Collection;
        }

        return Borrowing::overdue()
            ->with(['book:id,title', 'user:id,name'])
            ->orderBy('due_at')
            ->limit(self::RECENT_LIMIT)
            ->get();
    }

    /**
     * The student's books on hold, to collect before the deadline.
     *
     * @return Collection<int, Reservation>
     */
    #[Computed]
    public function myHolds(): Collection
    {
        return Reservation::where('user_id', Auth::id())
            ->activeHold()
            ->with('book:id,title')
            ->orderBy('expires_at')
            ->get();
    }

    public function render(): View
    {
        return view('livewire.dashboard');
    }
}
