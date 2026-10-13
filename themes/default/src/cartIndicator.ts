import { createApp } from 'vue'
import CartIndicator from './CartIndicator.vue'
import { parseCartIndicator } from './cartData'

const indicator = document.getElementById('cart-indicator')
if (indicator?.dataset.cart) {
  let payload: unknown = null
  try {
    payload = JSON.parse(indicator.dataset.cart)
  } catch {
    /* Leave the native cart link intact. */
  }
  const input = parseCartIndicator(payload)
  if (input) createApp(CartIndicator, { itemCount: input.itemCount }).mount(indicator)
}
