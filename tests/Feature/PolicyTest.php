<?php

use App\Models\Author;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\Publisher;
use App\Models\Reservation;
use App\Models\User;

/**
 * Every BookController mutation, as [method, route name, needs a book].
 */
dataset('book mutations', [
    'create form' => ['get', 'books.create', false],
    'store' => ['post', 'books.store', false],
    'edit form' => ['get', 'books.edit', true],
    'update' => ['put', 'books.update', true],
    'destroy' => ['delete', 'books.destroy', true],
]);

test('guests are sent to log in before any book mutation', function (string $method, string $routeName, bool $needsBook) {
    $book = Book::factory()->create();

    $this->call($method, route($routeName, $needsBook ? $book : []))
        ->assertRedirect(route('login'));

    expect(Book::count())->toBe(1);
})->with('book mutations');

test('students are forbidden from every book mutation', function (string $method, string $routeName, bool $needsBook) {
    $book = Book::factory()->create(['title' => 'Original Title']);

    $this->actingAs(User::factory()->create())
        ->call($method, route($routeName, $needsBook ? $book : []), [...$book->toArray(), 'title' => 'Changed'])
        ->assertForbidden();

    expect(Book::sole()->title)->toBe('Original Title');
})->with('book mutations');

test('guests and students can still browse the catalogue without edit controls', function () {
    $book = Book::factory()->create();

    $this->get(route('books.index'))
        ->assertOk()
        ->assertSee($book->title)
        ->assertDontSee(route('books.edit', $book))
        ->assertDontSee(route('books.create'));

    $this->actingAs(User::factory()->create())
        ->get(route('books.show', $book))
        ->assertOk()
        ->assertDontSee(route('books.edit', $book))
        ->assertDontSee('Delete');
});

test('librarians see edit controls but not delete', function () {
    $book = Book::factory()->create();

    $this->actingAs(User::factory()->librarian()->create())
        ->get(route('books.show', $book))
        ->assertOk()
        ->assertSee(route('books.edit', $book))
        ->assertDontSee('Delete');
});

test('librarians cannot delete books', function () {
    $book = Book::factory()->create();

    $this->actingAs(User::factory()->librarian()->create())
        ->delete(route('books.destroy', $book))
        ->assertForbidden();

    $this->assertModelExists($book);
});

test('admins cannot delete a book that has borrowing history', function () {
    $book = Borrowing::factory()->returned()->create()->book;

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('books.destroy', $book))
        ->assertForbidden();

    $this->assertModelExists($book);
});

test('unverified staff are sent to verify their email before mutating books', function () {
    $this->actingAs(User::factory()->admin()->unverified()->create())
        ->get(route('books.create'))
        ->assertRedirect(route('verification.notice'));
});

test('author and publisher records are managed by staff and deleted only by admins', function (string $ability, bool $admin, bool $librarian, bool $student) {
    $users = [
        'admin' => User::factory()->admin()->create(),
        'librarian' => User::factory()->librarian()->create(),
        'student' => User::factory()->create(),
    ];

    foreach ([Author::factory()->create(), Publisher::factory()->create()] as $record) {
        $target = $ability === 'create' ? $record::class : $record;

        expect($users['admin']->can($ability, $target))->toBe($admin)
            ->and($users['librarian']->can($ability, $target))->toBe($librarian)
            ->and($users['student']->can($ability, $target))->toBe($student);
    }
})->with([
    'create' => ['create', true, true, false],
    'update' => ['update', true, true, false],
    'delete' => ['delete', true, false, false],
]);

test('students may only view their own borrowings while staff manage all of them', function () {
    $borrower = User::factory()->create();
    $borrowing = Borrowing::factory()->for($borrower)->create();
    $otherStudent = User::factory()->create();
    $librarian = User::factory()->librarian()->create();

    expect($borrower->can('view', $borrowing))->toBeTrue()
        ->and($otherStudent->can('view', $borrowing))->toBeFalse()
        ->and($borrower->can('viewAny', Borrowing::class))->toBeFalse()
        ->and($borrower->can('create', Borrowing::class))->toBeFalse()
        ->and($borrower->can('update', $borrowing))->toBeFalse()
        ->and($librarian->can('viewAny', Borrowing::class))->toBeTrue()
        ->and($librarian->can('create', Borrowing::class))->toBeTrue()
        ->and($librarian->can('update', $borrowing))->toBeTrue()
        ->and($librarian->can('delete', $borrowing))->toBeFalse();
});

test('students request and cancel their own active reservations while staff review them', function () {
    $student = User::factory()->create();
    $pending = Reservation::factory()->for($student)->create();
    $approved = Reservation::factory()->for($student)->approved()->create();
    $rejected = Reservation::factory()->for($student)->rejected()->create();
    $otherStudent = User::factory()->create();
    $librarian = User::factory()->librarian()->create();

    expect($student->can('create', Reservation::class))->toBeTrue()
        ->and($librarian->can('create', Reservation::class))->toBeFalse()
        ->and($student->can('cancel', $pending))->toBeTrue()
        ->and($student->can('cancel', $approved))->toBeTrue()
        ->and($student->can('cancel', $rejected))->toBeFalse()
        ->and($librarian->can('cancel', $approved))->toBeTrue()
        ->and($librarian->can('cancel', $pending))->toBeFalse()
        ->and($otherStudent->can('cancel', $pending))->toBeFalse()
        ->and($otherStudent->can('view', $pending))->toBeFalse()
        ->and($student->can('review', $pending))->toBeFalse()
        ->and($librarian->can('review', $pending))->toBeTrue()
        ->and($librarian->can('review', $approved))->toBeFalse();
});
