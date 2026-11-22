@props(['alert' => null])

{{-- Alpine.js dismissible alert: auto-hides after 6 seconds. Pair with App\Concerns\InteractsWithAlerts. --}}
@if ($alert)
    <div
        wire:key="alert-{{ $alert['id'] }}"
        x-data="{ open: true }"
        x-init="setTimeout(() => { open = false; $wire.dismissAlert() }, 6000)"
        x-show="open"
        x-transition.opacity.duration.300ms
        role="status"
    >
        <flux:callout
            :variant="$alert['variant']"
            :icon="$alert['variant'] === 'success' ? 'check-circle' : 'exclamation-triangle'"
            :heading="$alert['message']"
        >
            <x-slot name="controls">
                <flux:button size="sm" variant="ghost" icon="x-mark" x-on:click="open = false; $wire.dismissAlert()" :aria-label="__('Dismiss')" />
            </x-slot>
        </flux:callout>
    </div>
@endif
