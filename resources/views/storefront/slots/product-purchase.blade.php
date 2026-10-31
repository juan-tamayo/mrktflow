{{-- Platform component (slot: product.purchase). Add-to-cart, save and
     watch forms post to platform endpoints with server-owned prices; a
     theme cannot replace them. Rendered by the product template when at
     least one variant is available, with the product page's view data in
     scope ($product, $selected, $selectionState, $cartRevision). --}}
<div data-platform-slot="product.purchase">
    <form method="post" action="/cart/lines" class="theme-cart-form">
        @csrf
        <input type="hidden" name="expected_revision" value="{{ $cartRevision }}">
        @if($selectionState === 'selected')
            <input type="hidden" name="variant_id" value="{{ $selected['id'] }}">
        @else
            <div class="setup-field">
                <label for="cart-variant">Variant</label>
                <select id="cart-variant" name="variant_id" required>
                    <option value="">Choose a combination</option>
                    @foreach($product->variants as $variant)
                        @if($variant['available'])
                            <option value="{{ $variant['id'] }}">{{ $variant['label'] }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
        @endif
        <div class="setup-field">
            <label for="cart-quantity">Quantity</label>
            <input id="cart-quantity" name="quantity" type="number" min="1" max="99" value="1" required>
        </div>
        <button type="submit" class="primary-button">Add to cart</button>
    </form>
    <form method="post" action="/wishlist/items" class="theme-wishlist-form">
        @csrf
        @if($selectionState === 'selected')
            <input type="hidden" name="variant_id" value="{{ $selected['id'] }}">
        @else
            <div class="setup-field">
                <label for="wishlist-variant">Variant</label>
                <select id="wishlist-variant" name="variant_id" required>
                    <option value="">Choose a combination</option>
                    @foreach($product->variants as $variant)
                        @if($variant['available'])
                            <option value="{{ $variant['id'] }}">{{ $variant['label'] }}</option>
                        @endif
                    @endforeach
                </select>
            </div>
        @endif
        <button type="submit" class="text-link">Save for later</button>
    </form>
    <div data-compare-actions data-compare="{{ json_encode(['productId' => $product->id, 'title' => $product->title, 'variants' => collect($product->variants)->where('available', true)->map(fn (array $variant): array => ['id' => $variant['id'], 'label' => $variant['label']])->values()->all(), 'selectedId' => $selectionState === 'selected' ? $selected['id'] : null], JSON_THROW_ON_ERROR) }}"></div>
    <div id="watch-options" data-endpoint="/watch/subscriptions" data-csrf="{{ csrf_token() }}" data-email="{{ request()->user('customer')?->email ?? '' }}" data-variants="{{ json_encode(collect($product->variants)->where('available', true)->map(fn (array $variant): array => ['id' => $variant['id'], 'label' => $variant['label']])->values()->all(), JSON_THROW_ON_ERROR) }}" @if($selectionState === 'selected') data-selected-id="{{ $selected['id'] }}" @endif>
        <form method="post" action="/watch/subscriptions" class="theme-watch-form">
            @csrf
            @if($selectionState === 'selected')
                <input type="hidden" name="variant_id" value="{{ $selected['id'] }}">
            @else
                <div class="setup-field">
                    <label for="watch-variant">Variant</label>
                    <select id="watch-variant" name="variant_id" required>
                        <option value="">Choose a combination</option>
                        @foreach($product->variants as $variant)
                            @if($variant['available'])
                                <option value="{{ $variant['id'] }}">{{ $variant['label'] }}</option>
                            @endif
                        @endforeach
                    </select>
                </div>
            @endif
            <div class="setup-field">
                <label for="watch-email">Email for alerts</label>
                <input id="watch-email" name="email" type="email" required value="{{ request()->user('customer')?->email ?? '' }}">
            </div>
            <fieldset class="setup-field">
                <legend>Email me when</legend>
                <label><input type="checkbox" name="types[]" value="stock"> It is back in stock</label>
                <label><input type="checkbox" name="types[]" value="price"> The price drops</label>
            </fieldset>
            <button type="submit" class="text-link">Set alerts</button>
        </form>
    </div>
    <p class="theme-purchase-note">Checkout is not available yet. Prices are set by the store, not this form.</p>
</div>
