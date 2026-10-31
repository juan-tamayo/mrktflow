{{-- Platform component (slot: cart.line-actions). Quantity updates and
     removals post to the platform cart endpoints with the cart revision;
     a theme cannot replace them. Included once per cart line by the cart
     template with $line and $revision in scope. --}}
<div class="theme-cart-line-actions" data-platform-slot="cart.line-actions">
    <form method="post" action="/cart/lines/{{ $line['id'] }}" class="theme-cart-form">
        @csrf
        <input type="hidden" name="expected_revision" value="{{ $revision }}">
        <label for="quantity-{{ $line['id'] }}">Quantity</label>
        <input id="quantity-{{ $line['id'] }}" name="quantity" type="number" min="0" max="99" value="{{ $line['quantity'] }}" required>
        <button type="submit" class="primary-button">Update</button>
    </form>
    <form method="post" action="/cart/lines/{{ $line['id'] }}/delete">
        @csrf
        <input type="hidden" name="expected_revision" value="{{ $revision }}">
        <button type="submit" class="text-link">Remove</button>
    </form>
</div>
