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
function errorMessage(payload: unknown, fallback: string): string {
  if (
    payload !== null &&
    typeof payload === 'object' &&
    'error' in payload &&
    payload.error !== null &&
    typeof payload.error === 'object' &&
    'message' in payload.error &&
    typeof payload.error.message === 'string' &&
    payload.error.message !== ''
  ) {
    return payload.error.message
  }
  return fallback
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
    error.value = errorMessage(payload, 'Could not update this cart.')
    return
  }
  const next = (payload as { data: CartSnapshot }).data
  cart.value = next
}
async function reload(): Promise<void> {
  error.value = ''
  const response = await fetch('/store-api/v1/cart', {
    credentials: 'same-origin',
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
  })
  const payload: unknown = await response.json().catch(() => null)
  if (!response.ok || payload === null || typeof payload !== 'object' || !('data' in payload)) {
    error.value = errorMessage(payload, 'Could not reload this cart.')
    return
  }
  cart.value = (payload as { data: CartSnapshot }).data
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
    <p v-if="cart.lines.length === 0">
      <a class="primary-button" href="/search">Continue shopping</a>
    </p>
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
    <p v-if="cart.lines.some((line) => !line.available)" class="theme-variant-prompt" role="status">
      Remove unavailable items to continue.
    </p>
    <p v-if="cart.lines.length > 0 && cart.lines.every((line) => line.available)">
      <a class="primary-button" href="/checkout">Checkout</a>
    </p>
    <p v-if="cart.lines.length > 0">
      <a class="text-link" href="/search">Continue shopping</a>
    </p>
    <p v-if="error !== ''">
      <button type="button" class="text-link" @click="reload">Reload cart</button>
    </p>
    <p class="theme-purchase-note">Posted prices are ignored. Totals are calculated at checkout.</p>
  </div>
</template>
