<?php

use App\Actions\ApproveReservationAction;
use App\Actions\IssueBookAction;
use App\Actions\ReturnBookAction;
use App\Enums\BorrowingStatus;
use App\Exceptions\ReservationException;
use App\Livewire\Books\BookManager;
use App\Livewire\Borrowings\BorrowingManager;
use App\Models\Author;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Testing\TestResponse;
use Livewire\Features\SupportComputed\CannotCallComputedDirectlyException;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Livewire\Mechanisms\HandleRequests\HandleRequests;

/*
|--------------------------------------------------------------------------
| Final security audit regression tests (see SECURITY_AUDIT.md)
|--------------------------------------------------------------------------
*/

/**
 * Laravel skips CSRF verification while running tests. Swap in the real
 * middleware with that shortcut disabled so CSRF is genuinely enforced.
 */
function enforceCsrf(): void
{
    app()->instance(PreventRequestForgery::class, new class(app(), app('encrypter')) extends PreventRequestForgery
    {
        protected function runningUnitTests(): bool
        {
            return false;
        }
    });
}

/**
 * Load a Livewire page over HTTP and send one real component request (a
 * method call), exactly as the browser does when a button is clicked.
 */
function callLivewireOverHttp(string $pageUrl, string $componentName, string $method, array $params = [], ?string $csrfToken = 'test-token'): TestResponse
{
    $html = test()->get($pageUrl)->assertOk()->getContent();

    preg_match('/wire:snapshot="([^"]*)"[^>]*wire:name="'.preg_quote($componentName, '/').'"/', $html, $matches);
    expect($matches)->not->toBeEmpty("Component {$componentName} not found on {$pageUrl}");

    $payload = [
        'components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
            'updates' => [],
            'calls' => [['path' => '', 'method' => $method, 'params' => $params, 'metadata' => []]],
        ]],
    ];

    if ($csrfToken !== null) {
        $payload['_token'] = $csrfToken;
    }

    return test()
        ->withSession(['_token' => 'test-token'])
        ->postJson(app(HandleRequests::class)->getUpdateUri(), $payload, ['X-Livewire' => '1']);
}

// --- Authentication on every Livewire request ---------------------------------

test('Livewire re-checks email verification on every component request', function () {
    expect(Livewire::getPersistentMiddleware())->toContain(EnsureEmailIsVerified::class);

    $librarian = User::factory()->librarian()->create();
    $this->actingAs($librarian);

    callLivewireOverHttp(route('books.manage'), 'books.book-manager', 'clearFilters')->assertOk();

    // In production every request is a fresh PHP process; in a test the
    // Livewire singletons survive, so reset them as a request boundary would.
    Livewire::flushState();

    // The page is already open; the user then becomes unverified (e.g. by
    // changing their email address). Further actions must be refused.
    $html = $this->get(route('books.manage'))->getContent();
    $librarian->forceFill(['email_verified_at' => null])->save();

    preg_match('/wire:snapshot="([^"]*)"[^>]*wire:name="books.book-manager"/', $html, $matches);

    $this->withSession(['_token' => 'test-token'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
        '_token' => 'test-token',
        'components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'create', 'params' => [], 'metadata' => []]],
        ]],
    ], ['X-Livewire' => '1'])->assertForbidden();
});

test('Livewire re-checks authentication after logout', function () {
    $librarian = User::factory()->librarian()->create();
    $this->actingAs($librarian);

    $html = $this->get(route('borrowings.index'))->getContent();
    preg_match('/wire:snapshot="([^"]*)"[^>]*wire:name="borrowings.borrowing-manager"/', $html, $matches);

    $this->app['auth']->forgetGuards();

    $this->withSession(['_token' => 'test-token'])->postJson(app(HandleRequests::class)->getUpdateUri(), [
        '_token' => 'test-token',
        'components' => [[
            'snapshot' => html_entity_decode($matches[1], ENT_QUOTES),
            'updates' => [],
            'calls' => [['path' => '', 'method' => 'openIssueForm', 'params' => [], 'metadata' => []]],
        ]],
    ], ['X-Livewire' => '1'])->assertUnauthorized();
});

test('login is throttled after five failed attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 5) as $attempt) {
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password']);
    }

    // Fortify's throttle:login limiter refuses even the correct password.
    $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertTooManyRequests();

    $this->assertGuest();
});

// --- CSRF -----------------------------------------------------------------------

