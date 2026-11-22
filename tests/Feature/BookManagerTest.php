<?php

use App\Livewire\Books\BookManager;
use App\Models\Author;
use App\Models\Book;
use App\Models\Borrowing;
use App\Models\Category;
use App\Models\Publisher;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\Route;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * @return array<string, mixed>
 */
function validBookInput(array $overrides = []): array
{
    return [
        'title' => 'Refactoring',
        'author' => 'Martin Fowler',
        'category_id' => Category::factory()->create()->id,
        'author_id' => '',
        'publisher_id' => '',
        'year_published' => 2018,
        'price' => '89.90',
        ...$overrides,
    ];
}

function fillBookForm(Testable $component, array $input): Testable
{
    foreach ($input as $field => $value) {
        $component->set("form.{$field}", $value);
    }

    return $component;
}

// --- Access -----------------------------------------------------------------

test('guests are redirected to login from the management module', function () {
    $this->get(route('books.manage'))->assertRedirect(route('login'));
});

test('unverified users are sent to verify their email', function () {
    $this->actingAs(User::factory()->unverified()->create())
        ->get(route('books.manage'))
        ->assertRedirect(route('verification.notice'));
});

test('every role can open the management page and the sidebar links to it', function (Closure $makeUser) {
    $book = Book::factory()->create();

    $this->actingAs($makeUser())
        ->get(route('books.manage'))
        ->assertOk()
        ->assertSeeLivewire(BookManager::class)
        ->assertSee($book->title)
        ->assertSee('Book Management');
})->with([
    'admin' => [fn () => User::factory()->admin()->create()],
    'librarian' => [fn () => User::factory()->librarian()->create()],
    'student' => [fn () => User::factory()->create()],
]);

// --- Admin ------------------------------------------------------------------

test('admins can add a book with linked author and publisher', function () {
    $author = Author::factory()->create();
    $publisher = Publisher::factory()->create();
    $input = validBookInput(['author_id' => $author->id, 'publisher_id' => $publisher->id]);

    $component = Livewire::actingAs(User::factory()->admin()->create())
        ->test(BookManager::class)
        ->call('create');

    fillBookForm($component, $input)
        ->call('save')
        ->assertHasNoErrors()
        ->assertSet('alert.variant', 'success')
        ->assertSee('"Refactoring" was added to the catalogue.');

    $book = Book::sole();

    expect($book->only(['title', 'author', 'category_id', 'author_id', 'publisher_id', 'year_published', 'price']))
        ->toBe([
            'title' => 'Refactoring',
            'author' => 'Martin Fowler',
            'category_id' => $input['category_id'],
            'author_id' => $author->id,
            'publisher_id' => $publisher->id,
            'year_published' => 2018,
            'price' => '89.90',
        ]);
});

test('leaving author and publisher empty stores null links', function () {
    $component = Livewire::actingAs(User::factory()->admin()->create())->test(BookManager::class);

    fillBookForm($component, validBookInput())->call('save')->assertHasNoErrors();

    expect(Book::sole())
        ->author_id->toBeNull()
        ->publisher_id->toBeNull();
});

test('picking a linked author pre-fills an empty author name', function () {
    $author = Author::factory()->create(['name' => 'Kent Beck']);

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BookManager::class)
        ->call('create')
        ->set('form.author_id', $author->id)
        ->assertSet('form.author', 'Kent Beck');
});

test('admins can delete a book with no circulation history', function () {
    $book = Book::factory()->create();

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(BookManager::class)
        ->call('confirmDelete', $book->id)
        ->assertSet('deletingBookId', $book->id)
        ->call('delete')
        ->assertSet('alert.variant', 'success');

    $this->assertModelMissing($book);
});

test('admins cannot delete a book with borrowing or reservation history', function (Closure $makeHistory) {
    $book = $makeHistory()->book;

    Livewire::actingAs(User::factory()->admin()->create())
        ->test(BookManager::class)
        ->call('confirmDelete', $book->id)
        ->assertSet('deletingBookId', null)
        ->assertSet('alert.variant', 'danger')
        ->assertSee('borrowing or reservation history');

    $this->assertModelExists($book);
})->with([
    'returned borrowing' => [fn () => Borrowing::factory()->returned()->create()],
    'pending reservation' => [fn () => Reservation::factory()->create()],
]);

