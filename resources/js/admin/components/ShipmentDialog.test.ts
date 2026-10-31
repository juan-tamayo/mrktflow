import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ShipmentDialog from './ShipmentDialog.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (fields: Record<string, unknown>) => ({
    ...fields,
    processing: false,
    post,
  }),
}))

it('posts shipment quantities and tracking fields with a stable key', () => {
  const wrapper = mount(ShipmentDialog, {
    props: {
      orderId: '11111111-1111-4111-8111-111111111111',
      lines: [
        {
          id: '22222222-2222-4222-8222-222222222222',
          title: 'Studio Lamp',
          sku: 'LAMP-001',
          type: 'physical',
          quantity: 3,
          shipped_qty: 1,
          shippable_quantity: 2,
        },
        {
          id: '33333333-3333-4333-8333-333333333333',
          title: 'Lighting Guide',
          sku: 'GUIDE-001',
          type: 'digital',
          quantity: 1,
          shipped_qty: 0,
          shippable_quantity: 0,
        },
      ],
    },
  })
  expect(wrapper.text()).toContain('Studio Lamp')
  expect(wrapper.text()).toContain('unshipped 2')
  expect(wrapper.text()).not.toContain('Lighting Guide')
  expect(wrapper.find('input[name="carrier"]').exists()).toBe(true)
  expect(wrapper.find('input[name="tracking_number"]').exists()).toBe(true)
  expect(wrapper.find('input[name="tracking_url"]').exists()).toBe(true)
  wrapper.find('form').trigger('submit')
  expect(post).toHaveBeenCalledWith(
    '/admin/orders/11111111-1111-4111-8111-111111111111/shipments',
    {
      preserveScroll: true,
    },
  )
})
