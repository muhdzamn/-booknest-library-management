<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $this->canViewAll ? __('Borrowings') : __('My Borrowings') }}</flux:heading>
            <flux:subheading>
                @if ($this->canViewAll)
                    {{ __('Issue books to students, record returns and track overdue loans.') }}
                @else
                    {{ __('Your borrowing history. Please return books by their due date.') }}
                @endif
            </flux:subheading>
        </div>

        @can('create', App\Models\Borrowing::class)
            <flux:button variant="primary" icon="arrow-up-tray" wire:click="openIssueForm" data-test="issue-book-button">
                {{ __('Issue Book') }}
            </flux:button>
        @endcan
    </div>

    <x-flash-alert :alert="$alert" />

    {{-- Summary --}}
    <div class="grid gap-3 sm:grid-cols-3">
        @foreach ([
            'active' => [__('On loan'), 'text-blue-600 dark:text-blue-400'],
            'overdue' => [__('Overdue'), 'text-red-600 dark:text-red-400'],
            'returned' => [__('Returned'), 'text-emerald-600 dark:text-emerald-400'],
        ] as $key => [$label, $colour])
            <button
                type="button"
                wire:key="stat-{{ $key }}"
                wire:click="$set('status', '{{ $status === $key ? '' : $key }}')"
                @class([
                    'rounded-xl border p-4 text-start transition hover:bg-zinc-50 dark:hover:bg-white/5',
                    'border-zinc-800 dark:border-white' => $status === $key,
                    'border-zinc-200 dark:border-zinc-700' => $status !== $key,
                ])
            >
                <flux:text>{{ $label }}</flux:text>
                <div class="mt-1 text-2xl font-semibold {{ $colour }}">{{ $this->stats[$key] }}</div>
            </button>
        @endforeach
    </div>

    {{-- Filters --}}
    <div class="grid gap-3 sm:grid-cols-[1fr_12rem] sm:items-end">
        <flux:input
            wire:model.live.debounce.300ms="search"
            :label="__('Search')"
            icon="magnifying-glass"
            :placeholder="$this->canViewAll ? __('Book title, author, student name or email...') : __('Book title or author...')"
            clearable
        />

        <flux:select wire:model.live="status" :label="__('Status')">
            <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
            <flux:select.option value="active">{{ __('On loan') }}</flux:select.option>
            <flux:select.option value="overdue">{{ __('Overdue') }}</flux:select.option>
            <flux:select.option value="returned">{{ __('Returned') }}</flux:select.option>
        </flux:select>
    </div>

    {{-- Table --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 px-4 dark:border-zinc-700">
        <flux:table :paginate="$this->borrowings">
            <flux:table.columns>
                <flux:table.column>{{ __('Book') }}</flux:table.column>
                @if ($this->canViewAll)
                    <flux:table.column>{{ __('Student') }}</flux:table.column>
                @endif
                <flux:table.column class="max-sm:hidden">{{ __('Borrowed') }}</flux:table.column>
                <flux:table.column>{{ __('Due') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Returned') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                @if ($this->canViewAll)
                    <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
                @endif
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->borrowings as $borrowing)
                    <flux:table.row wire:key="borrowing-{{ $borrowing->id }}">
                        <flux:table.cell>
                            <div class="font-medium text-zinc-800 dark:text-white">{{ $borrowing->book->title }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $borrowing->book->author }}</div>
                        </flux:table.cell>

                        @if ($this->canViewAll)
                            <flux:table.cell>
                                @if ($borrowing->user)
                                    <div class="text-zinc-800 dark:text-white">{{ $borrowing->user->name }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $borrowing->user->email }}</div>
                                @else
                                    <span class="text-zinc-500 italic">{{ __('Deleted account') }}</span>
                                @endif
                            </flux:table.cell>
                        @endif

                        <flux:table.cell class="max-sm:hidden">{{ $borrowing->borrowed_at->format('j M Y') }}</flux:table.cell>
                        <flux:table.cell>{{ $borrowing->due_at->format('j M Y') }}</flux:table.cell>
                        <flux:table.cell class="max-md:hidden">{{ $borrowing->returned_at?->format('j M Y') ?? '—' }}</flux:table.cell>

                        <flux:table.cell>
                            @if ($borrowing->isReturned())
                                <flux:badge size="sm" color="green" inset="top bottom">{{ __('Returned') }}</flux:badge>
                            @elseif ($borrowing->isOverdue())
                                <flux:badge size="sm" color="red" inset="top bottom">
                                    {{ trans_choice('Overdue :count day|Overdue :count days', max(1, $borrowing->daysOverdue()), ['count' => max(1, $borrowing->daysOverdue())]) }}
                                </flux:badge>
                            @else
                                <flux:badge size="sm" color="blue" inset="top bottom">{{ __('On loan') }}</flux:badge>
                            @endif
                        </flux:table.cell>

                        @if ($this->canViewAll)
                            <flux:table.cell align="end">
                                @can('return', $borrowing)
                                    <flux:button size="sm" icon="arrow-uturn-left" wire:click="confirmReturn({{ $borrowing->id }})">
                                        {{ __('Return') }}
                                    </flux:button>
                                @endcan
                            </flux:table.cell>
                        @endif
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell :colspan="$this->canViewAll ? 7 : 5" class="py-10 text-center">
                            <flux:text>{{ __('No borrowings found.') }}</flux:text>
                            @if ($search !== '' || $status !== '')
                                <flux:button size="sm" variant="ghost" class="mt-2" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    @can('create', App\Models\Borrowing::class)
        {{-- Issue modal. Alpine.js shows the loan length live as the due date changes. --}}
        <flux:modal name="issue-book" class="w-full md:w-[32rem]">
            <form wire:submit="issue" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('Issue Book') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('Only books that are not on loan, and not on hold for another student, are listed.') }}</flux:text>
                </div>

                <flux:select wire:model.live="form.user_id" :label="__('Student')" required>
                    <flux:select.option value="">{{ __('Select a student') }}</flux:select.option>
                    @foreach ($this->students as $student)
                        <flux:select.option value="{{ $student->id }}" wire:key="student-option-{{ $student->id }}">{{ $student->name }} ({{ $student->email }})</flux:select.option>
                    @endforeach
                </flux:select>

                <flux:select wire:model="form.book_id" :label="__('Book')" required>
                    <flux:select.option value="">{{ __('Select an available book') }}</flux:select.option>
                    @foreach ($this->availableBooks as $availableBook)
                        <flux:select.option value="{{ $availableBook->id }}" wire:key="book-option-{{ $availableBook->id }}">{{ $availableBook->title }} — {{ $availableBook->author }}{{ $availableBook->activeHold ? ' ('.__('reserved for this student').')' : '' }}</flux:select.option>
                    @endforeach
                </flux:select>

                <div
                    x-data="{
                        get loanDays() {
                            const due = $wire.form.due_at;
                            if (! due) return null;
                            const today = new Date(); today.setHours(0, 0, 0, 0);
                            return Math.round((new Date(due + 'T00:00:00') - today) / 86400000);
                        }
                    }"
                >
                    <flux:input
                        wire:model.live="form.due_at"
                        type="date"
                        :label="__('Due date')"
                        :min="now()->addDay()->toDateString()"
                        :max="now()->addDays(App\Livewire\Forms\BorrowingForm::MAX_LOAN_DAYS)->toDateString()"
                        required
                    />
                    <flux:text class="mt-2" x-show="loanDays !== null && loanDays > 0" x-cloak>
                        <span x-text="`Loan period: ${loanDays} day${loanDays === 1 ? '' : 's'}`"></span>
                    </flux:text>
                </div>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="confirm-issue-button">{{ __('Issue Book') }}</flux:button>
                </div>
            </form>
        </flux:modal>

        {{-- Return confirmation modal --}}
        <flux:modal name="confirm-return" class="w-full md:w-[28rem]">
            @if ($loan = $this->returningBorrowing)
                <div class="space-y-5" wire:key="confirm-return-{{ $loan->id }}">
                    <div>
                        <flux:heading size="lg">{{ __('Record return?') }}</flux:heading>
                        <flux:text class="mt-2">
                            {{ __('":title" borrowed by :student will be marked as returned today.', ['title' => $loan->book->title, 'student' => $loan->user?->name ?? __('a deleted account')]) }}
                        </flux:text>
                        @if ($loan->isOverdue())
                            <flux:callout class="mt-3" variant="warning" icon="clock" :heading="__('This book is :days day(s) overdue.', ['days' => max(1, $loan->daysOverdue())])" />
                        @endif
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <flux:button variant="primary" wire:click="returnBook" data-test="confirm-return-button">{{ __('Confirm return') }}</flux:button>
                    </div>
                </div>
            @endif
        </flux:modal>
    @endcan
</div>