test('Assignment 1 form submissions are rejected without a valid CSRF token', function (string $method, Closure $makeUri) {
    enforceCsrf();
    $admin = User::factory()->admin()->create();
    $book = Book::factory()->create(['title' => 'Untouched']);

    $this->actingAs($admin)
        ->call($method, $makeUri($book), ['title' => 'Hijacked', '_token' => 'forged'], [], [], [
            'HTTP_ORIGIN' => 'https://evil.example',
            'HTTP_SEC_FETCH_SITE' => 'cross-site',
        ])
        ->assertStatus(419);

    expect(Book::count())->toBe(1)
        ->and($book->fresh()->title)->toBe('Untouched');
})->with([
    'store' => ['POST', fn (Book $book) => route('books.store')],
    'update' => ['PUT', fn (Book $book) => route('books.update', $book)],
    'destroy' => ['DELETE', fn (Book $book) => route('books.destroy', $book)],
]);

test('Assignment 1 forms work with a valid CSRF token', function () {
    enforceCsrf();
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->librarian()->create())
        ->withSession(['_token' => 'valid-token'])
        ->post(route('books.store'), [
            '_token' => 'valid-token',
            'category_id' => $category->id,
            'title' => 'Refactoring',
            'author' => 'Martin Fowler',
            'year_published' => 2018,
            'price' => '89.90',
        ])
        ->assertRedirect(route('books.index'));

    expect(Book::sole()->title)->toBe('Refactoring');
});

test('Livewire component requests are rejected without a valid CSRF token', function () {
    enforceCsrf();
    $this->actingAs(User::factory()->librarian()->create());

    callLivewireOverHttp(route('books.manage'), 'books.book-manager', 'clearFilters', csrfToken: 'forged')
        ->assertStatus(419);

    callLivewireOverHttp(route('books.manage'), 'books.book-manager', 'clearFilters', csrfToken: 'test-token')
        ->assertOk();
});

// --- Livewire state tampering ----------------------------------------------------

test('computed data cannot be called directly from the browser', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(BorrowingManager::class)
        ->call('students');
})->throws(CannotCallComputedDirectlyException::class);

test('locked record ids cannot be changed from the browser', function () {
    $loan = Borrowing::factory()->create();

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->set('returningBorrowingId', $loan->id);
})->throws(CannotUpdateLockedPropertyException::class);

test('the book being edited cannot be swapped from the browser', function () {
    $edited = Book::factory()->create(['title' => 'Edited Book']);
    $other = Book::factory()->create(['title' => 'Other Book']);

    // Livewire ignores client attempts to replace a model property: the
    // form keeps pointing at the book that was authorised for editing.
    $component = Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BookManager::class)
        ->call('edit', $edited->id)
        ->set('form.book', $other->id);

    expect($component->instance()->form->book->id)->toBe($edited->id);

    $component->set('form.title', 'Renamed')->call('save')->assertHasNoErrors();

    expect($edited->fresh()->title)->toBe('Renamed')
        ->and($other->fresh()->title)->toBe('Other Book');
});

// --- Mass assignment and serialisation --------------------------------------------

test('protected attributes cannot be mass assigned', function () {
    $reviewer = User::factory()->librarian()->create();

    $reservation = new Reservation([
        'user_id' => 1,
        'book_id' => 1,
        'status' => 'approved',
        'reviewed_by' => $reviewer->id,
        'expires_at' => now()->addYear(),
        'remarks' => 'self-approved',
    ]);

    $user = new User(['name' => 'X', 'email' => 'x@example.com', 'password' => 'secret', 'role' => 'admin', 'email_verified_at' => now()]);

    expect($reservation->status->value)->toBe('pending')
        ->and($reservation->reviewed_by)->toBeNull()
        ->and($reservation->expires_at)->toBeNull()
        ->and($reservation->remarks)->toBeNull()
        ->and($user->role->value)->toBe('student')
        ->and($user->email_verified_at)->toBeNull();
});

test('secrets are never serialised with a user', function () {
    $user = User::factory()->withTwoFactor()->create();

    expect(array_keys($user->toArray()))
        ->not->toContain('password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes');
});

test('application secrets stay out of version control', function () {
    expect(config('app.key'))->not->toBeEmpty()
        ->and(file_get_contents(base_path('.gitignore')))->toMatch('/^\.env$/m')
        ->and(file_get_contents(base_path('.env.example')))->toMatch('/^APP_KEY=$/m');
});

// --- Role permission matrix (mirrors SECURITY_AUDIT.md) ----------------------------

