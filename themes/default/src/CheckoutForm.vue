<script setup lang="ts">
import type { CheckoutQuote } from './checkoutData'
defineProps<{ quote: CheckoutQuote }>()
</script>

<template>
  <div id="checkout-summary" class="theme-checkout-enhanced" data-platform-slot="checkout.summary">
    <h2>Itemized total</h2>
    <ul class="theme-cart-lines">
      <li v-for="line in quote.lines" :key="line.id">
        <div>
          <strong>{{ line.title }}</strong>
          <span>{{ line.sku }}</span>
          <span>{{ line.total.formatted }}</span>
        </div>
      </li>
    </ul>
    <p class="theme-price">
      <span>Merchandise</span><strong>{{ quote.merchandise.formatted }}</strong>
    </p>
    <p class="theme-price">
      <span>Discount</span><strong>{{ quote.discount.formatted }}</strong>
    </p>
    <ul v-if="quote.offers.length > 0" class="theme-checkout-offers">
      <li v-for="offer in quote.offers" :key="offer.id">
        <strong>{{ offer.status === 'applied' ? 'Applied' : 'Not applied' }}</strong>
        {{ offer.name ?? offer.code ?? offer.kind }} — {{ offer.reason }}
      </li>
    </ul>
    <p class="theme-price">
      <span>Shipping</span><strong>{{ quote.shipping.formatted }}</strong>
    </p>
    <p class="theme-price">
      <span>Tax</span><strong>{{ quote.tax.formatted }}</strong>
    </p>
    <p class="theme-price">
      <span>Total</span><strong>{{ quote.grandTotal.formatted }}</strong>
    </p>
    <p v-if="quote.acceptedAt" class="theme-purchase-note" role="status">
      Totals confirmed. Continue to hosted payment.
    </p>
    <p v-else class="theme-purchase-note">Confirm these totals before payment.</p>
  </div>
</template>
