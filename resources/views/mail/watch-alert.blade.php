<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $heading }}: {{ $title }}</title></head>
<body>
    <h1>{{ $heading }}</h1>
    <p>{{ $bodyText }}</p>
    <p><a href="{{ $productUrl }}">View {{ $title }}</a></p>
    <p>Current price: {{ $money['formatted'] }}</p>
    <p>You receive this alert because you asked to be notified about this item. <a href="{{ $unsubscribeUrl }}">Unsubscribe from these alerts</a></p>
</body>
</html>
