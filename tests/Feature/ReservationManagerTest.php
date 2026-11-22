<?php

use App\Actions\ApproveReservationAction;
use App\Actions\CancelReservationAction;
use App\Actions\CreateReservationAction;
use App\Actions\ExpireStaleReservationsAction;
use App\Actions\IssueBookAction;
use App\Actions\RejectReservationAction;
use App\Actions\ReturnBookAction;
use App\Enums\ReservationStatus;
use App\Exceptions\BorrowingException;
use App\Exceptions\ReservationException;
use App\Livewire\Borrowings\BorrowingManager;
use App\Livewire\Reservations\ReservationManager;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * IDs of the reservations in the table (dropdowns in the modals also
 * contain book titles, so assertSee() on the page is ambiguous).
 *
 * @return list<int>
 */
function listedReservationIds(Testable $component): array
{
    return $component->instance()->reservations->pluck('id')->sort()->values()->all();
}

dataset('staff', [
    'admin' => [fn () => User::factory()->admin()->create()],
    'librarian' => [fn () => User::factory()->librarian()->create()],
]);

// --- Access -----------------------------------------------------------------

test('reservations page requires a signed-in, verified user', function () {
    $this->get(route('reservations.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('reservations.index'))
        ->assertRedirect(route('verification.notice'));

    expect(Route::getRoutes()->getByName('reservations.index')->gatherMiddleware())
        ->toContain('auth', 'verified');
});

test('students and staff can open the page and the sidebar links to it', function (Closure $makeUser, string $heading) {
    $this->actingAs($makeUser())
        ->get(route('reservations.index'))
        ->assertOk()
        ->assertSeeLivewire(ReservationManager::class)
        ->assertSee($heading)
        ->assertSee(route('reservations.index'));
})->with([
    'student' => [fn () => User::factory()->create(), 'My Reservations'],
    'librarian' => [fn () => User::factory()->librarian()->create(), 'Review reservation requests'],
]);

// --- Creating ---------------------------------------------------------------

test('students can reserve a book, including one that is on loan', function () {
    $student = User::factory()->create();
    $onLoan = Borrowing::factory()->create()->book;

    Livewire::actingAs($student)
        ->test(ReservationManager::class)
        ->call('openRequestForm')
        ->set('form.book_id', $onLoan->id)
        ->call('submitRequest')
        ->assertHasNoErrors()
        ->assertSet('alert.variant', 'success')
        ->assertSee('is awaiting review');

    expect(Reservation::sole())
        ->user_id->toBe($student->id)
        ->book_id->toBe($onLoan->id)
        ->status->toBe(ReservationStatus::Pending)
        ->reviewed_by->toBeNull()
        ->created_at->not->toBeNull();
});

test('a student cannot hold two active reservations for the same book', function (Closure $makeExisting) {
    $student = User::factory()->create();
    $book = Book::factory()->create();
    $makeExisting($student, $book);

    Livewire::actingAs($student)
        ->test(ReservationManager::class)
        ->set('form.book_id', $book->id)
        ->call('submitRequest')
        ->assertHasErrors(['form.book_id'])
        ->assertSee('You already have an active reservation');

    expect($student->reservations()->active()->count())->toBe(1);
})->with([
    'pending' => [fn (User $student, Book $book) => Reservation::factory()->for($student)->for($book)->create()],
    'approved hold' => [fn (User $student, Book $book) => Reservation::factory()->for($student)->for($book)->approved()->create()],
]);

test('a student may reserve the same book again once the earlier one is closed', function () {
    $student = User::factory()->create();
    $book = Book::factory()->create();
    Reservation::factory()->for($student)->for($book)->rejected()->create();
    $lapsed = Reservation::factory()->for($student)->for($book)->lapsed()->create();

    app(CreateReservationAction::class)->handle($student, $book->id);

    expect($book->reservations()->count())->toBe(3)
        ->and($lapsed->fresh()->status)->toBe(ReservationStatus::Expired);
});

test('the database refuses a duplicate active reservation', function () {
    $existing = Reservation::factory()->create();

    expect(fn () => Reservation::factory()->for($existing->user)->for($existing->book)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a student cannot reserve a book they already have on loan', function () {
    $loan = Borrowing::factory()->create();

    expect(fn () => app(CreateReservationAction::class)->handle($loan->user, $loan->book_id))
        ->toThrow(ReservationException::class, 'You already have');
});

test('reservation requests must name an existing book', function (string|int $bookId, string $rule) {
    Livewire::actingAs(User::factory()->create())
        ->test(ReservationManager::class)
        ->set('form.book_id', $bookId)
        ->call('submitRequest')
        ->assertHasErrors(['form.book_id' => $rule]);

    expect(Reservation::count())->toBe(0);
})->with([
    'missing' => ['', 'required'],
    'unknown id' => [99999, 'exists'],
    'not a number' => ['abc', 'integer'],
]);

test('staff cannot create reservations', function () {
    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(ReservationManager::class)
        ->assertDontSee('New Reservation')
        ->call('openRequestForm')
        ->assertForbidden();

    expect(fn () => app(CreateReservationAction::class)->handle(User::factory()->admin()->create(), Book::factory()->create()->id))
        ->toThrow(AuthorizationException::class);
});

// --- Reviewing --------------------------------------------------------------

test('staff can approve a pending reservation, placing a hold', function (Closure $makeStaff) {
    $this->freezeSecond();
    $staff = $makeStaff();
    $reservation = Reservation::factory()->create();

    Livewire::actingAs($staff)
        ->test(ReservationManager::class)
        ->call('openReview', $reservation->id, 'approve')
        ->assertSet('reviewingReservationId', $reservation->id)
        ->assertSet('form.decision', 'approve')
        ->set('form.remarks', 'Ready at the front desk.')
        ->call('submitReview')
        ->assertHasNoErrors()
        ->assertSet('alert.variant', 'success')
        ->assertSee('is on hold for');

    $reservation->refresh();

    expect($reservation)
        ->status->toBe(ReservationStatus::Approved)
        ->reviewed_by->toBe($staff->id)
        ->remarks->toBe('Ready at the front desk.')
        ->and($reservation->reviewed_at->equalTo(now()))->toBeTrue()
        ->and($reservation->expires_at->toDateTimeString())->toBe(now()->addDays(Reservation::HOLD_DAYS)->endOfDay()->toDateTimeString())
        ->and(Borrowing::count())->toBe(0);
})->with('staff');

test('staff can reject a pending reservation with a reason', function (Closure $makeStaff) {
    $staff = $makeStaff();
    $reservation = Reservation::factory()->create();

    Livewire::actingAs($staff)
        ->test(ReservationManager::class)
        ->call('openReview', $reservation->id, 'reject')
        ->set('form.remarks', 'Reference copy only.')
        ->call('submitReview')
        ->assertHasNoErrors()
        ->assertSee('Rejected the reservation');

    expect($reservation->fresh())
        ->status->toBe(ReservationStatus::Rejected)
        ->reviewed_by->toBe($staff->id)
        ->remarks->toBe('Reference copy only.');
})->with('staff');

test('rejecting requires a reason', function () {
    $reservation = Reservation::factory()->create();

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(ReservationManager::class)
        ->call('openReview', $reservation->id, 'reject')
        ->call('submitReview')
        ->assertHasErrors(['form.remarks' => 'required']);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
});

test('students cannot approve or reject reservations', function () {
    $student = User::factory()->create();
    $own = Reservation::factory()->for($student)->create();

    Livewire::actingAs($student)
        ->test(ReservationManager::class)
        ->assertDontSeeHtml('wire:click="openReview(')
        ->call('openReview', $own->id, 'approve')
        ->assertSet('reviewingReservationId', null)
        ->assertSee('Only librarians and administrators can review reservations.');

    expect(fn () => app(ApproveReservationAction::class)->handle($student, $own))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RejectReservationAction::class)->handle($student, $own, 'No'))->toThrow(AuthorizationException::class)
        ->and($own->fresh()->status)->toBe(ReservationStatus::Pending);
});

test('a reservation cannot be reviewed twice', function () {
    $librarian = User::factory()->librarian()->create();
    $reservation = Reservation::factory()->create();
    app(ApproveReservationAction::class)->handle($librarian, $reservation);
    $firstReview = $reservation->fresh();

    expect(fn () => app(ApproveReservationAction::class)->handle($librarian, $reservation))
        ->toThrow(ReservationException::class, 'already been approved')
        ->and(fn () => app(RejectReservationAction::class)->handle($librarian, $reservation, 'Changed my mind'))
        ->toThrow(ReservationException::class, 'already been approved');

    Livewire::actingAs($librarian)
        ->test(ReservationManager::class)
        ->call('openReview', $reservation->id)
        ->assertSet('reviewingReservationId', null)
        ->assertSee('This reservation has already been approved.');

    expect($reservation->fresh()->only(['status', 'reviewed_at', 'remarks']))->toEqual($firstReview->only(['status', 'reviewed_at', 'remarks']));
});

test('a review submitted after another librarian decided is refused', function () {
    $reservation = Reservation::factory()->create();

    $component = Livewire::actingAs(User::factory()->librarian()->create())
        ->test(ReservationManager::class)
        ->call('openReview', $reservation->id, 'approve');

    app(RejectReservationAction::class)->handle(User::factory()->admin()->create(), $reservation, 'Lost copy');

    $component->call('submitReview')
        ->assertSet('alert.variant', 'danger')
        ->assertSee('already been rejected');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Rejected);
});

