import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ProductIndex from './ProductIndex.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('lists products and only offers create when catalog write is allowed', async () => {
  const wrapper = mount(ProductIndex, {
    props: {
      canWrite: true,
      products: {
        data: [
          {
            id: '11111111-1111-4111-8111-111111111111',
            title: 'Studio Lamp',
            slug: 'studio-lamp',
            status: 'draft',
            updated_at: '2026-10-13 00:00:00',
          },
        ],
        next_page_url: null,
        prev_page_url: null,
      },
    },
  })
  expect(wrapper.find('a[href="/admin/products/create"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('Studio Lamp')
  expect(wrapper.text()).toContain('draft')
  expect(wrapper.text()).toContain('storefront remains closed')
  await wrapper.setProps({
    canWrite: false,
    products: { data: [], next_page_url: null, prev_page_url: null },
  })
  expect(wrapper.find('a[href="/admin/products/create"]').exists()).toBe(false)
  expect(wrapper.text()).toContain('No products yet.')
})
