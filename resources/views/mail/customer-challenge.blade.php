<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $title }}</title></head>
<body>
    <h1>{{ $title }}</h1>
    <p>{{ $instruction }}</p>
    <p><a href="{{ $url }}">{{ $title }}</a></p>
    <p>If you cannot open the link, copy this code into the confirmation form:</p>
    <p>{{ $token }}</p>
    <p>This code expires in {{ $expiry }} and works once. Requesting another code replaces it.</p>
    <p>If you did not request this, you can ignore this email. Your account will not change.</p>
</body>
</html>