test('approval rechecks that the book is available', function (Closure $makeBlocker, string $message) {
    $reservation = Reservation::factory()->create();
    $makeBlocker($reservation->book);

    expect(fn () => app(ApproveReservationAction::class)->handle(User::factory()->librarian()->create(), $reservation))
        ->toThrow(ReservationException::class, $message);

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
})->with([
    'on loan' => [fn (Book $book) => Borrowing::factory()->for($book)->create(), 'is currently on loan'],
    'held for another student' => [fn (Book $book) => Reservation::factory()->for($book)->approved()->create(), 'already on hold for another student'],
]);

test('a lapsed hold does not stop the next approval', function () {
    $lapsed = Reservation::factory()->lapsed()->create();
    $next = Reservation::factory()->for($lapsed->book)->create();

    app(ApproveReservationAction::class)->handle(User::factory()->librarian()->create(), $next);

    expect($next->fresh()->status)->toBe(ReservationStatus::Approved)
        ->and($lapsed->fresh()->status)->toBe(ReservationStatus::Expired);
});

// --- Visibility -------------------------------------------------------------

test('students see only their own reservations; staff see all', function () {
    $student = User::factory()->create();
    $own = Reservation::factory()->for($student)->create();
    $someoneElses = Reservation::factory()->create();

    expect(listedReservationIds(Livewire::actingAs($student)->test(ReservationManager::class)))->toBe([$own->id])
        ->and(listedReservationIds(Livewire::actingAs(User::factory()->librarian()->create())->test(ReservationManager::class)))
        ->toBe([$own->id, $someoneElses->id]);
});

