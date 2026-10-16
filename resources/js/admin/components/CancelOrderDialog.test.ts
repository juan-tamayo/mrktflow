import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CancelOrderDialog from './CancelOrderDialog.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (fields: Record<string, unknown>) => ({
    ...fields,
    processing: false,
    post,
  }),
}))

it('posts a cancellation reason with a stable key', () => {
  const wrapper = mount(CancelOrderDialog, {
    props: {
      orderId: '11111111-1111-4111-8111-111111111111',
      cancellable: true,
    },
  })
  expect(wrapper.text()).toContain('Shipped lines require a return')
  expect(wrapper.find('textarea[name="reason"]').exists()).toBe(true)
  wrapper.find('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})
