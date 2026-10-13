import { createApp } from 'vue'
import CheckoutForm from './CheckoutForm.vue'
import { parseCheckoutQuote } from './checkoutData'

const root = document.getElementById('checkout-quote')
const summary = document.getElementById('checkout-summary')
if (root?.dataset.quote && summary) {
  let payload: unknown = null
  try {
    payload = JSON.parse(root.dataset.quote)
  } catch {
    /* Leave the native checkout forms intact. */
  }
  const quote = parseCheckoutQuote(payload)
  if (quote) createApp(CheckoutForm, { quote }).mount(summary)
}
