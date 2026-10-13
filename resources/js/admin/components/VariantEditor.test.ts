import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import VariantEditor from './VariantEditor.vue'

const put = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    put,
  }),
}))

it('saves unique option combinations without executing labels', async () => {
  const wrapper = mount(VariantEditor, {
    props: {
      productId: '11111111-1111-4111-8111-111111111111',
      version: 2,
      slug: 'studio-lamp',
      optionAxes: [{ name: 'Color', values: ['Pine', '<script>'] }],
      variants: [
        {
          id: '22222222-2222-4222-8222-222222222222',
          sku: 'LAMP-PINE',
          isDefault: true,
          available: true,
          options: [{ name: 'Color', value: 'Pine' }],
          money: { amount: 2499 },
        },
      ],
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>')
  await wrapper.get('form').trigger('submit')
  expect(put).toHaveBeenCalled()
})
