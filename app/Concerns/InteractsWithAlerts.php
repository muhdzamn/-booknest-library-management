<?php

namespace App\Concerns;

use Illuminate\Support\Str;

/**
 * A dismissible inline alert for Livewire pages, rendered by the
 * <x-flash-alert> Blade component (which auto-hides it with Alpine.js).
 */
trait InteractsWithAlerts
{
    /**
     * @var array{id: string, variant: 'success'|'danger', message: string}|null
     */
    public ?array $alert = null;

    public function dismissAlert(): void
    {
        $this->alert = null;
    }

    /**
     * @param  'success'|'danger'  $variant
     */
    protected function flash(string $variant, string $message): void
    {
        $this->alert = ['id' => (string) Str::ulid(), 'variant' => $variant, 'message' => $message];
    }
}
