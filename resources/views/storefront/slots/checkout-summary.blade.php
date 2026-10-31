{{-- Platform component (slot: checkout.summary). The itemized totals are
     server-owned facts; a theme cannot replace or restyle this component
     into different behavior. Rendered by the checkout template inside the
     quote block. --}}
<div id="checkout-summary" data-platform-slot="checkout.summary">
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
