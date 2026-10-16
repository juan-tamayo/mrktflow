<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Orders · MRKTFlow</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css'])
</head>
<body>
    <a class="skip-link" href="#orders-main">Skip to orders</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><a class="text-link" href="/account">Account</a></header>
        <main id="orders-main" class="theme-checkout">
            <h1>Your orders<span class="accent">.</span></h1>
            @if(count($orders) === 0)
                <p class="theme-purchase-note">You have no orders yet.</p>
            @else
                <ul class="product-list">
                    @foreach($orders as $order)
                        <li>
                            <p><a class="text-link" href="/account/orders/{{ $order['id'] }}">{{ $order['number'] }}</a> · {{ $order['email'] }} · {{ $order['status'] }} · {{ $order['grand_total']['formatted'] }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
        </main>
        <footer class="footer"><span>Account</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
