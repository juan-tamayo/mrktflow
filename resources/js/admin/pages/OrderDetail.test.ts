import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import OrderDetail from './OrderDetail.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (fields: Record<string, unknown>) => ({
    ...fields,
    processing: false,
    post,
    reset: vi.fn(),
  }),
}))

it('shows frozen lines totals independent states and notes', () => {
  const wrapper = mount(OrderDetail, {
    props: {
      saved: true,
      canRefund: false,
      canCancel: false,
      canResendReceipt: true,
      refundSaved: false,
      cancellationSaved: false,
      receiptSaved: false,
      order: {
        id: '11111111-1111-4111-8111-111111111111',
        number: 'MABCDEF123456',
        email: 'alex@example.test',
        status: 'confirmed',
        financial_status: 'paid',
        fulfillment_status: 'unfulfilled',
        download_status: 'not_required',
        created_at: '2026-10-16T12:00:00Z',
        coupon_code: 'SAVE10',
        totals: {
          merchandise: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
          discount: { amount: 250, currency: 'USD', exponent: 2, formatted: 'USD 2.50' },
          shipping: { amount: 300, currency: 'USD', exponent: 2, formatted: 'USD 3.00' },
          tax: { amount: 255, currency: 'USD', exponent: 2, formatted: 'USD 2.55' },
          grand_total: { amount: 2805, currency: 'USD', exponent: 2, formatted: 'USD 28.05' },
        },
        lines: [
          {
            id: '22222222-2222-4222-8222-222222222222',
            type: 'physical',
            sku: 'LAMP-001',
            title: 'Studio Lamp',
            quantity: 1,
            refundable_quantity: 1,
            held_qty: 0,
            cancelled_qty: 0,
            subtotal: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
            discount: { amount: 250, currency: 'USD', exponent: 2, formatted: 'USD 2.50' },
            total: { amount: 2250, currency: 'USD', exponent: 2, formatted: 'USD 22.50' },
          },
        ],
        billing: {
          recipient: 'Alex Shopper',
          line1: '10 Market Street',
          line2: '',
          city: 'Los Angeles',
          region: 'CA',
          postal_code: '90001',
          country_code: 'US',
        },
        shipping: {
          recipient: 'Alex Shopper',
          line1: '10 Market Street',
          line2: '',
          city: 'Los Angeles',
          region: 'CA',
          postal_code: '90001',
          country_code: 'US',
        },
        notes: [
          {
            id: '33333333-3333-4333-8333-333333333333',
            body: 'Hold for address confirmation.',
            staff_name: 'Example Owner',
            created_at: '2026-10-16T12:05:00Z',
          },
        ],
        refunds: [],
        refundable: {
          amount: 2805,
          money: { amount: 2805, currency: 'USD', exponent: 2, formatted: 'USD 28.05' },
          lines: [{ id: '22222222-2222-4222-8222-222222222222', quantity: 1 }],
        },
        cancellable: true,
        timeline: [
          {
            id: '44444444-4444-4444-8444-444444444444',
            type: 'paid',
            created_at: '2026-10-16T12:00:00Z',
            amount: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          },
        ],
      },
    },
  })
  expect(wrapper.text()).toContain('financial paid')
  expect(wrapper.text()).toContain('fulfillment unfulfilled')
  expect(wrapper.text()).toContain('Studio Lamp')
  expect(wrapper.text()).toContain('USD 28.05')
  expect(wrapper.text()).toContain('Hold for address confirmation.')
  expect(wrapper.text()).toContain('Internal note saved.')
  expect(wrapper.find('textarea[name="note"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('paid · 2026-10-16T12:00:00Z')
  expect(wrapper.text()).toContain('Resend receipt')
})
