<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Order · MRKTFlow</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css'])
</head>
<body>
    <a class="skip-link" href="#order-main">Skip to order status</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a></header>
        <main id="order-main" class="theme-checkout">
            <h1>Checkout<span class="accent">.</span></h1>
            @if($pending)
                <p class="theme-purchase-note" role="status">Payment is still processing. This page cannot complete the order.</p>
                <p class="theme-purchase-note">Current attempt: waiting for the processor. Order: pending.</p>
                <p><a class="text-link" href="{{ $refreshUrl }}">Check status again</a></p>
            @else
                <p class="theme-purchase-note" role="status">Order confirmed.@if($zeroTotal) No charge was taken for a zero total.@endif</p>
                <p class="theme-purchase-note">Order {{ $orderNumber }}. Total {{ $total }}. Status {{ $orderStatus }}.</p>
                <p><a class="text-link" href="/cart">Continue shopping</a></p>
            @endif
        </main>
        <footer class="footer"><span>Checkout</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
