<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    <meta name="robots" content="noindex, nofollow">
    <meta property="csp-nonce" nonce="{{ Vite::cspNonce() }}">
    <title>Store administration · MRKTFlow</title>
    @vite(['resources/css/app.css', 'resources/js/app.ts'])
    @inertiaHead
</head>
<body>
    @inertia
    <noscript><p>Store administration needs JavaScript. <a href="/">Return to storefront</a>.</p></noscript>
</body>
</html>
