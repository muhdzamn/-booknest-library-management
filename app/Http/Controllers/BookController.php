<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Category;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class BookController extends Controller implements HasMiddleware
{
    /**
     * Browsing stays public (Assignment 1). Every other action requires a
     * signed-in, verified user; the BookPolicy then decides by role.
     *
     * @return array<int, Middleware>
     */
    public static function middleware(): array
    {
        return [
            new Middleware(['auth', 'verified'], except: ['index', 'show']),
        ];
    }

    /**
     * 1. INDEX -- GET /books
     */
    public function index(): View
    {
        $books = Book::with('category')->orderBy('id')->get();

        return view('books.index', compact('books'));
    }

    /**
     * 2. CREATE -- GET /books/create
     */
    public function create(): View
    {
        Gate::authorize('create', Book::class);

        $categories = Category::all();

        return view('books.create', compact('categories'));
    }

    /**
     * 3. STORE -- POST /books
     */
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create', Book::class);

        $validated = $request->validate($this->rules());

        Book::create($validated);

        return redirect()->route('books.index')
            ->with('success', 'Book added successfully.');
    }

    /**
     * 4. SHOW -- GET /books/{book}
     */
    public function show(Book $book): View
    {
        $book->load('category.books');

        return view('books.show', compact('book'));
    }

    /**
     * 5. EDIT -- GET /books/{book}/edit
     */
    public function edit(Book $book): View
    {
        Gate::authorize('update', $book);

        $categories = Category::all();

        return view('books.edit', compact('book', 'categories'));
    }

    /**
     * 6. UPDATE -- PUT/PATCH /books/{book}
     */
    public function update(Request $request, Book $book): RedirectResponse
    {
        Gate::authorize('update', $book);

        $validated = $request->validate($this->rules());

        $book->update($validated);

        return redirect()->route('books.show', $book->id)
            ->with('success', 'Book updated successfully.');
    }

    /**
     * 7. DESTROY -- DELETE /books/{book}
     */
    public function destroy(Book $book): RedirectResponse
    {
        Gate::authorize('delete', $book);

        $book->delete();

        return redirect()->route('books.index')
            ->with('success', 'Book deleted successfully.');
    }

    /**
     * Validation rules shared by store() and update().
     *
     * @return array<string, string>
     */
    private function rules(): array
    {
        return [
            'category_id' => 'required|exists:categories,id',
            'title' => 'required|string|max:255',
            'author' => 'required|string|max:255',
            'year_published' => 'required|integer|min:1900|max:2100',
            'price' => 'required|numeric|min:0',
        ];
    }
}
