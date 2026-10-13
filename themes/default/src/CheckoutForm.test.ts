import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CheckoutForm from './CheckoutForm.vue'
import { parseCheckoutQuote } from './checkoutData'

const quote = {
  id: '10000000-0000-4000-8000-000000000032',
  cartRevision: 2,
  email: 'guest@example.test',
  fingerprint: 'a'.repeat(64),
  expiresAt: '2026-10-13T18:15:00Z',
  acceptedAt: null,
  priceMode: 'exclusive',
  requiresDelivery: true,
  digitalOnly: false,
  coupon: 'SAVE10',
  merchandise: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
  discount: { amount: 250, currency: 'USD', exponent: 2, formatted: 'USD 2.50' },
  shipping: { amount: 300, currency: 'USD', exponent: 2, formatted: 'USD 3.00' },
  tax: { amount: 255, currency: 'USD', exponent: 2, formatted: 'USD 2.55' },
  grandTotal: { amount: 2805, currency: 'USD', exponent: 2, formatted: 'USD 28.05' },
  lines: [
    {
      id: '10000000-0000-4000-8000-000000000011',
      title: 'Studio Lamp',
      sku: 'LAMP-001',
      quantity: 2,
      subtotal: { amount: 2000, currency: 'USD', exponent: 2, formatted: 'USD 20.00' },
      discount: { amount: 200, currency: 'USD', exponent: 2, formatted: 'USD 2.00' },
      total: { amount: 1800, currency: 'USD', exponent: 2, formatted: 'USD 18.00' },
    },
  ],
}

describe('default theme checkout quote', () => {
  it('shows itemized totals without payment authority', () => {
    const wrapper = mount(CheckoutForm, { props: { quote } })
    expect(wrapper.text()).toContain('USD 28.05')
    expect(wrapper.text()).toContain('Payment is not available yet')
    expect(wrapper.text()).not.toContain('Pay now')
    expect(parseCheckoutQuote(quote)).not.toBeNull()
    expect(parseCheckoutQuote({ ...quote, secret: true })).toBeNull()
  })
})
