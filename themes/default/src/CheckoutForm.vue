<script setup lang="ts">
import type { CheckoutQuote } from './checkoutData'
defineProps<{ quote: CheckoutQuote }>()
</script>

<template>
  <div class="theme-checkout-enhanced">
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
      Totals confirmed. Payment is not available yet.
    </p>
    <p v-else class="theme-purchase-note">Payment is not available yet.</p>
  </div>
</template>
