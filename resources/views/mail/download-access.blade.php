<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Your download access</title></head>
<body>
    <h1>Your download access</h1>
    <p>Use the link or code below to confirm download access for order {{ $order['number'] }}. Opening the link only shows the confirmation form — your downloads open only after you confirm there.</p>
    <p><a href="{{ $url }}">Confirm download access</a></p>
    <p>If you cannot open the link, copy this code into the confirmation form:</p>
    <p>{{ $token }}</p>
    <p>This code expires in {{ $expiry }} and works once. Requesting another code replaces it.</p>
    <p>If you did not request this, you can ignore this email. Nothing about your order changes.</p>
</body>
</html>
