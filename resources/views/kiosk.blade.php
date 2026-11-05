<!DOCTYPE html>
<html lang="en" class="kiosk">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#2A338F">
    <meta name="robots" content="noindex, nofollow">

    <title inertia>CSC Regional Office VIII Kiosk</title>

    <link rel="icon" href="/favicon.ico" sizes="any">
    <link rel="preconnect" href="https://fonts.bunny.net" crossorigin>

    {{-- No inline script here: the kiosk's content policy allows scripts only
         from this site. The fonts and the app come from Vite. --}}
    {{ Illuminate\Support\Facades\Vite::fonts() }}
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @inertiaHead
</head>
<body class="bg-surface-muted font-sans text-text antialiased">
    @inertia
</body>
</html>
