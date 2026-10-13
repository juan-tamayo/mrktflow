import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ProductEdit from './ProductEdit.vue'

const post = vi.fn()
const patch = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    post,
    patch,
  }),
}))

it('creates a draft through the product form', async () => {
  const wrapper = mount(ProductEdit, { props: { product: null, categories: [], saved: false } })
  expect(wrapper.find('input[type="file"]').exists()).toBe(true)
  await wrapper.get('#product-title').setValue('Studio Lamp')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('does not execute untrusted product titles', () => {
  const wrapper = mount(ProductEdit, {
    props: {
      saved: false,
      product: {
        id: '11111111-1111-4111-8111-111111111111',
        title: '<script>alert(1)</script>',
        slug: 'studio-lamp',
        description: 'Lamp',
        status: 'draft',
        version: 1,
        image: {
          url: '/storage/media/11111111-1111-4111-8111-111111111111/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
          alt: 'Lamp',
        },
        variant: { sku: 'LAMP-001', money: { amount: 2499 } },
        category_ids: [],
      },
      categories: [],
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.get('#product-title').element).toHaveProperty('value', '<script>alert(1)</script>')
})
