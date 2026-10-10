<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <link rel="icon" href="/favicon.svg" type="image/svg+xml">
        <link rel="alternate icon" href="/favicon.ico">
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="theme-color" content="#12232E">
        <meta name="csrf-token" content="{{ csrf_token() }}">
        <title inertia>{{ config('app.name', 'Dr Business Flow') }}</title>
        <script nonce="{{ $cspNonce ?? '' }}">try{var t=localStorage.getItem('cf-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
        @vite('resources/js/app.tsx')
        @inertiaHead
    </head>
    <body class="font-sans antialiased bg-paper text-ink">
        <div id="boot" class="boot" role="status" aria-label="Loading">
            <svg class="boot-mark" viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="8" fill="#0F7C74"/><polyline points="27 16 22 16 19 25 13 7 10 16 5 16" fill="none" stroke="#fff" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
            <span class="boot-name">{{ config('app.name') }}</span>
            <span class="boot-bar"><span></span></span>
        </div>
        @inertia
    </body>
</html>