// --- Librarian --------------------------------------------------------------

test('librarians can add and edit books', function () {
    $librarian = User::factory()->librarian()->create();
    $book = Book::factory()->create(['title' => 'Old Title']);

    $component = Livewire::actingAs($librarian)->test(BookManager::class);
    fillBookForm($component, validBookInput(['title' => 'Brand New']))->call('save')->assertHasNoErrors();

    Livewire::actingAs($librarian)
        ->test(BookManager::class)
        ->call('edit', $book->id)
        ->assertSet('form.title', 'Old Title')
        ->set('form.title', 'New Title')
        ->call('save')
        ->assertHasNoErrors()
        ->assertSee('"New Title" was updated.');

    expect($book->fresh()->title)->toBe('New Title')
        ->and(Book::where('title', 'Brand New')->exists())->toBeTrue();
});

test('librarians cannot delete books', function () {
    $book = Book::factory()->create();

    Livewire::actingAs(User::factory()->librarian()->create())
        ->test(BookManager::class)
        ->assertDontSeeHtml('wire:click="confirmDelete('.$book->id.')"')
        ->call('confirmDelete', $book->id)
        ->assertSet('deletingBookId', null)
        ->assertSee('Only administrators can delete books.');

    $this->assertModelExists($book);
});

// --- Student ----------------------------------------------------------------

test('students see the catalogue without management controls', function () {
    $book = Book::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(BookManager::class)
        ->assertSee($book->title)
        ->assertSee('read-only access')
        ->assertDontSee('Add Book')
        ->assertDontSeeHtml('wire:click="edit('.$book->id.')"')
        ->assertDontSeeHtml('wire:click="confirmDelete('.$book->id.')"')
        ->call('show', $book->id)
        ->assertSet('viewingBookId', $book->id);
});

test('students are forbidden from opening the add or edit forms', function (string $method, bool $needsBook) {
    $book = Book::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(BookManager::class)
        ->call($method, ...($needsBook ? [$book->id] : []))
        ->assertForbidden();
})->with([
    'create' => ['create', false],
    'edit' => ['edit', true],
]);

test('students cannot save a book even by calling save directly', function () {
    $component = Livewire::actingAs(User::factory()->create())->test(BookManager::class);

    fillBookForm($component, validBookInput())->call('save')->assertForbidden();

    expect(Book::count())->toBe(0);
});

test('students cannot delete books', function () {
    $book = Book::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test(BookManager::class)
        ->call('confirmDelete', $book->id)
        ->assertSet('deletingBookId', null)
        ->call('delete')
        ->assertSet('alert.variant', 'danger');

    $this->assertModelExists($book);
});

// --- Validation -------------------------------------------------------------

test('the book form rejects invalid input', function (array $overrides, string $field, string $rule) {
    $component = Livewire::actingAs(User::factory()->admin()->create())->test(BookManager::class);

    fillBookForm($component, validBookInput($overrides))
        ->call('save')
        ->assertHasErrors(["form.{$field}" => $rule]);

    expect(Book::count())->toBe(0);
})->with([
    'missing title' => [['title' => ''], 'title', 'required'],
    'missing author name' => [['author' => ''], 'author', 'required'],
    'missing category' => [['category_id' => ''], 'category_id', 'required'],
    'unknown category' => [['category_id' => 999], 'category_id', 'exists'],
    'unknown linked author' => [['author_id' => 999], 'author_id', 'exists'],
    'unknown publisher' => [['publisher_id' => 999], 'publisher_id', 'exists'],
    'year too early' => [['year_published' => 1899], 'year_published', 'min'],
    'year in the far future' => [['year_published' => now()->year + 2], 'year_published', 'max'],
    'negative price' => [['price' => '-1'], 'price', 'min'],
    'non-numeric price' => [['price' => 'free'], 'price', 'numeric'],
]);

// --- Search, filter and pagination -------------------------------------------

test('search matches title and author name', function () {
    Book::factory()->create(['title' => 'Clean Code', 'author' => 'Robert C. Martin']);
    Book::factory()->create(['title' => 'Steve Jobs', 'author' => 'Walter Isaacson']);

    Livewire::actingAs(User::factory()->create())
        ->test(BookManager::class)
        ->set('search', 'clean')
        ->assertSee('Clean Code')
        ->assertDontSee('Steve Jobs')
        ->set('search', 'isaacson')
        ->assertSee('Steve Jobs')
        ->assertDontSee('Clean Code')
        ->set('search', 'no such book')
        ->assertSee('No books match your search.');
});

