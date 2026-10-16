import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ResendReceiptDialog from './ResendReceiptDialog.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (fields: Record<string, unknown>) => ({
    ...fields,
    processing: false,
    post,
  }),
}))

it('posts an explicit receipt resend', () => {
  const wrapper = mount(ResendReceiptDialog, {
    props: {
      orderId: '11111111-1111-4111-8111-111111111111',
    },
  })
  expect(wrapper.text()).toContain('does not replay the original paid event')
  wrapper.find('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})
