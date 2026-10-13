import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ProductSchema from './ProductSchema.vue'

const post = vi.fn()
const patch = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    transform: vi.fn(),
    post,
    patch,
  }),
}))

it('creates a schema through the editor form', async () => {
  const wrapper = mount(ProductSchema, { props: { schema: null, saved: false } })
  await wrapper.get('#schema-label').setValue('Camera')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('does not execute untrusted schema labels', () => {
  const wrapper = mount(ProductSchema, {
    props: {
      saved: false,
      schema: {
        id: '11111111-1111-4111-8111-111111111111',
        key: 'camera',
        label: '<script>alert(1)</script>',
        comparison_group: 'cameras',
        default_template_key: 'product.default',
        version: 1,
        attributes: [],
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.get('#schema-label').element).toHaveProperty('value', '<script>alert(1)</script>')
})
