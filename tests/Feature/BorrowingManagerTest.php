<?php

use App\Actions\IssueBookAction;
use App\Actions\ReturnBookAction;
use App\Enums\BorrowingStatus;
use App\Exceptions\BorrowingException;
use App\Livewire\Borrowings\BorrowingManager;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

// --- Access -----------------------------------------------------------------

test('borrowings page requires a signed-in, verified user', function () {
    $this->get(route('borrowings.index'))->assertRedirect(route('login'));

    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('borrowings.index'))
        ->assertRedirect(route('verification.notice'));

    expect(Route::getRoutes()->getByName('borrowings.index')->gatherMiddleware())
        ->toContain('auth', 'verified');
});

test('staff and students can open the page and the sidebar links to it', function (Closure $makeUser, string $heading) {
    $this->actingAs($makeUser())
        ->get(route('borrowings.index'))
        ->assertOk()
        ->assertSeeLivewire(BorrowingManager::class)
        ->assertSee($heading)
        ->assertSee(route('borrowings.index'));
})->with([
    'librarian' => [fn () => User::factory()->librarian()->create(), 'Issue Book'],
    'student' => [fn () => User::factory()->create(), 'My Borrowings'],
]);

// --- Issuing ----------------------------------------------------------------

test('librarians can issue an available book to a student', function () {
    $this->freezeSecond();
    $student = User::factory()->create(['name' => 'Aina Student']);
    $book = Book::factory()->create(['title' => 'Clean Code']);
    $dueDate = now()->addDays(14)->toDateString();

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->call('openIssueForm')
        ->assertSet('form.due_at', $dueDate)
        ->set('form.user_id', $student->id)
        ->set('form.book_id', $book->id)
        ->call('issue')
        ->assertHasNoErrors()
        ->assertSet('alert.variant', 'success')
        ->assertSee('"Clean Code" was issued to Aina Student');

    $borrowing = Borrowing::sole();

    expect($borrowing)
        ->user_id->toBe($student->id)
        ->book_id->toBe($book->id)
        ->status->toBe(BorrowingStatus::Borrowed)
        ->returned_at->toBeNull()
        ->and($borrowing->borrowed_at->equalTo(now()))->toBeTrue()
        ->and($borrowing->due_at->toDateString())->toBe($dueDate)
        ->and($book->isAvailable())->toBeFalse();
});

test('admins can issue books too', function () {
    $student = User::factory()->create();
    $book = Book::factory()->create();

    app(IssueBookAction::class)->handle(User::factory()->admin()->create(), $student, $book, now()->addWeek());

    expect(Borrowing::sole()->user_id)->toBe($student->id);
});

test('a book already on loan cannot be issued to another student', function () {
    $existing = Borrowing::factory()->create();

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->call('openIssueForm')
        ->set('form.user_id', User::factory()->create()->id)
        ->set('form.book_id', $existing->book_id)
        ->call('issue')
        ->assertHasErrors(['form.book_id'])
        ->assertSee('is already on loan');

    expect(Borrowing::count())->toBe(1);
});

test('the issue form only offers books that are not on loan', function () {
    $onLoan = Borrowing::factory()->create()->book;
    $available = Book::factory()->create();

    $offered = Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->instance()
        ->availableBooks;

    expect($offered->pluck('id')->all())->toBe([$available->id])
        ->and($offered->contains($onLoan))->toBeFalse();
});

test('the database itself refuses a second open loan for the same book', function () {
    $book = Borrowing::factory()->create()->book;

    expect(fn () => Borrowing::factory()->for($book)->create())
        ->toThrow(UniqueConstraintViolationException::class);
});

test('a returned book can be issued again and both loans are kept', function () {
    $librarian = User::factory()->librarian()->create();
    $book = Book::factory()->create();
    $first = app(IssueBookAction::class)->handle($librarian, User::factory()->create(), $book, now()->addWeek());

    app(ReturnBookAction::class)->handle($librarian, $first);
    app(IssueBookAction::class)->handle($librarian, User::factory()->create(), $book, now()->addWeek());

    expect($book->borrowings()->count())->toBe(2)
        ->and($book->borrowings()->active()->count())->toBe(1);
});

test('books can only be issued to students', function () {
    $librarian = User::factory()->librarian()->create();

    expect(fn () => app(IssueBookAction::class)->handle($librarian, User::factory()->librarian()->create(), Book::factory()->create(), now()->addWeek()))
        ->toThrow(BorrowingException::class, 'Books can only be issued to registered students.');

    expect(Borrowing::count())->toBe(0);
});

test('the issue action refuses a due date that is not in the future', function () {
    expect(fn () => app(IssueBookAction::class)->handle(User::factory()->librarian()->create(), User::factory()->create(), Book::factory()->create(), now()))
        ->toThrow(BorrowingException::class, 'The due date must be after today.');
});

