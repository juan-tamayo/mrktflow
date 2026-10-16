import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import Orders from './Orders.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('lists searchable orders with independent financial and fulfillment states', async () => {
  const wrapper = mount(Orders, {
    props: {
      canWriteNotes: true,
      orders: {
        q: 'alex@example.test',
        from: '2026-10-16',
        to: '2026-10-16',
        next_page_url: null,
        prev_page_url: null,
        data: [
          {
            id: '11111111-1111-4111-8111-111111111111',
            number: 'MABCDEF123456',
            email: 'alex@example.test',
            status: 'pending',
            financial_status: 'unpaid',
            fulfillment_status: 'unfulfilled',
            download_status: 'not_required',
            created_at: '2026-10-16T12:00:00Z',
            grand_total: { amount: 2805, currency: 'USD', exponent: 2, formatted: 'USD 28.05' },
          },
        ],
      },
    },
  })
  expect(wrapper.find('form[action="/admin/orders"]').exists()).toBe(true)
  expect(
    wrapper.find('a[href="/admin/orders/11111111-1111-4111-8111-111111111111"]').exists(),
  ).toBe(true)
  expect(wrapper.text()).toContain('MABCDEF123456')
  expect(wrapper.text()).toContain('financial unpaid')
  expect(wrapper.text()).toContain('fulfillment unfulfilled')
  await wrapper.setProps({
    canWriteNotes: false,
    orders: {
      q: '',
      from: '',
      to: '',
      next_page_url: null,
      prev_page_url: null,
      data: [],
    },
  })
  expect(wrapper.text()).toContain('No orders match this search.')
})
