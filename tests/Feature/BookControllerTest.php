<?php

use App\Models\Book;
use App\Models\Category;
use App\Models\User;
use Database\Seeders\BookSeeder;
use Database\Seeders\CategorySeeder;

test('home page renders through the BookNest layout', function () {
    $this->get(route('home'))
        ->assertOk()
        ->assertSee('Welcome to BookNest')
        ->assertSee('<footer>', false)
        ->assertSee('&copy; 2026 BookNest Library System', false);
});

test('seeders create exactly the three categories and five books from Assignment 1', function () {
    $this->seed([CategorySeeder::class, BookSeeder::class]);

    expect(Category::orderBy('id')->pluck('name', 'id')->all())
        ->toBe([1 => 'Fiction', 2 => 'Technology', 3 => 'Biography']);

    expect(Book::orderBy('id')->get(['category_id', 'title', 'author', 'year_published', 'price'])->toArray())
        ->toBe([
            ['category_id' => 1, 'title' => 'The Silent Patient', 'author' => 'Alex Michaelides', 'year_published' => 2019, 'price' => '29.90'],
            ['category_id' => 2, 'title' => 'Laravel: Up & Running', 'author' => 'Matt Stauffer', 'year_published' => 2022, 'price' => '120.00'],
            ['category_id' => 2, 'title' => 'Clean Code', 'author' => 'Robert C. Martin', 'year_published' => 2008, 'price' => '150.50'],
            ['category_id' => 3, 'title' => 'Steve Jobs', 'author' => 'Walter Isaacson', 'year_published' => 2011, 'price' => '75.00'],
            ['category_id' => 1, 'title' => 'Where the Crawdads Sing', 'author' => 'Delia Owens', 'year_published' => 2018, 'price' => '45.00'],
        ]);
});

test('category and book relationships resolve each other', function () {
    $category = Category::factory()->create();
    $books = Book::factory()->count(2)->for($category)->create();

    expect($category->books->pluck('id')->all())->toBe($books->pluck('id')->all())
        ->and($books->first()->category->is($category))->toBeTrue();
});

test('deleting a category cascades to its books', function () {
    $category = Category::factory()->create();
    Book::factory()->count(2)->for($category)->create();

    $category->delete();

    expect(Book::count())->toBe(0);
});

test('index lists books with their category name', function () {
    $book = Book::factory()->for(Category::factory()->state(['name' => 'Technology']))->create();

    $this->get(route('books.index'))
        ->assertOk()
        ->assertSee($book->title)
        ->assertSee('Technology');
});

test('create form lists categories', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->librarian()->create())
        ->get(route('books.create'))
        ->assertOk()
        ->assertSee($category->name);
});

test('show displays book details with category name and category book count', function () {
    $category = Category::factory()->create(['name' => 'Technology']);
    $book = Book::factory()->for($category)->create(['price' => 150.50]);
    Book::factory()->for($category)->create();

    $this->get(route('books.show', $book))
        ->assertOk()
        ->assertSeeInOrder(['Category', 'Technology'])
        ->assertSee('RM 150.50')
        ->assertSeeInOrder(['Books in this category', '2']);
});

test('show returns 404 for a missing book', function () {
    $this->get(route('books.show', 999))->assertNotFound();
});

test('edit form is pre-filled with the book values', function () {
    $book = Book::factory()->create();

    $this->actingAs(User::factory()->librarian()->create())
        ->get(route('books.edit', $book))
        ->assertOk()
        ->assertSee('value="'.$book->title.'"', false);
});

test('store rejects an empty submission', function () {
    $this->actingAs(User::factory()->librarian()->create())
        ->post(route('books.store'), [])
        ->assertSessionHasErrors(['category_id', 'title', 'author', 'year_published', 'price']);

    expect(Book::count())->toBe(0);
});

test('store rejects a category that does not exist', function () {
    $this->actingAs(User::factory()->librarian()->create())->post(route('books.store'), [
        'category_id' => 999,
        'title' => 'Orphan',
        'author' => 'Nobody',
        'year_published' => 2020,
        'price' => 10,
    ])->assertSessionHasErrors(['category_id' => 'The selected category id is invalid.']);
});

test('store saves a valid book and redirects to the index', function () {
    $category = Category::factory()->create();

    $this->actingAs(User::factory()->librarian()->create())->post(route('books.store'), [
        'category_id' => $category->id,
        'title' => 'Refactoring',
        'author' => 'Martin Fowler',
        'year_published' => 2018,
        'price' => '89.90',
    ])
        ->assertRedirect(route('books.index'))
        ->assertSessionHas('success', 'Book added successfully.');

    $this->assertDatabaseHas('books', [
        'category_id' => $category->id,
        'title' => 'Refactoring',
        'author' => 'Martin Fowler',
        'year_published' => 2018,
        'price' => 89.90,
    ]);
});

test('update rejects an invalid price', function () {
    $book = Book::factory()->create(['price' => 20]);

    $this->actingAs(User::factory()->librarian()->create())
        ->put(route('books.update', $book), [...$book->only(['category_id', 'title', 'author', 'year_published']), 'price' => 'free'])
        ->assertSessionHasErrors(['price' => 'The price field must be a number.']);

    expect($book->fresh()->price)->toBe('20.00');
});

test('update saves changes and redirects to the book page', function () {
    $book = Book::factory()->create();

    $this->actingAs(User::factory()->librarian()->create())
        ->put(route('books.update', $book), [...$book->only(['category_id', 'title', 'author', 'year_published']), 'price' => '99.00'])
        ->assertRedirect(route('books.show', $book))
        ->assertSessionHas('success', 'Book updated successfully.');

    expect($book->fresh()->price)->toBe('99.00');
});

test('destroy deletes the book and redirects to the index', function () {
    $book = Book::factory()->create();

    $this->actingAs(User::factory()->admin()->create())
        ->delete(route('books.destroy', $book))
        ->assertRedirect(route('books.index'))
        ->assertSessionHas('success', 'Book deleted successfully.');

    $this->assertModelMissing($book);
});
