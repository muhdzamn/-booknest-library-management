<div class="flex w-full flex-col gap-6">
    {{-- Header --}}
    <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
        <div>
            <flux:heading size="xl" level="1">{{ $this->canViewAll ? __('Reservations') : __('My Reservations') }}</flux:heading>
            <flux:subheading>
                @if ($this->canViewAll)
                    {{ __('Review reservation requests. Approved requests hold the book for :days days.', ['days' => App\Models\Reservation::HOLD_DAYS]) }}
                @else
                    {{ __('Reserve a book and collect it within :days days of approval.', ['days' => App\Models\Reservation::HOLD_DAYS]) }}
                @endif
            </flux:subheading>
        </div>

        @can('create', App\Models\Reservation::class)
            <flux:button variant="primary" icon="bookmark" wire:click="openRequestForm" data-test="new-reservation-button">
                {{ __('New Reservation') }}
            </flux:button>
        @endcan
    </div>

    <x-flash-alert :alert="$alert" />

    {{-- Statistics (click to filter) --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-4">
        @foreach ([
            'pending' => [__('Pending'), 'text-amber-600 dark:text-amber-400'],
            'approved' => [__('On hold'), 'text-blue-600 dark:text-blue-400'],
            'rejected' => [__('Rejected'), 'text-red-600 dark:text-red-400'],
            'fulfilled' => [__('Fulfilled'), 'text-emerald-600 dark:text-emerald-400'],
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
            @foreach (App\Enums\ReservationStatus::cases() as $statusOption)
                <flux:select.option value="{{ $statusOption->value }}" wire:key="status-{{ $statusOption->value }}">{{ $statusOption->label() }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    {{-- History table --}}
    <div class="overflow-x-auto rounded-xl border border-zinc-200 px-4 dark:border-zinc-700">
        <flux:table :paginate="$this->reservations">
            <flux:table.columns>
                <flux:table.column>{{ __('Book') }}</flux:table.column>
                @if ($this->canViewAll)
                    <flux:table.column>{{ __('Student') }}</flux:table.column>
                @endif
                <flux:table.column class="max-sm:hidden">{{ __('Reserved') }}</flux:table.column>
                <flux:table.column>{{ __('Status') }}</flux:table.column>
                <flux:table.column class="max-lg:hidden">{{ __('Reviewed by') }}</flux:table.column>
                <flux:table.column class="max-md:hidden">{{ __('Remarks') }}</flux:table.column>
                <flux:table.column align="end">{{ __('Actions') }}</flux:table.column>
            </flux:table.columns>

            <flux:table.rows>
                @forelse ($this->reservations as $reservation)
                    @php($effectiveStatus = $reservation->effectiveStatus())
                    <flux:table.row wire:key="reservation-{{ $reservation->id }}">
                        <flux:table.cell>
                            <div class="font-medium text-zinc-800 dark:text-white">{{ $reservation->book->title }}</div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $reservation->book->author }}</div>
                        </flux:table.cell>

                        @if ($this->canViewAll)
                            <flux:table.cell>
                                @if ($reservation->user)
                                    <div class="text-zinc-800 dark:text-white">{{ $reservation->user->name }}</div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $reservation->user->email }}</div>
                                @else
                                    <span class="text-zinc-500 italic">{{ __('Deleted account') }}</span>
                                @endif
                            </flux:table.cell>
                        @endif

                        <flux:table.cell class="max-sm:hidden">{{ $reservation->created_at?->format('j M Y') }}</flux:table.cell>

                        <flux:table.cell>
                            <flux:badge size="sm" :color="$effectiveStatus->color()" inset="top bottom">{{ $effectiveStatus->label() }}</flux:badge>
                            @if ($effectiveStatus === App\Enums\ReservationStatus::Approved)
                                <div class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('Collect by :date', ['date' => $reservation->expires_at?->format('j M Y')]) }}</div>
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="max-lg:hidden">
                            @if ($reservation->reviewed_at)
                                <div class="text-zinc-800 dark:text-white">{{ $reservation->reviewer->name ?? __('Deleted account') }}</div>
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">{{ $reservation->reviewed_at->format('j M Y') }}</div>
                            @else
                                —
                            @endif
                        </flux:table.cell>

                        <flux:table.cell class="max-md:hidden max-w-56 truncate" :title="$reservation->remarks">{{ $reservation->remarks ?? '—' }}</flux:table.cell>

                        <flux:table.cell align="end">
                            <div class="flex justify-end gap-1">
                                @can('review', $reservation)
                                    <flux:button size="sm" icon="check" wire:click="openReview({{ $reservation->id }}, 'approve')">{{ __('Approve') }}</flux:button>
                                    <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="openReview({{ $reservation->id }}, 'reject')">{{ __('Reject') }}</flux:button>
                                @endcan
                                @can('cancel', $reservation)
                                    <flux:button size="sm" variant="ghost" wire:click="confirmCancel({{ $reservation->id }})">{{ __('Cancel') }}</flux:button>
                                @endcan
                            </div>
                        </flux:table.cell>
                    </flux:table.row>
                @empty
                    <flux:table.row>
                        <flux:table.cell :colspan="$this->canViewAll ? 7 : 6" class="py-10 text-center">
                            <flux:text>{{ __('No reservations found.') }}</flux:text>
                            @if ($search !== '' || $status !== '')
                                <flux:button size="sm" variant="ghost" class="mt-2" wire:click="clearFilters">{{ __('Clear filters') }}</flux:button>
                            @endif
                        </flux:table.cell>
                    </flux:table.row>
                @endforelse
            </flux:table.rows>
        </flux:table>
    </div>

    {{-- New reservation (students) --}}
    @can('create', App\Models\Reservation::class)
        <flux:modal name="new-reservation" class="w-full md:w-[30rem]">
            <form wire:submit="submitRequest" class="space-y-5">
                <div>
                    <flux:heading size="lg">{{ __('New Reservation') }}</flux:heading>
                    <flux:text class="mt-1">{{ __('A librarian will review your request. Once approved, the book is held for you for :days days.', ['days' => App\Models\Reservation::HOLD_DAYS]) }}</flux:text>
                </div>

                <flux:select wire:model="form.book_id" :label="__('Book')" required>
                    <flux:select.option value="">{{ __('Select a book') }}</flux:select.option>
                    @foreach ($this->reservableBooks as $reservableBook)
                        <flux:select.option value="{{ $reservableBook->id }}" wire:key="reservable-{{ $reservableBook->id }}">
                            {{ $reservableBook->title }} — {{ $reservableBook->is_on_loan ? __('on loan') : ($reservableBook->is_on_hold ? __('on hold') : __('available')) }}
                        </flux:select.option>
                    @endforeach
                </flux:select>

                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                    </flux:modal.close>
                    <flux:button type="submit" variant="primary" data-test="submit-reservation-button">{{ __('Submit Request') }}</flux:button>
                </div>
            </form>
        </flux:modal>
    @endcan

    {{-- Review modal (staff). Alpine.js keeps the approve/reject choice in sync with the form. --}}
    @if ($this->canViewAll)
        <flux:modal name="review-reservation" class="w-full md:w-[32rem]">
            @if ($review = $this->reviewingReservation)
                <form
                    wire:submit="submitReview"
                    class="space-y-5"
                    wire:key="review-{{ $review->id }}"
                    x-data="{ decision: $wire.entangle('form.decision') }"
                >
                    <div>
                        <flux:heading size="lg">{{ __('Review Reservation') }}</flux:heading>
                        <flux:text class="mt-1">
                            {{ __(':student requested ":title" on :date.', [
                                'student' => $review->user->name ?? __('A deleted account'),
                                'title' => $review->book->title,
                                'date' => $review->created_at?->format('j M Y'),
                            ]) }}
                        </flux:text>
                    </div>

                    @if ($review->book->is_on_loan)
                        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('This book is currently on loan. Approval will be refused until it is returned.')" />
                    @elseif ($review->book->is_on_hold)
                        <flux:callout variant="warning" icon="exclamation-triangle" :heading="__('This book is already on hold for another student.')" />
                    @else
                        <flux:callout variant="success" icon="check-circle" :heading="__('This book is available. Approving will hold it until :date.', ['date' => now()->addDays(App\Models\Reservation::HOLD_DAYS)->format('j M Y')])" />
                    @endif

                    <div class="grid grid-cols-2 gap-2" role="radiogroup" aria-label="{{ __('Decision') }}">
                        <button type="button" role="radio" x-on:click="decision = 'approve'" x-bind:aria-checked="decision === 'approve'"
                            class="rounded-lg border px-3 py-2 text-sm font-medium transition"
                            x-bind:class="decision === 'approve' ? 'border-emerald-600 bg-emerald-50 text-emerald-700 dark:border-emerald-400 dark:bg-emerald-400/10 dark:text-emerald-300' : 'border-zinc-200 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300'">
                            {{ __('Approve') }}
                        </button>
                        <button type="button" role="radio" x-on:click="decision = 'reject'" x-bind:aria-checked="decision === 'reject'"
                            class="rounded-lg border px-3 py-2 text-sm font-medium transition"
                            x-bind:class="decision === 'reject' ? 'border-red-600 bg-red-50 text-red-700 dark:border-red-400 dark:bg-red-400/10 dark:text-red-300' : 'border-zinc-200 text-zinc-600 dark:border-zinc-700 dark:text-zinc-300'">
                            {{ __('Reject') }}
                        </button>
                    </div>
                    <flux:error name="form.decision" />

                    <div>
                        <flux:textarea wire:model="form.remarks" rows="3" :label="__('Remarks')" :placeholder="__('Optional note for the student...')" />
                        <flux:text class="mt-1 text-xs" x-show="decision === 'reject'" x-cloak>{{ __('A reason is required when rejecting.') }}</flux:text>
                    </div>

                    <div class="flex justify-end gap-2">
                        <flux:modal.close>
                            <flux:button variant="ghost">{{ __('Cancel') }}</flux:button>
                        </flux:modal.close>
                        <div x-show="decision === 'approve'">
                            <flux:button type="submit" variant="primary" data-test="confirm-approve-button">{{ __('Confirm approval') }}</flux:button>
                        </div>
                        <div x-show="decision === 'reject'" x-cloak>
                            <flux:button type="submit" variant="danger" data-test="confirm-reject-button">{{ __('Confirm rejection') }}</flux:button>
                        </div>
                    </div>
                </form>
            @endif
        </flux:modal>
    @endif

    {{-- Cancel confirmation --}}
    <flux:modal name="confirm-cancel" class="w-full md:w-[28rem]">
        @if ($cancelling = $this->cancellingReservation)
            <div class="space-y-5" wire:key="cancel-{{ $cancelling->id }}">
                <div>
                    <flux:heading size="lg">{{ __('Cancel reservation?') }}</flux:heading>
                    <flux:text class="mt-2">{{ __('The reservation for ":title" will be cancelled and the book released for others. The record is kept in the history.', ['title' => $cancelling->book->title]) }}</flux:text>
                </div>
                <div class="flex justify-end gap-2">
                    <flux:modal.close>
                        <flux:button variant="ghost">{{ __('Keep it') }}</flux:button>
                    </flux:modal.close>
                    <flux:button variant="danger" wire:click="cancelReservation" data-test="confirm-cancel-button">{{ __('Cancel reservation') }}</flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
