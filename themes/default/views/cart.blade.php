<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Cart · MRKTFlow</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/cart.ts', 'themes/default/src/cartIndicator.ts'])
</head>
<body>
    <a class="skip-link" href="#cart-main">Skip to cart</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><span id="cart-indicator" data-cart="{{ json_encode(['itemCount' => $cartItemCount, 'revision' => $cart['revision']], JSON_THROW_ON_ERROR) }}"><a class="text-link" href="/cart" aria-current="page">@if($cartItemCount > 0)Cart ({{ $cartItemCount }})@else Cart @endif</a></span></header>
        <main id="cart-main" class="theme-cart">
            <h1>Cart<span class="accent">.</span></h1>
            <div id="cart-lines" data-cart="{{ json_encode($payload, JSON_THROW_ON_ERROR) }}">
                @if($cart['notices'] !== [])
                    <div class="theme-cart-notices" role="status">@foreach($cart['notices'] as $notice)<p>{{ $notice }}</p>@endforeach</div>
                @endif
                @if($cart['lines'] === [])
                    <p class="theme-empty">Your cart is empty.</p>
                @else
                    <ul class="theme-cart-lines">
                        @foreach($cart['lines'] as $line)
                            <li>
                                <div>
                                    <strong>{{ $line['title'] }}</strong>
                                    <span>{{ $line['sku'] }}</span>
                                    <span>{{ $line['line_total']['formatted'] }}</span>
                                    @if(! $line['available'])
                                        <p class="theme-variant-prompt" role="status">An item is no longer available.</p>
                                    @endif
                                </div>
                                <form method="post" action="/cart/lines/{{ $line['id'] }}" class="theme-cart-form">
                                    @csrf
                                    <input type="hidden" name="expected_revision" value="{{ $cart['revision'] }}">
                                    <label for="quantity-{{ $line['id'] }}">Quantity</label>
                                    <input id="quantity-{{ $line['id'] }}" name="quantity" type="number" min="0" max="99" value="{{ $line['quantity'] }}" required>
                                    <button type="submit" class="primary-button">Update</button>
                                </form>
                                <form method="post" action="/cart/lines/{{ $line['id'] }}/delete">
                                    @csrf
                                    <input type="hidden" name="expected_revision" value="{{ $cart['revision'] }}">
                                    <button type="submit" class="text-link">Remove</button>
                                </form>
                            </li>
                        @endforeach
                    </ul>
                    <p class="theme-price"><span>Merchandise</span><strong>{{ $cart['subtotal']['formatted'] }}</strong></p>
                    <p><a class="primary-button" href="/checkout">Checkout</a></p>
                @endif
                <p class="theme-purchase-note">Posted prices are ignored. Totals are calculated at checkout.</p>
            </div>
        </main>
        <footer class="footer"><span>Cart</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
