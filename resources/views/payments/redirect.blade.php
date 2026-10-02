<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Secure payment</title>
    <style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;color:#12232E}button{background:#0F7C74;color:#fff;border:0;border-radius:10px;padding:12px 20px;font-size:16px}</style>
</head>
<body>
    <form id="pay" method="post" action="{{ $action }}">
        @foreach ($fields as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <p>Taking you to the secure payment page…</p>
        <noscript><button type="submit">Continue to payment</button></noscript>
    </form>
    <script>document.getElementById('pay').submit();</script>
</body>
</html>
