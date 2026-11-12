<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $heading }}</title></head>
<body>
    <h1>{{ $heading }}</h1>
    @if($test)
        <p>This is a staff test message. It is not a customer send.</p>
    @endif
    <p>{{ $body }}</p>
    @if($unsubscribeUrl)
        <p><a href="{{ $unsubscribeUrl }}">Unsubscribe</a></p>
    @endif
    <p>This is {{ $policyClass }} email. Order receipts, shipping notices and download access are not marketing.</p>
</body>
</html>
