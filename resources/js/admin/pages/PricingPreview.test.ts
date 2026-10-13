import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import PricingPreview from './PricingPreview.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    post,
  }),
}))

const money = {
  amount: 2805,
  currency: 'USD',
  exponent: 2,
  formatted: 'USD 28.05',
}

it('submits a mixed cart preview through the form', async () => {
  const wrapper = mount(PricingPreview, {
    props: { currency: 'USD', exponent: 2, canWrite: true, preview: null },
  })
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('shows itemized totals and does not execute untrusted reasons', () => {
  const wrapper = mount(PricingPreview, {
    props: {
      currency: 'USD',
      exponent: 2,
      canWrite: true,
      preview: {
        ok: true,
        reason: null,
        cart: 'mixed',
        price_mode: 'exclusive',
        currency: 'USD',
        exponent: 2,
        coupon: 'SAVE10',
        merchandise: { ...money, amount: 2500, formatted: 'USD 25.00' },
        discount: { ...money, amount: 250, formatted: 'USD 2.50' },
        shipping: { ...money, amount: 300, formatted: 'USD 3.00' },
        tax: { ...money, amount: 255, formatted: 'USD 2.55' },
        grand_total: money,
      },
    },
  })
  expect(wrapper.text()).toContain('Merchandise')
  expect(wrapper.text()).toContain('USD 28.05')
  expect(wrapper.find('script').exists()).toBe(false)
})

it('shows a failure reason as text', () => {
  const wrapper = mount(PricingPreview, {
    props: {
      currency: 'USD',
      exponent: 2,
      canWrite: true,
      preview: {
        ok: false,
        reason: '<script>alert(1)</script>',
        cart: 'mixed',
        price_mode: '',
        currency: 'USD',
        exponent: 2,
        coupon: null,
        merchandise: { ...money, amount: 0, formatted: 'USD 0.00' },
        discount: { ...money, amount: 0, formatted: 'USD 0.00' },
        shipping: { ...money, amount: 0, formatted: 'USD 0.00' },
        tax: { ...money, amount: 0, formatted: 'USD 0.00' },
        grand_total: { ...money, amount: 0, formatted: 'USD 0.00' },
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.get('[role="alert"]').text()).toBe('<script>alert(1)</script>')
})