test('category filter shows only books in that category and combines with search', function () {
    $fiction = Category::factory()->create(['name' => 'Fiction']);
    $technology = Category::factory()->create(['name' => 'Technology']);
    Book::factory()->for($fiction)->create(['title' => 'The Silent Patient']);
    Book::factory()->for($technology)->create(['title' => 'Clean Code']);
    Book::factory()->for($technology)->create(['title' => 'Laravel: Up & Running']);

    Livewire::actingAs(User::factory()->create())
        ->test(BookManager::class)
        ->set('categoryFilter', (string) $technology->id)
        ->assertSee('Clean Code')
        ->assertSee('Laravel: Up &amp; Running', false)
        ->assertDontSee('The Silent Patient')
        ->set('search', 'laravel')
        ->assertDontSee('Clean Code')
        ->assertSee('Laravel: Up &amp; Running', false);
});

test('books are paginated at 10 or 5 per page', function () {
    $category = Category::factory()->create();
    foreach (range(1, 12) as $number) {
        Book::factory()->for($category)->create(['title' => sprintf('Paged Book %02d', $number)]);
    }

    Livewire::actingAs(User::factory()->create())
        ->test(BookManager::class)
        ->assertSee('Paged Book 10')
        ->assertDontSee('Paged Book 11')
        ->set('perPage', 5)
        ->assertSee('Paged Book 05')
        ->assertDontSee('Paged Book 06')
        ->call('nextPage')
        ->assertSee('Paged Book 06')
        ->assertDontSee('Paged Book 05')
        ->set('search', 'Paged')
        ->assertSee('Paged Book 01');
});

test('an unsupported page size falls back to 10', function () {
    Livewire::actingAs(User::factory()->create())
        ->test(BookManager::class)
        ->set('perPage', 500)
        ->assertSet('perPage', 10);
});

// --- Relationships ----------------------------------------------------------

test('the list and details show category, linked author and publisher names', function () {
    $book = Book::factory()
        ->for(Category::factory()->state(['name' => 'Technology']))
        ->create([
            'author' => 'Robert C. Martin',
            'author_id' => Author::factory()->state(['name' => 'Robert C. Martin', 'biography' => 'Known as Uncle Bob.']),
            'publisher_id' => Publisher::factory()->state(['name' => 'Prentice Hall']),
        ]);
    Borrowing::factory()->returned()->for($book)->create();

    Livewire::actingAs(User::factory()->create())
        ->test(BookManager::class)
        ->assertSee('Technology')
        ->assertSee('Prentice Hall')
        ->call('show', $book->id)
        ->assertSee('Known as Uncle Bob.')
        ->assertSeeInOrder(['Borrowings', '1']);
});

// --- Assignment 1 preservation -----------------------------------------------

test('the seven Assignment 1 resource routes still point at BookController', function () {
    $expected = [
        'books.index' => ['GET', 'books', 'index'],
        'books.create' => ['GET', 'books/create', 'create'],
        'books.store' => ['POST', 'books', 'store'],
        'books.show' => ['GET', 'books/{book}', 'show'],
        'books.edit' => ['GET', 'books/{book}/edit', 'edit'],
        'books.update' => ['PUT', 'books/{book}', 'update'],
        'books.destroy' => ['DELETE', 'books/{book}', 'destroy'],
    ];

    foreach ($expected as $name => [$method, $uri, $action]) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)->not->toBeNull()
            ->and($route->methods())->toContain($method)
            ->and($route->uri())->toBe($uri)
            ->and($route->getActionName())->toBe("App\\Http\\Controllers\\BookController@{$action}");
    }

    expect(Route::getRoutes()->getByName('books.manage')->uri())->toBe('manage/books');
});

test('the public Assignment 1 catalogue still works for guests', function () {
    $book = Book::factory()->create();

    $this->get(route('books.index'))->assertOk()->assertSee($book->title);
    $this->get(route('books.show', $book))->assertOk()->assertSee($book->title);
});