test('a student cannot act on another student\'s reservation', function () {
    $intruder = User::factory()->create();
    $reservation = Reservation::factory()->create();

    Livewire::actingAs($intruder)
        ->test(ReservationManager::class)
        ->call('confirmCancel', $reservation->id)
        ->assertSet('cancellingReservationId', null)
        ->assertSee('You can only cancel your own reservations.');

    expect(fn () => app(CancelReservationAction::class)->handle($intruder, $reservation))
        ->toThrow(AuthorizationException::class)
        ->and($intruder->can('view', $reservation))->toBeFalse()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Pending);
});

test('students can cancel their own pending request or hold', function () {
    $student = User::factory()->create();
    $hold = Reservation::factory()->for($student)->approved()->create();

    Livewire::actingAs($student)
        ->test(ReservationManager::class)
        ->call('confirmCancel', $hold->id)
        ->assertSet('cancellingReservationId', $hold->id)
        ->call('cancelReservation')
        ->assertSet('alert.variant', 'success');

    expect($hold->fresh()->status)->toBe(ReservationStatus::Cancelled)
        ->and($hold->book->isAvailable())->toBeTrue();
});

test('search, status filter and pagination narrow the list', function () {
    $librarian = User::factory()->librarian()->create();
    $pending = Reservation::factory()->for(User::factory()->state(['name' => 'Siti Aminah']))->create();
    $approved = Reservation::factory()->approved()->create();
    $rejected = Reservation::factory()->rejected()->create();
    $lapsed = Reservation::factory()->lapsed()->create();

    $component = Livewire::actingAs($librarian)->test(ReservationManager::class)
        ->assertSet('stats', ['pending' => 1, 'approved' => 1, 'rejected' => 1, 'fulfilled' => 0]);

    expect(listedReservationIds($component->set('status', 'approved')))->toBe([$approved->id])
        ->and(listedReservationIds($component->set('status', 'expired')))->toBe([$lapsed->id])
        ->and(listedReservationIds($component->set('status', 'rejected')))->toBe([$rejected->id])
        ->and(listedReservationIds($component->set('status', '')->set('search', 'siti')))->toBe([$pending->id]);

    Reservation::factory()->count(12)->create();

    $paged = Livewire::actingAs($librarian)->test(ReservationManager::class);

    expect($paged->instance()->reservations->count())->toBe(10)
        ->and($paged->call('nextPage')->instance()->reservations->count())->toBe(6);
});

// --- Interaction with borrowing ---------------------------------------------

test('an active hold blocks lending the book to anyone but the holder', function () {
    $librarian = User::factory()->librarian()->create();
    $hold = Reservation::factory()->approved()->create();
    $otherStudent = User::factory()->create();

    expect($hold->book->isAvailable())->toBeFalse()
        ->and(Book::availableFor($otherStudent->id)->pluck('id'))->not->toContain($hold->book_id)
        ->and(Book::availableFor($hold->user_id)->pluck('id'))->toContain($hold->book_id)
        ->and(fn () => app(IssueBookAction::class)->handle($librarian, $otherStudent, $hold->book, now()->addWeek()))
        ->toThrow(BorrowingException::class, 'on hold for another student');

    $borrowing = app(IssueBookAction::class)->handle($librarian, $hold->user, $hold->book, now()->addWeek());

    expect($borrowing->user_id)->toBe($hold->user_id)
        ->and($hold->fresh()->status)->toBe(ReservationStatus::Fulfilled);
});

