<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ __('Book Management') }}</flux:heading>
            <flux:subheading>
                @can('create', App\Models\Book::class)
                    {{ __('Add, edit and maintain the BookNest catalogue.') }}
                @else
                    {{ __('Browse the BookNest catalogue. You have read-only access.') }}
                @endcan
            </flux:subheading>
        </div>

        @can('create', App\Models\Book::class)
            <flux:button variant="primary" icon="plus" wire:click="create" data-test="add-book-button">
                {{ __('Add Book') }}
            </flux:button>
        @endcan
    </div>

    <x-flash-alert :alert="$alert" />

    {{-- Filters --}}
    <div class="grid gap-3 sm:grid-cols-[1fr_14rem_8rem] sm:items-end">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            icon="magnifying-glass"
            :placeholder="__('Search by title or author...')"
            clearable
        />

        <flux:select wire:model.live="categoryFilter" :label="__('Category')">
            <flux:select.option value="">{{ __('All categories') }}</flux:select.option>
            @foreach ($this->categories as $category)
                <flux:select.option value="{{ $category->id }}" wire:key="filter-category-{{ $category->id }}">{{ $category->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="perPage" :label="__('Per page')">
            @foreach (App\Livewire\Books\BookManager::PER_PAGE_OPTIONS as $option)
                <flux:select.option value="{{ $option }}" wire:key="per-page-{{ $option }}">{{ $option }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 px-4 dark:border-zinc-700">
        <flux:table :paginate="$this->books">
            <flux:table.columns>
                <flux:table.column>#</flux:table.column>
                <flux:table.column>{{ __('Title') }}</flux:table.column>
                <flux:table.column>{{ __('Author') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Publisher') }}</flux:table.column>
                <flux:table.column>{{ __('Category') }}</flux:table.column>
                <flux:table.column class="max-sm:hidden">{{ __('Year') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Price (RM)') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->books as $book)
                    <flux:table.row wire:key="book-{{ $book->id }}">
                        <flux:table.cell>{{ $book->id }}</flux:table.cell>
                        <flux:table.cell variant="strong" class="max-w-64 truncate">{{ $book->title }}</flux:table.cell>
                        <flux:table.cell>{{ $book->author }}</flux:table.cell>
                        <flux:table.cell class="max-md:hidden">{{ $book->publisher?->name ?? '—' }}</flux:table.cell>
                        <flux:table.cell>
                            <flux:badge size="sm" color="indigo" inset="top bottom">{{ $book->category->name }}</flux:badge>
                        </flux:table.cell>
                        <flux:table.cell class="max-sm:hidden">{{ $book->year_published }}</flux:table.cell>
                        <flux:table.cell align="end">{{ number_format((float) $book->price, 2) }}</flux:table.cell>
                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                <flux:button size="sm" variant="ghost" icon="eye" wire:click="show({{ $book->id }})" :aria-label="__('View :title', ['title' => $book->title])" />

                                @can('update', $book)
                                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $book->id }})" :aria-label="__('Edit :title', ['title' => $book->title])" />
                                @endcan

                                @can('delete', $book)
                                    <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600! dark:text-red-400!" wire:click="confirmDelete({{ $book->id }})" :aria-label="__('Delete :title', ['title' => $book->title])" />
                                @elsecan('update', $book)
                                    @if (auth()->user()->isAdmin() && $book->hasCirculationHistory())
                                        <flux:tooltip :content="__('Has borrowing or reservation history')">
                                            <flux:button size="sm" variant="ghost" icon="lock-closed" disabled :aria-label="__('Deletion locked')" />
                                        </flux:tooltip>
                                    @endif
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell colspan="8" class="py-10 text-center">
                            <flux:text>{{ __('No books match your search.') }}</flux:text>
                            @if ($search !== '' || $categoryFilter !== '')
                                <flux:button size="sm" variant="ghost" class="mt-2" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- Add / Edit modal --}}
    {{-- Only staff receive the form markup; the Actions enforce this server-side too. --}}
    @can('create', App\Models\Book::class)
    <flux:modal name="book-form" class="w-full md:w-[36rem]">
        <form wire:submit="save" class="space-y-5">
            <div>
                <flux:heading size="lg">{{ $form->book ? __('Edit Book') : __('Add Book') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Fields marked * are required.') }}</flux:text>
            </div>

            <flux:input wire:model="form.title" :label="__('Title *')" required />

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model.live="form.author_id" :label="__('Linked author')">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->authors as $authorOption)
                        <flux:select.option value="{{ $authorOption->id }}" wire:key="author-option-{{ $authorOption->id }}">{{ $authorOption->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:input wire:model="form.author" :label="__('Author name *')" :description:trailing="__('Shown in the public catalogue.')" required />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="form.category_id" :label="__('Category *')" required>
                    <flux:select.option value="">{{ __('Select a category') }}</flux:select.option>
                    @foreach ($this->categories as $category)
                        <flux:select.option value="{{ $category->id }}" wire:key="form-category-{{ $category->id }}">{{ $category->name }}</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="form.publisher_id" :label="__('Publisher')">
                    <flux:select.option value="">{{ __('— None —') }}</flux:select.option>
                    @foreach ($this->publishers as $publisherOption)
                        <flux:select.option value="{{ $publisherOption->id }}" wire:key="publisher-option-{{ $publisherOption->id }}">{{ $publisherOption->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="form.year_published" type="number" min="1900" :max="now()->year + 1" :label="__('Year published *')" required />
                <flux:input wire:model="form.price" type="number" step="0.01" min="0" :label="__('Price (RM) *')" required />
            </div>

            <div class="flex justify-end gap-2">
                <flux:modal.close>
                    <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                </flux:modal.close>
                <flux:button type="submit" variant="primary" data-test="save-book-button">
                    {{ $form->book ? __('Save changes') : __('Add Book') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>
    @endcan

    {{-- Details modal --}}
    <flux:modal name="book-details" class="w-full md:w-[32rem]">
        @if ($book = $this->viewingBook)
            <div class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ $book->title }}</flux:heading>
                    <flux:text class="mt-1">{{ $book->author }}</flux:text>
                </div>

                <dl class="grid grid-cols-[9rem_1fr] gap-x-4 gap-y-3 text-sm">
                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Category') }}</dt>
                    <dd><flux:badge size="sm" color="indigo">{{ $book->category->name }}</flux:badge></dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Linked author') }}</dt>
                    <dd class="text-zinc-800 dark:text-white">{{ $book->authorProfile?->name ?? __('Not linked') }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Publisher') }}</dt>
                    <dd class="text-zinc-800 dark:text-white">{{ $book->publisher?->name ?? __('Not linked') }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Year published') }}</dt>
                    <dd class="text-zinc-800 dark:text-white">{{ $book->year_published }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Price') }}</dt>
                    <dd class="text-zinc-800 dark:text-white">RM {{ number_format((float) $book->price, 2) }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Borrowings') }}</dt>
                    <dd class="text-zinc-800 dark:text-white">{{ $book->borrowings_count }}</dd>

                    <dt class="text-zinc-500 dark:text-zinc-400">{{ __('Reservations') }}</dt>
                    <dd class="text-zinc-800 dark:text-white">{{ $book->reservations_count }}</dd>
                </dl>

                @if ($book->authorProfile?->biography)
                    <div>
                        <flux:heading size="sm">{{ __('About the author') }}</flux:heading>
                        <flux:text class="mt-1">{{ $book->authorProfile->biography }}</flux:text>
                    </div>
                @endif

                <div class="flex justify-end gap-2">
                    <flux:button variant="ghost" :href="route('books.show', $book)">{{ __('Public page') }}</flux:button>
                    <flux:modal.close>
                        <flux:button variant="primary">{{ __('Close') }}</flux:button>
                    </flux:modal.close>
                </div>
            </div>
        @endif
    </flux:modal>

    {{-- Delete confirmation modal --}}
    <flux:modal name="confirm-delete" class="w-full md:w-[28rem]">
        @if ($book = $this->deletingBook)
            {{-- Alpine.js guard: Delete stays disabled until the box is ticked --}}
            <div class="space-y-5" x-data="{ confirmed: false }" wire:key="confirm-delete-{{ $book->id }}">
                <div>
                    <flux:heading size="lg">{{ __('Delete book?') }}</flux:heading>
                    <flux:text class="mt-2">
                        {{ __('":title" will be permanently removed from the catalogue. This cannot be undone.', ['title' => $book->title]) }}
                    </flux:text>
                </div>

                <label class="flex items-center gap-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <input type="checkbox" x-model="confirmed" class="size-4 rounded border-zinc-300 accent-red-600 dark:border-zinc-600">
                    {{ __('I understand this is permanent') }}
                </label>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="delete" x-bind:disabled="! confirmed" data-test="confirm-delete-button">
                        {{ __('Delete') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
