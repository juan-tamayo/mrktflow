import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ReturnDialog from './ReturnDialog.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (fields: Record<string, unknown>) => ({
    ...fields,
    processing: false,
    post,
  }),
}))

const orderId = '11111111-1111-4111-8111-111111111111'
const lineId = '22222222-2222-4222-8222-222222222222'

it('posts a new return with bounded quantities and a reason', () => {
  post.mockClear()
  const wrapper = mount(ReturnDialog, {
    props: {
      orderId,
      action: 'create' as const,
      lines: [
        { id: lineId, title: 'Studio Lamp', sku: 'LAMP-001', max: 2 },
        { id: '33333333-3333-4333-8333-333333333333', title: 'Studio Mug', sku: 'MUG-001', max: 0 },
      ],
    },
  })
  expect(wrapper.text()).toContain('Open a return')
  expect(wrapper.text()).toContain('Studio Lamp')
  expect(wrapper.text()).toContain('returnable 2')
  expect(wrapper.text()).not.toContain('Studio Mug')
  expect(wrapper.find('textarea[name="reason"]').exists()).toBe(true)
  wrapper.find('form').trigger('submit')
  expect(post).toHaveBeenCalledWith(`/admin/orders/${orderId}/returns`, { preserveScroll: true })
})

it('posts a restock step to the return step URL without a reason field', () => {
  post.mockClear()
  const wrapper = mount(ReturnDialog, {
    props: {
      orderId,
      action: 'restock' as const,
      returnId: '44444444-4444-4444-8444-444444444444',
      lines: [{ id: lineId, title: 'Studio Lamp', sku: 'LAMP-001', max: 1 }],
    },
  })
  expect(wrapper.text()).toContain('Restock return')
  expect(wrapper.text()).toContain('received not restocked 1')
  expect(wrapper.find('textarea[name="reason"]').exists()).toBe(false)
  wrapper.find('form').trigger('submit')
  expect(post).toHaveBeenCalledWith(
    `/admin/orders/${orderId}/returns/44444444-4444-4444-8444-444444444444/restock`,
    { preserveScroll: true },
  )
})
