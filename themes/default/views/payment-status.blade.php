<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Payment · MRKTFlow</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css'])
</head>
<body>
    <a class="skip-link" href="#payment-main">Skip to payment status</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a></header>
        <main id="payment-main" class="theme-checkout">
            <h1>Checkout<span class="accent">.</span></h1>
            <p class="theme-purchase-note" role="status">{{ $message }}</p>
            <p class="theme-purchase-note">Current attempt: {{ $attemptStatus }}. Order: {{ $orderStatus }}.</p>
        </main>
        <footer class="footer"><span>Checkout</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
