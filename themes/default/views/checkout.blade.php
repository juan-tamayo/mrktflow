<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Checkout · MRKTFlow</title>
    <link rel="icon" href="/brand/favicon.svg" type="image/svg+xml">
    @vite(['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/checkout.ts', 'themes/default/src/cartIndicator.ts'])
</head>
<body>
    @php
        $needsShipping = false;
        foreach ($cart['lines'] as $line) {
            if (($line['type'] ?? '') === 'physical') {
                $needsShipping = true;
            }
        }
        $billing = $quote['billing'] ?? [];
        $shipping = $quote['shipping_address'] ?? [];
        $emailValue = $quote['email'] ?? $email;
    @endphp
    <a class="skip-link" href="#checkout-main">Skip to checkout</a>
    <div class="page-shell theme-shell">
        <header class="masthead"><a class="wordmark" href="/" aria-label="MRKTFlow"><img src="/brand/mark.svg" width="36" height="36" alt=""><span>mrktflow</span></a><span id="cart-indicator" data-cart="{{ json_encode(['itemCount' => $cartItemCount, 'revision' => $cart['revision']], JSON_THROW_ON_ERROR) }}"><a class="text-link" href="/cart">@if($cartItemCount > 0)Cart ({{ $cartItemCount }})@else Cart @endif</a></span></header>
        <main id="checkout-main" class="theme-checkout">
            <h1>Checkout<span class="accent">.</span></h1>
            @if($errors->any())
                <div class="theme-cart-notices" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            @if($quote)
                <div id="checkout-quote" data-quote="{{ json_encode($payload, JSON_THROW_ON_ERROR) }}">
                    <div id="checkout-summary">
                    <h2>Itemized total</h2>
                    <ul class="theme-cart-lines">
                        @foreach($quote['lines'] as $line)
                            <li>
                                <div>
                                    <strong>{{ $line['title'] }}</strong>
                                    <span>{{ $line['sku'] }}</span>
                                    <span>{{ $line['total']['formatted'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                    <p class="theme-price"><span>Merchandise</span><strong>{{ $quote['merchandise']['formatted'] }}</strong></p>
                    <p class="theme-price"><span>Discount</span><strong>{{ $quote['discount']['formatted'] }}</strong></p>
                    <p class="theme-price"><span>Shipping</span><strong>{{ $quote['shipping']['formatted'] }}</strong></p>
                    <p class="theme-price"><span>Tax</span><strong>{{ $quote['tax']['formatted'] }}</strong></p>
                    <p class="theme-price"><span>Total</span><strong>{{ $quote['grand_total']['formatted'] }}</strong></p>
                    </div>
                    <form method="post" action="/checkout/{{ $quote['id'] }}/accept" class="theme-checkout-form">
                        @csrf
                        <input type="hidden" name="fingerprint" value="{{ $quote['fingerprint'] }}">
                        <label><input type="checkbox" name="policy_acknowledged" value="1" required> I acknowledge the store policies</label>
                        <button type="submit" class="primary-button">Confirm these totals</button>
                    </form>
                    @if($quote['accepted_at'])
                        <p class="theme-purchase-note" role="status">Totals confirmed. Payment is not available yet.</p>
                    @else
                        <p class="theme-purchase-note">Payment is not available yet. Confirming stores this quote until it expires or the cart changes.</p>
                    @endif
                </div>
            @endif
            <h2>Contact</h2>
            <form method="post" action="/checkout" class="theme-checkout-form">
                @csrf
                <input type="hidden" name="expected_revision" value="{{ $cart['revision'] }}">
                <div class="setup-field">
                    <label for="checkout-email">Email</label>
                    <input id="checkout-email" name="email" type="email" value="{{ $emailValue }}" maxlength="254" autocomplete="email" required>
                </div>
                <div class="setup-field">
                    <label for="checkout-coupon">Coupon (optional)</label>
                    <input id="checkout-coupon" name="coupon" value="{{ $quote['coupon'] ?? '' }}" maxlength="32" autocomplete="off">
                </div>
                <h2>Billing</h2>
                @foreach(['recipient' => ['Recipient', 120], 'line1' => ['Street address', 200], 'line2' => ['Apartment or suite (optional)', 200], 'city' => ['City', 120], 'region' => ['Region or state (optional)', 120], 'postal_code' => ['Postal code (optional)', 32]] as $field => $options)
                    <div class="setup-field">
                        <label for="billing-{{ $field }}">{{ $options[0] }}</label>
                        <input id="billing-{{ $field }}" name="billing[{{ $field }}]" value="{{ $billing[$field] ?? '' }}" maxlength="{{ $options[1] }}" @required(in_array($field, ['recipient','line1','city'], true))>
                    </div>
                @endforeach
                <div class="setup-field">
                    <label for="billing-country">Country</label>
                    <select id="billing-country" name="billing[country_code]" required>
                        <option value="">Choose a country</option>
                        @foreach($countries as $country)
                            <option value="{{ $country['code'] }}" @selected(($billing['country_code'] ?? '') === $country['code'])>{{ $country['label'] }}</option>
                        @endforeach
                    </select>
                </div>
                @if($needsShipping)
                    <h2>Delivery</h2>
                    @foreach(['recipient' => ['Recipient', 120], 'line1' => ['Street address', 200], 'line2' => ['Apartment or suite (optional)', 200], 'city' => ['City', 120], 'region' => ['Region or state (optional)', 120], 'postal_code' => ['Postal code (optional)', 32]] as $field => $options)
                        <div class="setup-field">
                            <label for="shipping-{{ $field }}">{{ $options[0] }}</label>
                            <input id="shipping-{{ $field }}" name="shipping[{{ $field }}]" value="{{ $shipping[$field] ?? '' }}" maxlength="{{ $options[1] }}" @required(in_array($field, ['recipient','line1','city'], true))>
                        </div>
                    @endforeach
                    <div class="setup-field">
                        <label for="shipping-country">Country</label>
                        <select id="shipping-country" name="shipping[country_code]" required>
                            <option value="">Choose a country</option>
                            @foreach($countries as $country)
                                <option value="{{ $country['code'] }}" @selected(($shipping['country_code'] ?? '') === $country['code'])>{{ $country['label'] }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif
                <button type="submit" class="primary-button">{{ $quote ? 'Update address and totals' : 'Review totals' }}</button>
                <p class="theme-purchase-note">I acknowledge the store policies apply to this order. Payment is not available yet.</p>
            </form>
        </main>
        <footer class="footer"><span>Checkout</span><span>MRKTFlow</span></footer>
    </div>
</body>
</html>
