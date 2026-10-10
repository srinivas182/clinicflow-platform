<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>@yield('title') · {{ config('app.name', 'Dr Business Flow') }}</title>
    {{-- Apply a saved Light/Dark choice before drawing (same key as the in-app switch). --}}
    <script nonce="{{ $cspNonce ?? '' }}">try{var t=localStorage.getItem('cf-theme');if(t==='light'||t==='dark')document.documentElement.setAttribute('data-theme',t)}catch(e){}</script>
    <style>
        :root { --bg:#f5f7f6; --card:#ffffff; --ink:#12232e; --muted:#5e6e76; --line:#dce3e1; --teal:#0f7c74; --chip:#e5f3f1; --chip-ink:#0b5c57; color-scheme: light; }
        :root[data-theme="dark"] { --bg:#0e1a20; --card:#15242c; --ink:#e6eef0; --muted:#9aadb4; --line:#2a3c45; --chip:#12302d; --chip-ink:#5fd3c6; color-scheme: dark; }
        @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --bg:#0e1a20; --card:#15242c; --ink:#e6eef0; --muted:#9aadb4; --line:#2a3c45; --chip:#12302d; --chip-ink:#5fd3c6; color-scheme: dark; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; padding:24px; background:var(--bg); color:var(--ink);
               font: 16px/1.5 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        main { width:100%; max-width:480px; background:var(--card); border:1px solid var(--line); border-radius:16px; padding:32px; text-align:center; }
        .brand { display:inline-flex; align-items:center; gap:8px; font-weight:600; color:var(--chip-ink); margin-bottom:20px; }
        .dot { width:12px; height:12px; border-radius:50%; background:var(--teal); }
        .code { display:inline-block; font-size:13px; font-weight:600; letter-spacing:.06em; color:var(--chip-ink); background:var(--chip); border-radius:999px; padding:4px 12px; }
        h1 { font-size:24px; margin:16px 0 8px; }
        p { color:var(--muted); margin:0 0 24px; }
        .actions { display:flex; gap:12px; justify-content:center; flex-wrap:wrap; }
        a.btn { display:inline-block; padding:10px 18px; border-radius:8px; text-decoration:none; font-weight:600; }
        a.primary { background:var(--teal); color:#fff; }
        a.secondary { border:1px solid var(--line); color:var(--ink); }
        a.btn:focus-visible { outline:2px solid var(--teal); outline-offset:2px; }
    </style>
</head>
<body>
    <main role="main">
        <div class="brand"><span class="dot" aria-hidden="true"></span>{{ config('app.name', 'Dr Business Flow') }}</div>
        <div><span class="code">Error @yield('code')</span></div>
        <h1>@yield('title')</h1>
        <p>@yield('message')</p>
        <div class="actions">
            @hasSection('primary')@yield('primary')@else<a class="btn primary" href="/">Go to the home page</a>@endif
            <a class="btn secondary" href="/" data-back>Go back</a>
        </div>
    </main>
    {{-- "Go back" uses the browser history when available (allowed by the content-security policy via the nonce). --}}
    <script nonce="{{ $cspNonce ?? '' }}">document.querySelectorAll('[data-back]').forEach(function(a){a.addEventListener('click',function(e){if(history.length>1){e.preventDefault();history.back();}});});</script>
</body>
</html>
