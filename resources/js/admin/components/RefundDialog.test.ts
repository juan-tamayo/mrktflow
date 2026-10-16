import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import RefundDialog from './RefundDialog.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (fields: Record<string, unknown>) => ({
    ...fields,
    processing: false,
    post,
  }),
}))

it('posts quantity and goodwill refund fields with a stable key', () => {
  const wrapper = mount(RefundDialog, {
    props: {
      orderId: '11111111-1111-4111-8111-111111111111',
      refundable: {
        amount: 2805,
        money: { amount: 2805, currency: 'USD', exponent: 2, formatted: 'USD 28.05' },
        lines: [{ id: '22222222-2222-4222-8222-222222222222', quantity: 1 }],
      },
      lines: [
        {
          id: '22222222-2222-4222-8222-222222222222',
          title: 'Studio Lamp',
          sku: 'LAMP-001',
          type: 'physical',
          quantity: 1,
          refundable_quantity: 1,
          total: { amount: 2250, currency: 'USD', exponent: 2, formatted: 'USD 22.50' },
        },
      ],
    },
  })
  expect(wrapper.text()).toContain('Remaining USD 28.05')
  expect(wrapper.find('textarea[name="reason"]').exists()).toBe(true)
  expect(wrapper.find('input[name="amount"]').exists()).toBe(true)
  wrapper.find('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})
