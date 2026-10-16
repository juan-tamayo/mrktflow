<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Order {{ $order['number'] }} · MRKTFlow</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css'])
</head>
<body>
    <a class="skip-link" href="#order-main">Skip to order</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><a class="text-link" href="/account/orders">Your orders</a></header>
        <main id="order-main" class="theme-checkout">
            <h1>Order {{ $order['number'] }}<span class="accent">.</span></h1>
            <p class="theme-purchase-note">{{ $order['email'] }} · status {{ $order['status'] }} · financial {{ $order['financial_status'] }}</p>
            <h2>Receipt</h2>
            <p class="theme-purchase-note">Frozen totals from checkout. Grand {{ $order['receipt']['grand_total']['formatted'] }}.</p>
            <ul class="product-list">
                @foreach($order['lines'] as $line)
                    <li>
                        <p>{{ $line['title'] }} · {{ $line['sku'] }} · qty {{ $line['quantity'] }} · {{ $line['total']['formatted'] }}</p>
                    </li>
                @endforeach
            </ul>
            <p>Merchandise {{ $order['totals']['merchandise']['formatted'] }} · discount {{ $order['totals']['discount']['formatted'] }} · shipping {{ $order['totals']['shipping']['formatted'] }} · tax {{ $order['totals']['tax']['formatted'] }} · {{ $order['totals']['grand_total']['formatted'] }}</p>
            <h2>History</h2>
            <ul class="product-list">
                @foreach($order['timeline'] as $entry)
                    <li><p>{{ $entry['type'] }} · {{ $entry['created_at'] }} · {{ $entry['amount']['formatted'] }}</p></li>
                @endforeach
            </ul>
            @include('mail.order-receipt', ['order' => $order])
        </main>
        <footer class="footer"><span>Account</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