// --- Validation -------------------------------------------------------------

test('the issue form validates its input', function (Closure $makeInput, string $field, string $rule) {
    $input = [
        'user_id' => User::factory()->create()->id,
        'book_id' => Book::factory()->create()->id,
        'due_at' => now()->addDays(14)->toDateString(),
        ...$makeInput(),
    ];

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->set('form.user_id', $input['user_id'])
        ->set('form.book_id', $input['book_id'])
        ->set('form.due_at', $input['due_at'])
        ->call('issue')
        ->assertHasErrors(["form.{$field}" => $rule]);

    expect(Borrowing::count())->toBe(0);
})->with([
    'missing student' => [fn () => ['user_id' => ''], 'user_id', 'required'],
    'borrower is not a student' => [fn () => ['user_id' => User::factory()->librarian()->create()->id], 'user_id', 'exists'],
    'missing book' => [fn () => ['book_id' => ''], 'book_id', 'required'],
    'unknown book' => [fn () => ['book_id' => 999], 'book_id', 'exists'],
    'missing due date' => [fn () => ['due_at' => ''], 'due_at', 'required'],
    'due today' => [fn () => ['due_at' => now()->toDateString()], 'due_at', 'after'],
    'loan longer than 60 days' => [fn () => ['due_at' => now()->addDays(61)->toDateString()], 'due_at', 'before_or_equal'],
    'malformed date' => [fn () => ['due_at' => '31/12/2026'], 'due_at', 'date_format'],
]);

// --- Returns ----------------------------------------------------------------

test('librarians can record a return and the loan is kept as history', function () {
    $this->freezeSecond();
    $borrowing = Borrowing::factory()->create();

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->call('confirmReturn', $borrowing->id)
        ->assertSet('returningBorrowingId', $borrowing->id)
        ->call('returnBook')
        ->assertSet('alert.variant', 'success')
        ->assertSee('was returned.');

    expect($borrowing->fresh())
        ->status->toBe(BorrowingStatus::Returned)
        ->and($borrowing->fresh()->returned_at->equalTo(now()))->toBeTrue()
        ->and($borrowing->book->isAvailable())->toBeTrue();
});

test('a late return is flagged in the success message', function () {
    $borrowing = Borrowing::factory()->create(['borrowed_at' => now()->subDays(20), 'due_at' => now()->subDays(3)]);

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->call('confirmReturn', $borrowing->id)
        ->call('returnBook')
        ->assertSee('It was returned late.');
});

test('a loan cannot be returned twice', function () {
    $librarian = User::factory()->librarian()->create();
    $borrowing = Borrowing::factory()->create();
    app(ReturnBookAction::class)->handle($librarian, $borrowing);
    $firstReturnedAt = $borrowing->fresh()->returned_at;

    $this->travel(1)->day();

    expect(fn () => app(ReturnBookAction::class)->handle($librarian, $borrowing))
        ->toThrow(BorrowingException::class, 'already returned');

    Livewire::actingAs($librarian)
        ->test(BorrowingManager::class)
        ->assertDontSeeHtml('wire:click="confirmReturn('.$borrowing->id.')"')
        ->call('confirmReturn', $borrowing->id)
        ->assertSet('returningBorrowingId', null)
        ->assertSee('This loan has already been returned.');

    expect($borrowing->fresh()->returned_at->equalTo($firstReturnedAt))->toBeTrue();
});

test('a return recorded elsewhere after confirmation is not recorded again', function () {
    $librarian = User::factory()->librarian()->create();
    $borrowing = Borrowing::factory()->create();

    $component = Livewire::actingAs($librarian)
        ->test(BorrowingManager::class)
        ->call('confirmReturn', $borrowing->id);

    app(ReturnBookAction::class)->handle($librarian, $borrowing);

    $component->call('returnBook')
        ->assertSet('alert.variant', 'danger')
        ->assertSee('already returned');
});

// --- Students ---------------------------------------------------------------

test('students see only their own borrowing history', function () {
    $student = User::factory()->create();
    Borrowing::factory()->for($student)->for(Book::factory()->state(['title' => 'My Own Book']))->create();
    Borrowing::factory()->for(Book::factory()->state(['title' => 'Someone Elses Book']))->create();

    Livewire::actingAs($student)
        ->test(BorrowingManager::class)
        ->assertSee('My Own Book')
        ->assertDontSee('Someone Elses Book')
        ->assertSet('stats.active', 1)
        ->set('search', 'Someone')
        ->assertSee('No borrowings found.');
});

