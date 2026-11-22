<?php

use App\Enums\ReservationStatus;
use App\Livewire\Books\BookManager;
use App\Livewire\Borrowings\BorrowingManager;
use App\Livewire\Dashboard;
use App\Livewire\Reservations\ReservationManager;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Drive the reservation → approval → issue → return workflow through the
 * real Livewire pages, the way it is demonstrated, not only via Actions.
 */
function issueThroughDesk(User $librarian, User $student, Book $book): Testable
{
    return Livewire::actingAs($librarian)
        ->test(BorrowingManager::class)
        ->call('openIssueForm')
        ->set('form.user_id', $student->id)
        ->set('form.book_id', $book->id)
        ->call('issue');
}

function reserveAndApprove(User $student, User $librarian, Book $book): Reservation
{
    Livewire::actingAs($student)
        ->test(ReservationManager::class)
        ->set('form.book_id', $book->id)
        ->call('submitRequest')
        ->assertHasNoErrors();

    $reservation = Reservation::where('user_id', $student->id)->where('book_id', $book->id)->sole();

    Livewire::actingAs($librarian)
        ->test(ReservationManager::class)
        ->call('openReview', $reservation->id, 'approve')
        ->call('submitReview')
        ->assertHasNoErrors();

    return $reservation->refresh();
}

test('an approved reservation holds the book for its owner and is fulfilled when issued to them', function () {
    $librarian = User::factory()->librarian()->create();
    $owner = User::factory()->create();
    $otherStudent = User::factory()->create();
    $book = Book::factory()->create(['title' => 'Clean Code']);

    $reservation = reserveAndApprove($owner, $librarian, $book);

    expect($reservation->status)->toBe(ReservationStatus::Approved)
        ->and(Borrowing::count())->toBe(0)
        ->and($book->isAvailable())->toBeFalse();

    issueThroughDesk($librarian, $otherStudent, $book)
        ->assertHasErrors(['form.book_id'])
        ->assertSee('on hold for another student');

    expect(Borrowing::count())->toBe(0);

    issueThroughDesk($librarian, $owner, $book)->assertHasNoErrors()->assertSet('alert.variant', 'success');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Fulfilled)
        ->and(Borrowing::sole()->user_id)->toBe($owner->id)
        ->and($book->fresh()->isAvailable())->toBeFalse();

    $loan = Borrowing::sole();

    Livewire::actingAs($librarian)
        ->test(BorrowingManager::class)
        ->call('confirmReturn', $loan->id)
        ->call('returnBook')
        ->assertSet('alert.variant', 'success');

    expect($book->fresh()->isAvailable())->toBeTrue()
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Fulfilled)
        ->and($loan->fresh()->returned_at)->not->toBeNull();
});

test('the hold lasts until the end of the third day after approval, then lapses', function () {
    Carbon::setTestNow('2026-11-23 10:00:00');
    $librarian = User::factory()->librarian()->create();
    $owner = User::factory()->create();
    $otherStudent = User::factory()->create();
    $book = Book::factory()->create();

    $reservation = reserveAndApprove($owner, $librarian, $book);

    expect($reservation->expires_at->toDateTimeString())->toBe('2026-11-26 23:59:59');

    // Last evening of the hold: still reserved for the owner only.
    Carbon::setTestNow('2026-11-26 23:00:00');
    issueThroughDesk($librarian, $otherStudent, $book)->assertHasErrors(['form.book_id']);

    // Just after the deadline: the lapsed hold no longer blocks anyone,
    // even though nothing has marked it "expired" yet.
    Carbon::setTestNow('2026-11-27 00:00:01');

    expect($reservation->fresh()->status)->toBe(ReservationStatus::Approved)
        ->and($reservation->fresh()->effectiveStatus())->toBe(ReservationStatus::Expired)
        ->and($book->isAvailable())->toBeTrue();

    issueThroughDesk($librarian, $otherStudent, $book)->assertHasNoErrors();

    expect(Borrowing::sole()->user_id)->toBe($otherStudent->id)
        ->and($reservation->fresh()->status)->toBe(ReservationStatus::Expired);
});

