<script setup lang="ts">
import { ref } from 'vue'
import type { CartSnapshot } from './cartData'
const props = defineProps<{ cart: CartSnapshot }>()
const cart = ref(props.cart)
const error = ref('')
function csrfToken(): string {
  const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]*)/)
  return match ? decodeURIComponent(match[1] ?? '') : ''
}
async function mutate(path: string, method: string, body: Record<string, unknown>): Promise<void> {
  error.value = ''
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: {
      Accept: 'application/json',
      'Content-Type': 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-XSRF-TOKEN': csrfToken(),
    },
    body: JSON.stringify(body),
  })
  const payload: unknown = await response.json().catch(() => null)
  if (!response.ok || payload === null || typeof payload !== 'object' || !('data' in payload)) {
    error.value = 'This cart changed. Reload before saving.'
    return
  }
  const next = (payload as { data: CartSnapshot }).data
  cart.value = next
}
async function updateLine(lineId: string, quantity: number): Promise<void> {
  await mutate(`/store-api/v1/cart/lines/${lineId}`, 'PATCH', {
    quantity,
    expectedRevision: cart.value.revision,
  })
}
async function removeLine(lineId: string): Promise<void> {
  await mutate(`/store-api/v1/cart/lines/${lineId}`, 'DELETE', {
    expectedRevision: cart.value.revision,
  })
}
</script>

<template>
  <div class="theme-cart-enhanced">
    <div v-if="cart.notices.length > 0 || error !== ''" class="theme-cart-notices" role="status">
      <p v-for="notice in cart.notices" :key="notice">{{ notice }}</p>
      <p v-if="error !== ''">{{ error }}</p>
    </div>
    <p v-if="cart.lines.length === 0" class="theme-empty">Your cart is empty.</p>
    <ul v-else class="theme-cart-lines">
      <li v-for="line in cart.lines" :key="line.id">
        <div>
          <strong>{{ line.title }}</strong>
          <span>{{ line.sku }}</span>
          <span>{{ line.lineTotal.formatted }}</span>
          <p v-if="!line.available" class="theme-variant-prompt" role="status">
            An item is no longer available.
          </p>
        </div>
        <form
          method="post"
          :action="`/cart/lines/${line.id}`"
          class="theme-cart-form"
          @submit.prevent="
            updateLine(line.id, Number(($event.target as HTMLFormElement).quantity.value))
          "
        >
          <label :for="`quantity-${line.id}`">Quantity</label>
          <input
            :id="`quantity-${line.id}`"
            name="quantity"
            type="number"
            min="0"
            max="99"
            :value="line.quantity"
            required
          />
          <button type="submit" class="primary-button">Update</button>
        </form>
        <form
          method="post"
          :action="`/cart/lines/${line.id}/delete`"
          @submit.prevent="removeLine(line.id)"
        >
          <button type="submit" class="text-link">Remove</button>
        </form>
      </li>
    </ul>
    <p v-if="cart.lines.length > 0" class="theme-price">
      <span>Merchandise</span><strong>{{ cart.subtotal.formatted }}</strong>
    </p>
    <p class="theme-purchase-note">Checkout is not available yet. Posted prices are ignored.</p>
  </div>
</template>
