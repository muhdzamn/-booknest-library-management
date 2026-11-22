<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Livewire\Forms;

use App\Models\Author;
use App\Models\Book;
use Illuminate\Validation\Rule;
use Livewire\Form;

/**
 * Holds and validates the add/edit book fields. Persistence is delegated to
 * the Create/Update book Actions, which also perform authorization.
 */
class BookForm extends Form
{
    /**
     * The book being edited, or null when adding a new one.
     */
    public ?Book $book = null;

    public string $title = '';

    /**
     * The Assignment 1 author display string. Still required.
     */
    public string $author = '';

    /**
     * Select and number inputs arrive as strings, and Livewire turns an
     * emptied field into null, so these accept string, number or null.
     */
    public string|int|null $category_id = '';

    public string|int|null $author_id = '';

    public string|int|null $publisher_id = '';

    public string|int|null $year_published = '';

    public string|int|float|null $price = '';

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'author' => ['required', 'string', 'max:255'],
            'category_id' => ['required', 'integer', Rule::exists('categories', 'id')],
            'author_id' => ['nullable', 'integer', Rule::exists('authors', 'id')],
            'publisher_id' => ['nullable', 'integer', Rule::exists('publishers', 'id')],
            'year_published' => ['required', 'integer', 'min:1900', 'max:'.(now()->year + 1)],
            'price' => ['required', 'numeric', 'min:0', 'max:999999.99'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'category_id' => 'category',
            'author_id' => 'linked author',
            'publisher_id' => 'publisher',
            'year_published' => 'year published',
        ];
    }

    /**
     * Load an existing book into the form for editing.
     */
    public function setBook(Book $book): void
    {
        $this->resetErrorBag();

        $this->book = $book;
        $this->title = $book->title;
        $this->author = $book->author;
        $this->category_id = $book->category_id;
        $this->author_id = $book->author_id ?? '';
        $this->publisher_id = $book->publisher_id ?? '';
        $this->year_published = $book->year_published;
        $this->price = $book->price;
    }

    /**
     * Clear the form for adding a new book.
     */
    public function clear(): void
    {
        $this->reset();
        $this->resetErrorBag();
    }

    /**
     * When a linked author is picked and the display name is still empty,
     * pre-fill it so staff do not have to type the same name twice.
     */
    public function updatedAuthorId(string|int|null $authorId): void
    {
        if (blank($this->author) && filled($authorId)) {
            $this->author = Author::find($authorId)->name ?? '';
        }
    }

    /**
     * Validate and return attributes ready for the book Actions.
     *
     * @return array{category_id: int, title: string, author: string, author_id: int|null, publisher_id: int|null, year_published: int, price: string}
     */
    public function validatedAttributes(): array
    {
        $validated = $this->validate();

        return [
            'category_id' => (int) $validated['category_id'],
            'title' => trim($validated['title']),
            'author' => trim($validated['author']),
            'author_id' => filled($validated['author_id']) ? (int) $validated['author_id'] : null,
            'publisher_id' => filled($validated['publisher_id']) ? (int) $validated['publisher_id'] : null,
            'year_published' => (int) $validated['year_published'],
            'price' => number_format((float) $validated['price'], 2, '.', ''),
        ];
    }
}
