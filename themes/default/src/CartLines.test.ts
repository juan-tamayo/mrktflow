import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CartLines from './CartLines.vue'
import { parseCart } from './cartData'

const cart = {
  id: '10000000-0000-4000-8000-000000000010',
  revision: 4,
  lines: [
    {
      id: '10000000-0000-4000-8000-000000000011',
      variantId: '10000000-0000-4000-8000-000000000002',
      productId: '10000000-0000-4000-8000-000000000001',
      title: 'Studio Lamp',
      sku: 'LAMP-001',
      type: 'physical' as const,
      quantity: 5,
      available: true,
      availability: 'in_stock',
      maxQuantity: 5,
      money: { amount: 2499, currency: 'USD', exponent: 2, formatted: 'USD 24.99' },
      lineTotal: { amount: 12495, currency: 'USD', exponent: 2, formatted: 'USD 124.95' },
    },
  ],
  subtotal: { amount: 12495, currency: 'USD', exponent: 2, formatted: 'USD 124.95' },
  notices: ['Some quantities were adjusted to the allowed maximum.'],
}

describe('default theme cart lines', () => {
  it('shows merge adjustment notices without creating checkout authority', () => {
    const wrapper = mount(CartLines, { props: { cart } })
    expect(wrapper.get('[role="status"]').text()).toContain(
      'Some quantities were adjusted to the allowed maximum.',
    )
    expect(wrapper.text()).toContain('Studio Lamp')
    expect(wrapper.text()).toContain('USD 124.95')
    expect(wrapper.text()).toContain('Checkout is not available yet')
    expect(wrapper.text()).not.toContain('Checkout now')
    expect(wrapper.find('a[href="/checkout"]').exists()).toBe(false)
  })

  it('accepts only bounded public cart payloads', () => {
    expect(parseCart(cart)).not.toBeNull()
    expect(parseCart({ ...cart, secret: true })).toBeNull()
    expect(parseCart({ ...cart, revision: 0 })).toBeNull()
    expect(parseCart(null)).toBeNull()
  })
})
