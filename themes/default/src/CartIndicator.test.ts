import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CartIndicator from './CartIndicator.vue'
import { parseCartIndicator } from './cartData'

describe('default theme cart indicator', () => {
  it('shows the current item count without purchase controls', () => {
    const wrapper = mount(CartIndicator, { props: { itemCount: 5 } })
    expect(wrapper.get('a').attributes('href')).toBe('/cart')
    expect(wrapper.text()).toContain('Cart (5)')
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('accepts only bounded public indicator payloads', () => {
    expect(parseCartIndicator({ itemCount: 5, revision: 2 })).not.toBeNull()
    expect(parseCartIndicator({ itemCount: 5, revision: 2, customerId: 'secret' })).toBeNull()
    expect(parseCartIndicator({ itemCount: -1, revision: 2 })).toBeNull()
  })
})