test('the borrowing issue form hides books held for other students', function () {
    $hold = Reservation::factory()->approved()->create();
    $otherStudent = User::factory()->create();

    $component = Livewire::actingAs(User::factory()->librarian()->create())->test(BorrowingManager::class);

    expect($component->set('form.user_id', $otherStudent->id)->instance()->availableBooks->pluck('id'))->not->toContain($hold->book_id)
        ->and($component->set('form.user_id', $hold->user_id)->instance()->availableBooks->pluck('id'))->toContain($hold->book_id);
});

test('pending reservations do not block lending, and are fulfilled when the requester borrows', function () {
    $librarian = User::factory()->librarian()->create();
    $pending = Reservation::factory()->create();
    $book = $pending->book;

    expect($book->isAvailable())->toBeTrue();

    app(IssueBookAction::class)->handle($librarian, $pending->user, $book, now()->addWeek());

    expect($pending->fresh()->status)->toBe(ReservationStatus::Fulfilled);
});

test('a lapsed hold no longer blocks lending', function () {
    $lapsed = Reservation::factory()->lapsed()->create();

    expect($lapsed->book->isAvailable())->toBeTrue()
        ->and($lapsed->effectiveStatus())->toBe(ReservationStatus::Expired);

    app(IssueBookAction::class)->handle(User::factory()->librarian()->create(), User::factory()->create(), $lapsed->book, now()->addWeek());

    expect($lapsed->fresh()->status)->toBe(ReservationStatus::Expired);
});

test('the expire action marks only lapsed holds', function () {
    $lapsed = Reservation::factory()->lapsed()->create();
    $active = Reservation::factory()->approved()->create();

    expect(app(ExpireStaleReservationsAction::class)->handle())->toBe(1)
        ->and($lapsed->fresh()->status)->toBe(ReservationStatus::Expired)
        ->and($active->fresh()->status)->toBe(ReservationStatus::Approved);
});

test('the full lifecycle keeps every reservation and loan as history', function () {
    $librarian = User::factory()->librarian()->create();
    $student = User::factory()->create();
    $book = Book::factory()->create();

    $reservation = app(CreateReservationAction::class)->handle($student, $book->id);
    app(ApproveReservationAction::class)->handle($librarian, $reservation);
    $loan = app(IssueBookAction::class)->handle($librarian, $student, $book, now()->addWeek());
    app(ReturnBookAction::class)->handle($librarian, $loan);
    app(CreateReservationAction::class)->handle($student, $book->id);

    expect($book->reservations()->orderBy('id')->pluck('status')->all())
        ->toBe([ReservationStatus::Fulfilled, ReservationStatus::Pending])
        ->and($book->borrowings()->count())->toBe(1)
        ->and($reservation->fresh()->reviewed_by)->toBe($librarian->id);
});

// --- Transactions -----------------------------------------------------------

test('a failure while creating a reservation rolls it back', function () {
    Reservation::created(fn () => throw new RuntimeException('Simulated failure after insert'));

    expect(fn () => app(CreateReservationAction::class)->handle(User::factory()->create(), Book::factory()->create()->id))
        ->toThrow(RuntimeException::class);

    expect(Reservation::count())->toBe(0);
});

test('a failure while approving rolls the approval back', function () {
    $reservation = Reservation::factory()->create();

    DB::listen(function (QueryExecuted $query): void {
        if (str_starts_with($query->sql, 'update "reservations" set "status"')) {
            throw new RuntimeException('Simulated failure after update');
        }
    });

    expect(fn () => app(ApproveReservationAction::class)->handle(User::factory()->librarian()->create(), $reservation))
        ->toThrow(RuntimeException::class);

    expect($reservation->fresh())
        ->status->toBe(ReservationStatus::Pending)
        ->reviewed_by->toBeNull()
        ->expires_at->toBeNull();
});

test('a failure while issuing a held book rolls back both the loan and the fulfilment', function () {
    $hold = Reservation::factory()->approved()->create();
    Borrowing::created(fn () => throw new RuntimeException('Simulated failure after insert'));

    expect(fn () => app(IssueBookAction::class)->handle(User::factory()->librarian()->create(), $hold->user, $hold->book, now()->addWeek()))
        ->toThrow(RuntimeException::class);

    expect(Borrowing::count())->toBe(0)
        ->and($hold->fresh()->status)->toBe(ReservationStatus::Approved);
});
