<?php

/**
 * Student Name : 1. MUHAMMAD AKMAL SAFWAN BIN BADRO HISHAM
 *                2. MUHAMMAD AZAMUDDIN BIN AZMAWARIS
 * Student ID   : 1. ____________________
 *                2. ____________________
 */

namespace App\Livewire\Books;

use App\Actions\CreateBookAction;
use App\Actions\DeleteBookAction;
use App\Actions\UpdateBookAction;
use App\Concerns\InteractsWithAlerts;
use App\Livewire\Forms\BookForm;
use App\Models\Author;
use App\Models\Book;
use App\Models\Category;
use App\Models\Publisher;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Authenticated book management screen (/manage/books).
 *
 * This sits alongside the Assignment 1 BookController; it does not replace
 * it. Authorization is enforced here and again inside each Action.
 *
 * @property-read LengthAwarePaginator<int, Book> $books
 * @property-read Collection<int, Category> $categories
 * @property-read Collection<int, Author> $authors
 * @property-read Collection<int, Publisher> $publishers
 * @property-read Book|null $viewingBook
 * @property-read Book|null $deletingBook
 */
#[Title('Book Management')]
class BookManager extends Component
{
    use InteractsWithAlerts, WithPagination;

    /**
     * @var list<int>
     */
    public const PER_PAGE_OPTIONS = [5, 10];

    public BookForm $form;

    #[Url(except: '')]
    public string $search = '';

    #[Url(as: 'category', except: '')]
    public string $categoryFilter = '';

    #[Url(except: 10)]
    public int $perPage = 10;

    #[Locked]
    public ?int $viewingBookId = null;

    #[Locked]
    public ?int $deletingBookId = null;

    public function mount(): void
    {
        Gate::authorize('viewAny', Book::class);
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatedPerPage(): void
    {
        if (! in_array($this->perPage, self::PER_PAGE_OPTIONS, true)) {
            $this->perPage = 10;
        }

        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset('search', 'categoryFilter');
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, Book>
     */
    #[Computed]
    public function books(): LengthAwarePaginator
    {
        $search = trim($this->search);
        $perPage = in_array($this->perPage, self::PER_PAGE_OPTIONS, true) ? $this->perPage : 10;

        return Book::query()
            ->with(['category', 'authorProfile', 'publisher'])
            ->withCount(['borrowings', 'reservations'])
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.addcslashes($search, '%_\\').'%';

                $query->where(function (Builder $query) use ($term): void {
                    $query->where('title', 'like', $term)
                        ->orWhere('author', 'like', $term)
                        ->orWhereHas('authorProfile', fn (Builder $author) => $author->where('name', 'like', $term));
                });
            })
            ->when($this->categoryFilter !== '', fn (Builder $query) => $query->where('category_id', (int) $this->categoryFilter))
            ->orderBy('id')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, Category>
     */
    #[Computed]
    public function categories(): Collection
    {
        return Category::orderBy('name')->get();
    }

    /**
     * @return Collection<int, Author>
     */
    #[Computed]
    public function authors(): Collection
    {
        return Author::orderBy('name')->get(['id', 'name']);
    }

    /**
     * @return Collection<int, Publisher>
     */
    #[Computed]
    public function publishers(): Collection
    {
        return Publisher::orderBy('name')->get(['id', 'name']);
    }

    #[Computed]
    public function viewingBook(): ?Book
    {
        return $this->viewingBookId === null
            ? null
            : Book::with(['category', 'authorProfile', 'publisher'])
                ->withCount(['borrowings', 'reservations'])
                ->find($this->viewingBookId);
    }

    #[Computed]
    public function deletingBook(): ?Book
    {
        return $this->deletingBookId === null ? null : Book::find($this->deletingBookId);
    }

    /**
     * Open the details modal.
     */
    public function show(int $bookId): void
    {
        Gate::authorize('view', Book::findOrFail($bookId));

        $this->viewingBookId = $bookId;
        unset($this->viewingBook);

        Flux::modal('book-details')->show();
    }

    /**
     * Open an empty form for a new book.
     */
    public function create(): void
    {
        Gate::authorize('create', Book::class);

        $this->form->clear();

        Flux::modal('book-form')->show();
    }

    /**
     * Open the form pre-filled with an existing book.
     */
    public function edit(int $bookId): void
    {
        $book = Book::findOrFail($bookId);

        Gate::authorize('update', $book);

        $this->form->setBook($book);

        Flux::modal('book-form')->show();
    }

    /**
     * Persist the form through the matching Action.
     */
    public function save(CreateBookAction $createBook, UpdateBookAction $updateBook): void
    {
        $user = Auth::user();
        $attributes = $this->form->validatedAttributes();

        if ($this->form->book) {
            $book = $updateBook->handle($user, $this->form->book, $attributes);
            $message = "\"{$book->title}\" was updated.";
        } else {
            $book = $createBook->handle($user, $attributes);
            $message = "\"{$book->title}\" was added to the catalogue.";
        }

        $this->form->clear();
        unset($this->books);

        Flux::modal('book-form')->close();

        $this->flash('success', $message);
    }

    /**
     * Ask for confirmation before deleting. Refuses early with a clear message
     * when the user may not delete this book.
     */
    public function confirmDelete(int $bookId): void
    {
        $book = Book::findOrFail($bookId);

        $response = Gate::inspect('delete', $book);

        if ($response->denied()) {
            $this->flash('danger', $response->message() ?? 'You cannot delete this book.');

            return;
        }

        $this->deletingBookId = $bookId;
        unset($this->deletingBook);

        Flux::modal('confirm-delete')->show();
    }

    /**
     * Delete the confirmed book through the Action.
     */
    public function delete(DeleteBookAction $deleteBook): void
    {
        $book = $this->deletingBookId === null ? null : Book::find($this->deletingBookId);

        $this->deletingBookId = null;
        Flux::modal('confirm-delete')->close();

        if ($book === null) {
            $this->flash('danger', 'That book no longer exists.');

            return;
        }

        try {
            $deleteBook->handle(Auth::user(), $book);
        } catch (AuthorizationException $exception) {
            $this->flash('danger', $exception->getMessage());

            return;
        }

        unset($this->books);

        if ($this->books->isEmpty() && $this->getPage() > 1) {
            $this->previousPage();
        }

        $this->flash('success', "\"{$book->title}\" was deleted.");
    }

    public function render(): View
    {
        return view('livewire.books.book-manager');
    }
}