test('students cannot issue books or record returns', function () {
    $student = User::factory()->create();
    $ownLoan = Borrowing::factory()->for($student)->create();

    Livewire::actingAs($student)
        ->test(BorrowingManager::class)
        ->assertDontSee('Issue Book')
        ->assertDontSeeHtml('wire:click="confirmReturn(')
        ->call('openIssueForm')
        ->assertForbidden();

    Livewire::actingAs($student)
        ->test(BorrowingManager::class)
        ->set('form.user_id', $student->id)
        ->set('form.book_id', Book::factory()->create()->id)
        ->set('form.due_at', now()->addWeek()->toDateString())
        ->call('issue')
        ->assertForbidden();

    Livewire::actingAs($student)
        ->test(BorrowingManager::class)
        ->call('confirmReturn', $ownLoan->id)
        ->assertSet('returningBorrowingId', null)
        ->assertSee('Only librarians and administrators can record returns.');

    expect(fn () => app(ReturnBookAction::class)->handle($student, $ownLoan))
        ->toThrow(AuthorizationException::class);

    expect(Borrowing::count())->toBe(1)
        ->and($ownLoan->fresh()->returned_at)->toBeNull();
});

// --- Listing ----------------------------------------------------------------

/**
 * IDs of the loans in the table. The issue modal's dropdowns also contain
 * student names and available book titles, so assertSee() on the whole
 * page cannot tell table rows apart from dropdown options.
 *
 * @return list<int>
 */
function listedLoanIds(Testable $component): array
{
    return $component->instance()->borrowings->pluck('id')->sort()->values()->all();
}

test('status filters separate active, overdue and returned loans', function () {
    $active = Borrowing::factory()->create(['due_at' => now()->addDays(5)]);
    $overdue = Borrowing::factory()->create(['borrowed_at' => now()->subDays(20), 'due_at' => now()->subDays(2)]);
    $returned = Borrowing::factory()->returned()->create();

    $component = Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->assertSet('stats', ['active' => 2, 'overdue' => 1, 'returned' => 1])
        ->assertSee('Overdue 2 days');

    expect(listedLoanIds($component->set('status', 'active')))->toBe([$active->id, $overdue->id])
        ->and(listedLoanIds($component->set('status', 'overdue')))->toBe([$overdue->id])
        ->and(listedLoanIds($component->set('status', 'returned')))->toBe([$returned->id]);

    $component->set('status', 'bogus')->assertSet('status', '');
});

test('staff can search by book title or student name', function () {
    $hakimsLoan = Borrowing::factory()
        ->for(User::factory()->state(['name' => 'Hakim Rahman']))
        ->for(Book::factory()->state(['title' => 'Steve Jobs']))
        ->create();
    $sitisLoan = Borrowing::factory()
        ->for(User::factory()->state(['name' => 'Siti Aminah']))
        ->for(Book::factory()->state(['title' => 'Clean Code']))
        ->create();

    $component = Livewire::actingAs(User::factory()->librarian()->create())->test(BorrowingManager::class);

    expect(listedLoanIds($component->set('search', 'steve')))->toBe([$hakimsLoan->id])
        ->and(listedLoanIds($component->set('search', 'siti')))->toBe([$sitisLoan->id]);
});

test('borrowings are paginated ten per page', function () {
    foreach (range(1, 12) as $number) {
        Borrowing::factory()
            ->for(Book::factory()->state(['title' => sprintf('Loan Book %02d', $number)]))
            ->create(['due_at' => now()->addDays($number)]);
    }

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BorrowingManager::class)
        ->assertSee('Loan Book 10')
        ->assertDontSee('Loan Book 11')
        ->call('nextPage')
        ->assertSee('Loan Book 11')
        ->assertDontSee('Loan Book 10');
});

// --- Transactions -----------------------------------------------------------

test('a failure while issuing rolls the new loan back', function () {
    Borrowing::created(fn () => throw new RuntimeException('Simulated failure after insert'));
    $book = Book::factory()->create();

    expect(fn () => app(IssueBookAction::class)->handle(User::factory()->librarian()->create(), User::factory()->create(), $book, now()->addWeek()))
        ->toThrow(RuntimeException::class, 'Simulated failure after insert');

    expect(Borrowing::count())->toBe(0)
        ->and($book->isAvailable())->toBeTrue();
});

test('a failure while returning rolls the return back', function () {
    $borrowing = Borrowing::factory()->create();
    Borrowing::updated(fn () => throw new RuntimeException('Simulated failure after update'));

    expect(fn () => app(ReturnBookAction::class)->handle(User::factory()->librarian()->create(), $borrowing))
        ->toThrow(RuntimeException::class, 'Simulated failure after update');

    expect($borrowing->fresh())
        ->returned_at->toBeNull()
        ->status->toBe(BorrowingStatus::Borrowed);
});
