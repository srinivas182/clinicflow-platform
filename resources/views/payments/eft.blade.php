<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex"><title>{{ $title }}</title>
<style>body{font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;color:#12232E;padding:24px}main{max-width:30rem}dl{display:grid;grid-template-columns:auto 1fr;gap:6px 16px;margin:20px 0}dt{color:#5E6E76}dd{margin:0;font-weight:600;overflow-wrap:anywhere}</style></head>
<body><main>
<h1>{{ $title }}</h1>
<p>{{ $intro }}</p>
<dl>
    <dt>Amount</dt><dd>{{ $amount }}</dd>
    <dt>Reference</dt><dd>{{ $reference }}</dd>
    @if (($eft['bank'] ?? '') !== '' && ($eft['account_number'] ?? '') !== '')
        <dt>Bank</dt><dd>{{ $eft['bank'] }}</dd>
        <dt>Account name</dt><dd>{{ $eft['account_name'] }}</dd>
        <dt>Account number</dt><dd>{{ $eft['account_number'] }}</dd>
        <dt>Branch code</dt><dd>{{ $eft['branch_code'] }}</dd>
    @endif
</dl>
<p>Use the reference exactly as shown so we can match your payment.
@if (($eft['bank'] ?? '') === '' || ($eft['account_number'] ?? '') === '')
    For our bank details, email <a href="mailto:{{ $eft['email'] }}">{{ $eft['email'] }}</a>.
@else
    Questions? Email <a href="mailto:{{ $eft['email'] }}">{{ $eft['email'] }}</a>.
@endif
</p>
</main></body>
</html>
