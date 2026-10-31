@extends('default-theme::layout', [
    'pageKey' => 'cart',
    'pageTitle' => 'Cart · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/cart.ts', 'themes/default/src/cartIndicator.ts'],
    'mainId' => 'cart-main',
    'mainClass' => 'theme-cart',
    'skipLabel' => 'Skip to cart',
    'footerLabel' => 'Cart',
    'masthead' => ['cartIndicator' => ['itemCount' => $cartItemCount, 'revision' => $cart['revision']], 'cartCurrent' => true],
])
@section('content')
            <h1>Cart<span class="accent">.</span></h1>
            <div id="cart-lines" data-cart="{{ json_encode($payload, JSON_THROW_ON_ERROR) }}">
                @if($cart['notices'] !== [])
                    <div class="theme-cart-notices" role="status">@foreach($cart['notices'] as $notice)<p>{{ $notice }}</p>@endforeach</div>
                @endif
                @php
                    $canCheckout = $cart['lines'] !== [];
                    $hasUnavailable = false;
                    foreach ($cart['lines'] as $line) {
                        if (! ($line['available'] ?? false)) {
                            $canCheckout = false;
                            $hasUnavailable = true;
                        }
                    }
                @endphp
                @if($cart['lines'] === [])
                    <p class="theme-empty">Your cart is empty.</p>
                    <p><a class="primary-button" href="/search">Continue shopping</a></p>
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
                                @include('storefront.slots.cart-line-actions', ['line' => $line, 'revision' => $cart['revision']])
                            </li>
                        @endforeach
                    </ul>
                    <p class="theme-price"><span>Merchandise</span><strong>{{ $cart['subtotal']['formatted'] }}</strong></p>
                    @if($hasUnavailable)
                        <p class="theme-variant-prompt" role="status">Remove unavailable items to continue.</p>
                    @endif
                    @if($canCheckout)
                        <p><a class="primary-button" href="/checkout">Checkout</a></p>
                    @endif
                    <p><a class="text-link" href="/search">Continue shopping</a></p>
                @endif
                <p class="theme-purchase-note">Posted prices are ignored. Totals are calculated at checkout.</p>
            </div>
@endsection