test('the role permission matrix is enforced by the policies', function (string $role, array $expected) {
    $user = match ($role) {
        'admin' => User::factory()->admin()->create(),
        'librarian' => User::factory()->librarian()->create(),
        'student' => User::factory()->create(),
    };

    $book = Book::factory()->create();
    $ownLoan = Borrowing::factory()->for($user)->create();
    $othersLoan = Borrowing::factory()->create();
    $othersPending = Reservation::factory()->create();
    $othersHold = Reservation::factory()->approved()->create();

    $actual = [
        'book.create' => $user->can('create', Book::class),
        'book.update' => $user->can('update', $book),
        'book.delete' => $user->can('delete', $book),
        'author/publisher.manage' => $user->can('create', Author::class) && $user->can('create', Publisher::class),
        'borrowing.viewAll' => $user->can('viewAny', Borrowing::class),
        'borrowing.viewOwn' => $user->can('view', $ownLoan),
        'borrowing.viewOthers' => $user->can('view', $othersLoan),
        'borrowing.issue' => $user->can('create', Borrowing::class),
        'borrowing.return' => $user->can('return', $othersLoan),
        'reservation.viewAll' => $user->can('viewAny', Reservation::class),
        'reservation.viewOthers' => $user->can('view', $othersPending),
        'reservation.create' => $user->can('create', Reservation::class),
        'reservation.review' => $user->can('review', $othersPending),
        'reservation.releaseHold' => $user->can('cancel', $othersHold),
    ];

    expect($actual)->toBe($expected);
})->with([
    'admin' => ['admin', [
        'book.create' => true, 'book.update' => true, 'book.delete' => true, 'author/publisher.manage' => true,
        'borrowing.viewAll' => true, 'borrowing.viewOwn' => true, 'borrowing.viewOthers' => true, 'borrowing.issue' => true, 'borrowing.return' => true,
        'reservation.viewAll' => true, 'reservation.viewOthers' => true, 'reservation.create' => false, 'reservation.review' => true, 'reservation.releaseHold' => true,
    ]],
    'librarian' => ['librarian', [
        'book.create' => true, 'book.update' => true, 'book.delete' => false, 'author/publisher.manage' => true,
        'borrowing.viewAll' => true, 'borrowing.viewOwn' => true, 'borrowing.viewOthers' => true, 'borrowing.issue' => true, 'borrowing.return' => true,
        'reservation.viewAll' => true, 'reservation.viewOthers' => true, 'reservation.create' => false, 'reservation.review' => true, 'reservation.releaseHold' => true,
    ]],
    'student' => ['student', [
        'book.create' => false, 'book.update' => false, 'book.delete' => false, 'author/publisher.manage' => false,
        'borrowing.viewAll' => false, 'borrowing.viewOwn' => true, 'borrowing.viewOthers' => false, 'borrowing.issue' => false, 'borrowing.return' => false,
        'reservation.viewAll' => false, 'reservation.viewOthers' => false, 'reservation.create' => true, 'reservation.review' => false, 'reservation.releaseHold' => false,
    ]],
]);

// --- Business rule regressions -------------------------------------------------------

test('a closed reservation can never be approved', function (Closure $makeClosed) {
    $reservation = $makeClosed();
    $statusBefore = $reservation->fresh()->status;

    expect(fn () => app(ApproveReservationAction::class)->handle(User::factory()->librarian()->create(), $reservation))
        ->toThrow(ReservationException::class, 'can no longer be reviewed');

    expect($reservation->fresh()->status)->toBe($statusBefore);
})->with([
    'fulfilled' => [fn () => Reservation::factory()->create(['status' => 'fulfilled'])],
    'rejected' => [fn () => Reservation::factory()->rejected()->create()],
    'cancelled' => [fn () => Reservation::factory()->create(['status' => 'cancelled'])],
    'expired' => [fn () => Reservation::factory()->create(['status' => 'expired'])],
    'approved (active hold)' => [fn () => Reservation::factory()->approved()->create()],
]);

test('loan status and return date stay consistent across the whole lifecycle', function () {
    $librarian = User::factory()->librarian()->create();
    $students = User::factory()->count(3)->create();

    foreach (Book::factory()->count(3)->create() as $index => $book) {
        $loan = app(IssueBookAction::class)->handle($librarian, $students[$index], $book, now()->addWeek());

        if ($index !== 2) {
            app(ReturnBookAction::class)->handle($librarian, $loan);
        }
    }

    Borrowing::all()->each(function (Borrowing $loan) {
        expect($loan->status === BorrowingStatus::Returned)->toBe($loan->returned_at !== null)
            ->and($loan->user_id)->not->toBeNull()
            ->and($loan->due_at->greaterThan($loan->borrowed_at))->toBeTrue();
    });

    expect(Borrowing::count())->toBe(3)
        ->and(Borrowing::active()->count())->toBe(1);
});
