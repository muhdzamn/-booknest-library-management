<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' · '.config('booknest.name') : config('booknest.name').' · '.config('booknest.tagline') }}
</title>

{{-- BookNest book icon. The starter kit's favicon.ico / apple-touch-icon.png (Laravel logo) are no longer linked. --}}
<link rel="icon" href="/favicon.svg" type="image/svg+xml">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
