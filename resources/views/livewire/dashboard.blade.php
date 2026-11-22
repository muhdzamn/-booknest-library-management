@php
    $user = auth()->user();
    $catalogue = $this->catalogueStats;
    $circulation = $this->circulationStats;
    $roleColours = ['admin' => 'purple', 'librarian' => 'blue', 'student' => 'green'];
@endphp

<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <div class="flex items-center gap-2">
                <flux:heading size="xl" level="1">{{ __('Welcome back, :name', ['name' => $user->name]) }}</flux:heading>
                <flux:badge size="sm" :color="$roleColours[$user->role->value]">{{ ucfirst($user->role->value) }}</flux:badge>
            </div>
            <flux:subheading>
                @if ($this->isStaff)
                    {{ __(':brand library overview for :date.', ['brand' => config('booknest.name'), 'date' => now()->format('l, j F Y')]) }}
                @else
                    {{ __('Your books, loans and reservations at :brand.', ['brand' => config('booknest.name')]) }}
                @endif
            </flux:subheading>
        </div>

        <div class="flex flex-wrap gap-2">
            @can('create', App\Models\Borrowing::class)
                <flux:button variant="primary" icon="arrows-right-left" :href="route('borrowings.index')" wire:navigate>{{ __('Issue / Return') }}</flux:button>
            @endcan
            @can('viewAny', App\Models\Reservation::class)
                <flux:button icon="bookmark" :href="route('reservations.index', ['status' => 'pending'])" wire:navigate>{{ __('Review Reservations') }}</flux:button>
            @endcan
            @can('create', App\Models\Reservation::class)
                <flux:button variant="primary" icon="bookmark" :href="route('reservations.index')" wire:navigate>{{ __('Reserve a Book') }}</flux:button>
                <flux:button icon="book-open" :href="route('books.manage')" wire:navigate>{{ __('Browse Catalogue') }}</flux:button>
            @endcan
        </div>
    </div>

    {{-- Statistics --}}
    @php
        $cards = [
            ['key' => 'books', 'label' => __('Total Books'), 'value' => $catalogue['books'], 'icon' => 'book-open', 'colour' => 'text-zinc-800 dark:text-white', 'href' => route('books.manage')],
            ['key' => 'categories', 'label' => __('Categories'), 'value' => $catalogue['categories'], 'icon' => 'tag', 'colour' => 'text-zinc-800 dark:text-white', 'href' => route('books.manage')],
            ['key' => 'available', 'label' => __('Available Books'), 'value' => $catalogue['available'], 'icon' => 'check-circle', 'colour' => 'text-emerald-600 dark:text-emerald-400', 'href' => route('books.manage')],
            ['key' => 'active', 'label' => $this->isStaff ? __('Active Borrowings') : __('My Active Loans'), 'value' => $circulation['active'], 'icon' => 'arrows-right-left', 'colour' => 'text-blue-600 dark:text-blue-400', 'href' => route('borrowings.index', ['status' => 'active'])],
            ['key' => 'overdue', 'label' => $this->isStaff ? __('Overdue Borrowings') : __('My Overdue Loans'), 'value' => $circulation['overdue'], 'icon' => 'exclamation-triangle', 'colour' => 'text-red-600 dark:text-red-400', 'href' => route('borrowings.index', ['status' => 'overdue'])],
            ['key' => 'pending', 'label' => $this->isStaff ? __('Pending Reservations') : __('My Pending Reservations'), 'value' => $circulation['pending'], 'icon' => 'clock', 'colour' => 'text-amber-600 dark:text-amber-400', 'href' => route('reservations.index', ['status' => 'pending'])],
            ['key' => 'onHold', 'label' => $this->isStaff ? __('Approved On Hold') : __('My Books On Hold'), 'value' => $circulation['onHold'], 'icon' => 'bookmark', 'colour' => 'text-indigo-600 dark:text-indigo-400', 'href' => route('reservations.index', ['status' => 'approved'])],
            ['key' => 'returned', 'label' => $this->isStaff ? __('Returned Books') : __('My Returned Books'), 'value' => $circulation['returned'], 'icon' => 'check-circle', 'colour' => 'text-zinc-800 dark:text-white', 'href' => route('borrowings.index', ['status' => 'returned'])],
        ];
    @endphp

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        @foreach ($cards as $card)
            <a
                href="{{ $card['href'] }}"
                wire:navigate
                wire:key="stat-{{ $card['key'] }}"
                data-stat="{{ $card['key'] }}"
                class="group rounded-xl border border-zinc-200 p-4 transition hover:bg-zinc-50 dark:border-zinc-700 dark:hover:bg-white/5"
            >
                <div class="flex items-center justify-between">
                    <flux:text class="text-sm">{{ $card['label'] }}</flux:text>
                    <flux:icon :name="$card['icon']" variant="mini" class="text-zinc-400 group-hover:text-zinc-600 dark:group-hover:text-zinc-200" />
                </div>
                <div class="mt-2 text-3xl font-semibold tabular-nums {{ $card['colour'] }}">{{ $card['value'] }}</div>
            </a>
        @endforeach
    </div>

    {{-- Admin: accounts --}}
    @if ($this->accountStats !== [])
        <div class="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-zinc-200 px-4 py-3 dark:border-zinc-700">
            <div class="flex items-center gap-2">
                <flux:icon.user-group variant="mini" class="text-zinc-400" />
                <flux:heading size="sm">{{ __('Accounts') }}</flux:heading>
            </div>
            @foreach ($this->accountStats as $role => $total)
                <div class="flex items-center gap-2" wire:key="accounts-{{ $role }}" data-accounts="{{ $role }}">
                    <flux:badge size="sm" :color="$roleColours[$role]">{{ ucfirst($role) }}s</flux:badge>
                    <span class="font-semibold tabular-nums text-zinc-800 dark:text-white">{{ $total }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Needs attention --}}
    @if ($this->isStaff && $this->overdueBorrowings->isNotEmpty())
        <flux:callout variant="danger" icon="exclamation-triangle" :heading="__('Overdue loans need follow-up')">
            <flux:callout.text>
                <ul class="mt-1 space-y-1">
                    @foreach ($this->overdueBorrowings as $loan)
                        <li wire:key="overdue-{{ $loan->id }}">
                            <span class="font-medium">{{ $loan->book->title }}</span>
                            — {{ $loan->user->name ?? __('Deleted account') }},
                            {{ trans_choice(':count day overdue|:count days overdue', max(1, $loan->daysOverdue()), ['count' => max(1, $loan->daysOverdue())]) }}
                        </li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    @if (! $this->isStaff && $this->myHolds->isNotEmpty())
        <flux:callout variant="success" icon="bookmark" :heading="__('Ready for you to collect')">
            <flux:callout.text>
                <ul class="mt-1 space-y-1">
                    @foreach ($this->myHolds as $hold)
                        <li wire:key="hold-{{ $hold->id }}">
                            <span class="font-medium">{{ $hold->book->title }}</span>
                            — {{ __('collect by :date', ['date' => $hold->expires_at?->format('j M Y')]) }}
                        </li>
                    @endforeach
                </ul>
            </flux:callout.text>
        </flux:callout>
    @endif

    {{-- Recent activity --}}
    <div class="grid gap-6 lg:grid-cols-2">
        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-section="recent-borrowings">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ $this->isStaff ? __('Recent Borrowing Activity') : __('My Recent Loans') }}</flux:heading>
                <flux:link :href="route('borrowings.index')" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
            </div>

            @forelse ($this->recentBorrowings as $loan)
                <div class="flex items-start justify-between gap-3 border-t border-zinc-100 py-3 first-of-type:border-t-0 dark:border-zinc-700/60" wire:key="recent-loan-{{ $loan->id }}">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-zinc-800 dark:text-white">{{ $loan->book->title }}</div>
                        <flux:text class="text-xs">
                            @if ($this->isStaff)
                                {{ $loan->user->name ?? __('Deleted account') }} ·
                            @endif
                            @if ($loan->isReturned())
                                {{ __('Returned :date', ['date' => $loan->returned_at->format('j M Y')]) }}
                            @else
                                {{ __('Borrowed :borrowed · due :due', ['borrowed' => $loan->borrowed_at->format('j M'), 'due' => $loan->due_at->format('j M Y')]) }}
                            @endif
                        </flux:text>
                    </div>
                    @if ($loan->isReturned())
                        <flux:badge size="sm" color="green">{{ __('Returned') }}</flux:badge>
                    @elseif ($loan->isOverdue())
                        <flux:badge size="sm" color="red">{{ __('Overdue') }}</flux:badge>
                    @else
                        <flux:badge size="sm" color="blue">{{ __('On loan') }}</flux:badge>
                    @endif
                </div>
            @empty
                <flux:text class="py-6 text-center">{{ __('No borrowing activity yet.') }}</flux:text>
            @endforelse
        </section>

        <section class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-700" data-section="recent-reservations">
            <div class="mb-3 flex items-center justify-between">
                <flux:heading size="lg">{{ $this->isStaff ? __('Recent Reservation Activity') : __('My Recent Reservations') }}</flux:heading>
                <flux:link :href="route('reservations.index')" wire:navigate class="text-sm">{{ __('View all') }}</flux:link>
            </div>

            @forelse ($this->recentReservations as $reservation)
                @php($effectiveStatus = $reservation->effectiveStatus())
                <div class="flex items-start justify-between gap-3 border-t border-zinc-100 py-3 first-of-type:border-t-0 dark:border-zinc-700/60" wire:key="recent-reservation-{{ $reservation->id }}">
                    <div class="min-w-0">
                        <div class="truncate font-medium text-zinc-800 dark:text-white">{{ $reservation->book->title }}</div>
                        <flux:text class="text-xs">
                            @if ($this->isStaff)
                                {{ $reservation->user->name ?? __('Deleted account') }} ·
                            @endif
                            {{ __('Requested :date', ['date' => $reservation->created_at?->format('j M Y')]) }}
                            @if ($effectiveStatus === App\Enums\ReservationStatus::Approved)
                                · {{ __('hold until :date', ['date' => $reservation->expires_at?->format('j M Y')]) }}
                            @endif
                        </flux:text>
                    </div>
                    <flux:badge size="sm" :color="$effectiveStatus->color()">{{ $effectiveStatus->label() }}</flux:badge>
                </div>
            @empty
                <flux:text class="py-6 text-center">{{ __('No reservation activity yet.') }}</flux:text>
            @endforelse
        </section>
    </div>
</div>
