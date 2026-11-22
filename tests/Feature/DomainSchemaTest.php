<?php

use App\Enums\BorrowingStatus;
use App\Enums\ReservationStatus;
use App\Enums\UserRole;
use App\Models\Author;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Reservation;
use App\Models\User;
use Database\Seeders\AuthorSeeder;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\PublisherSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;

test('books keep the Assignment 1 author column and gain nullable author and publisher keys', function () {
    expect(Schema::hasColumns('books', ['category_id', 'title', 'author', 'year_published', 'price', 'author_id', 'publisher_id']))->toBeTrue();

    $book = Book::factory()->create();

    expect($book->fresh()->author_id)->toBeNull()
        ->and($book->fresh()->publisher_id)->toBeNull();
});

test('the four new domain tables have their required columns', function (string $table, array $columns) {
    expect(Schema::hasColumns($table, $columns))->toBeTrue();
})->with([
    'authors' => ['authors', ['id', 'name', 'biography', 'created_at', 'updated_at']],
    'publishers' => ['publishers', ['id', 'name', 'email', 'created_at', 'updated_at']],
    'borrowings' => ['borrowings', ['id', 'user_id', 'book_id', 'borrowed_at', 'due_at', 'returned_at', 'status', 'created_at', 'updated_at']],
    'reservations' => ['reservations', ['id', 'user_id', 'book_id', 'status', 'reviewed_by', 'reviewed_at', 'remarks', 'created_at', 'updated_at']],
]);

test('books resolve their author profile and publisher, and both resolve their books', function () {
    $author = Author::factory()->create();
    $publisher = Publisher::factory()->create();
    $book = Book::factory()->create(['author_id' => $author->id, 'publisher_id' => $publisher->id]);

    expect($book->authorProfile->is($author))->toBeTrue()
        ->and($book->publisher->is($publisher))->toBeTrue()
        ->and($author->books->sole()->is($book))->toBeTrue()
        ->and($publisher->books->sole()->is($book))->toBeTrue();
});

test('borrowings link a user and a book in both directions', function () {
    $borrowing = Borrowing::factory()->create();

    expect($borrowing->status)->toBe(BorrowingStatus::Borrowed)
        ->and($borrowing->user->borrowings->sole()->is($borrowing))->toBeTrue()
        ->and($borrowing->book->borrowings->sole()->is($borrowing))->toBeTrue();
});

test('reservations link a user, a book and the reviewing staff member', function () {
    $reservation = Reservation::factory()->approved()->create();

    expect($reservation->status)->toBe(ReservationStatus::Approved)
        ->and($reservation->user->reservations->sole()->is($reservation))->toBeTrue()
        ->and($reservation->book->reservations->sole()->is($reservation))->toBeTrue()
        ->and($reservation->reviewer->reviewedReservations->sole()->is($reservation))->toBeTrue();
});

test('deleting an author or publisher unlinks the book but keeps the author string', function () {
    $book = Book::factory()->create([
        'author' => 'Robert C. Martin',
        'author_id' => Author::factory(),
        'publisher_id' => Publisher::factory(),
    ]);

    $book->authorProfile->delete();
    $book->publisher->delete();

    expect($book->fresh())
        ->author->toBe('Robert C. Martin')
        ->author_id->toBeNull()
        ->publisher_id->toBeNull();
});

test('a book with borrowing history cannot be deleted at the database level', function () {
    $borrowing = Borrowing::factory()->returned()->create();

    expect(fn () => $borrowing->book->delete())->toThrow(QueryException::class);

    $this->assertModelExists($borrowing->book);
});

test('deleting a book removes its reservations', function () {
    $reservation = Reservation::factory()->create();

    $reservation->book->delete();

    $this->assertModelMissing($reservation);
});

test('deleting a user keeps their borrowing and reservation history', function () {
    $user = User::factory()->create();
    $borrowing = Borrowing::factory()->for($user)->returned()->create();
    $reservation = Reservation::factory()->for($user)->rejected()->create();

    $user->delete();

    expect($borrowing->fresh())->not->toBeNull()
        ->user_id->toBeNull()
        ->and($reservation->fresh())->not->toBeNull()
        ->user_id->toBeNull();
});

test('deleting the reviewing staff member keeps the reservation decision', function () {
    $reservation = Reservation::factory()->approved()->create();

    $reservation->reviewer->delete();

    expect($reservation->fresh())
        ->reviewed_by->toBeNull()
        ->status->toBe(ReservationStatus::Approved);
});

test('full seeding keeps the original catalogue and links every book to its author and publisher', function () {
    $this->seed(DatabaseSeeder::class);

    expect(Category::count())->toBe(3)
        ->and(Book::count())->toBe(5)
        ->and(Author::count())->toBe(5)
        ->and(Publisher::count())->toBe(5);

    Book::with(['authorProfile', 'publisher'])->get()->each(function (Book $book) {
        expect($book->authorProfile->name)->toBe($book->author)
            ->and($book->publisher)->not->toBeNull();
    });

    expect(Book::firstWhere('title', 'Clean Code')->publisher->name)->toBe('Prentice Hall');

    expect(User::pluck('role', 'email')->all())->toBe([
        'admin@example.com' => UserRole::Admin,
        'librarian@example.com' => UserRole::Librarian,
        'test@example.com' => UserRole::Student,
    ]);
});

test('author and publisher seeders can be re-run on existing data without duplicates', function () {
    $this->seed(DatabaseSeeder::class);

    $this->seed([AuthorSeeder::class, PublisherSeeder::class]);

    expect(Author::count())->toBe(5)
        ->and(Publisher::count())->toBe(5)
        ->and(Book::count())->toBe(5);
});