test('the reservations:expire command marks lapsed holds and is scheduled hourly', function () {
    $lapsed = Reservation::factory()->lapsed()->create();
    $active = Reservation::factory()->approved()->create();

    $this->artisan('reservations:expire')
        ->expectsOutput('Marked 1 lapsed reservation hold(s) as expired.')
        ->assertSuccessful();

    expect($lapsed->fresh()->status)->toBe(ReservationStatus::Expired)
        ->and($active->fresh()->status)->toBe(ReservationStatus::Approved);

    $this->artisan('schedule:list')->expectsOutputToContain('reservations:expire')->assertSuccessful();
});

test('the dashboard reflects the workflow as it happens', function () {
    $librarian = User::factory()->librarian()->create();
    $owner = User::factory()->create();
    $book = Book::factory()->create();

    reserveAndApprove($owner, $librarian, $book);

    Livewire::actingAs($librarian)->test(Dashboard::class)
        ->assertSet('catalogueStats.available', 0)
        ->assertSet('circulationStats.onHold', 1);

    issueThroughDesk($librarian, $owner, $book);

    Livewire::actingAs($librarian)->test(Dashboard::class)
        ->assertSet('circulationStats.onHold', 0)
        ->assertSet('circulationStats.active', 1);
});

// --- Security regressions ---------------------------------------------------

test('every protected page requires a signed-in, verified user', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route($routeName))
        ->assertRedirect(route('verification.notice'));

    expect(Route::getRoutes()->getByName($routeName)->gatherMiddleware())->toContain('auth', 'verified');
})->with(['dashboard', 'books.manage', 'borrowings.index', 'reservations.index']);

test('the original Assignment 1 pages stay public and its seven routes are intact', function () {
    $book = Book::factory()->create();

    $this->get(route('home'))->assertOk()->assertSee('Welcome to BookNest');
    $this->get(route('books.index'))->assertOk()->assertSee($book->title);
    $this->get(route('books.show', $book))->assertOk();

    $actions = collect(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
        ->map(fn (string $action) => Route::getRoutes()->getByName("books.{$action}")?->getActionName());

    expect($actions->all())->toBe(collect(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'])
        ->map(fn (string $action) => "App\\Http\\Controllers\\BookController@{$action}")->all());
});

test('user-supplied text is escaped everywhere it is shown', function () {
    $payload = '<script>alert("xss")</script>';
    $student = User::factory()->create(['name' => $payload]);
    $book = Book::factory()->create(['title' => $payload, 'author' => $payload]);
    Borrowing::factory()->for($student)->for($book)->create();
    Reservation::factory()->for($student)->for(Book::factory()->state(['title' => $payload]))->rejected()->create(['remarks' => $payload]);
    $librarian = User::factory()->librarian()->create();
    $escaped = e($payload);

    foreach ([Dashboard::class, BookManager::class, BorrowingManager::class, ReservationManager::class] as $page) {
        Livewire::actingAs($librarian)->test($page)
            ->assertDontSeeHtml($payload)
            ->assertSeeHtml($escaped);
    }

    $this->get(route('books.index'))->assertDontSee($payload, false)->assertSee($escaped, false);
});

test('the registration form cannot grant a staff role', function () {
    $this->post(route('register.store'), [
        'name' => 'Role Escalation',
        'email' => 'escalate@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'role' => 'admin',
    ]);

    $registered = User::firstWhere('email', 'escalate@example.com');

    // New accounts must verify their email before reaching the dashboard.
    $this->actingAs($registered)->get(route('dashboard'))->assertRedirect(route('verification.notice'));

    $registered->markEmailAsVerified();

    $this->actingAs($registered)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('Student')
        ->assertDontSee('Accounts');
});
