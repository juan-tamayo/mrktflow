import { createApp } from 'vue'
import CartLines from './CartLines.vue'
import { parseCart } from './cartData'

const lines = document.getElementById('cart-lines')
if (lines?.dataset.cart) {
  let payload: unknown = null
  try {
    payload = JSON.parse(lines.dataset.cart)
  } catch {
    /* Leave the native cart forms intact. */
  }
  const cart = parseCart(payload)
  if (cart) createApp(CartLines, { cart }).mount(lines)
}
