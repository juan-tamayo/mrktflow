{{-- Platform component (slot: checkout.actions). Quote acceptance and the
     hosted-payment handoff are authoritative commerce actions; a theme
     cannot replace them. Rendered by the checkout template inside the
     quote block, after checkout.summary. --}}
@php
    $accepted = filled($quote['accepted_at'] ?? $quote['acceptedAt'] ?? null);
    $grandAmount = (int) data_get($quote, 'grand_total.amount', data_get($quote, 'grandTotal.amount', 1));
@endphp
<div data-platform-slot="checkout.actions">
    <form method="post" action="/checkout/{{ $quote['id'] }}/accept" class="theme-checkout-form">
        @csrf
        <input type="hidden" name="fingerprint" value="{{ $quote['fingerprint'] }}">
        <label><input type="checkbox" name="policy_acknowledged" value="1" required> I acknowledge the store policies</label>
        <button type="submit" class="primary-button">Confirm these totals</button>
    </form>
    @if($accepted)
        <p class="theme-purchase-note" role="status">Totals confirmed. Continue to hosted payment.</p>
        <form method="post" action="/checkout/{{ $quote['id'] }}/pay" class="theme-checkout-form">
            @csrf
            <input type="hidden" name="fingerprint" value="{{ $quote['fingerprint'] }}">
            <button type="submit" class="primary-button">{{ $grandAmount === 0 ? 'Place order' : 'Pay now' }}</button>
        </form>
    @else
        <p class="theme-purchase-note">Confirm these totals before payment. Hosted payment starts after confirmation.</p>
    @endif
</div>
