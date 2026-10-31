@extends('default-theme::layout', [
    'pageKey' => 'checkout',
    'pageTitle' => 'Checkout · MRKTFlow',
    'pageAssets' => ['resources/css/app.css', 'themes/default/src/theme.css', 'themes/default/src/checkout.ts', 'themes/default/src/cartIndicator.ts'],
    'mainId' => 'checkout-main',
    'mainClass' => 'theme-checkout',
    'skipLabel' => 'Skip to checkout',
    'footerLabel' => 'Checkout',
    'masthead' => ['cartIndicator' => ['itemCount' => $cartItemCount, 'revision' => $cart['revision']]],
])
@section('content')
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
            <h1>Checkout<span class="accent">.</span></h1>
            @if($errors->any())
                <div class="theme-cart-notices" role="alert">@foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach</div>
            @endif
            @if($quote)
                <div id="checkout-quote" data-quote="{{ json_encode($payload, JSON_THROW_ON_ERROR) }}">
                    @include('storefront.slots.checkout-summary')
                    @include('storefront.slots.checkout-actions')
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
@endsection
