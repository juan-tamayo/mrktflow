import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CategoryIndex from './CategoryIndex.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('lists categories and only offers create when catalog write is allowed', async () => {
  const wrapper = mount(CategoryIndex, {
    props: {
      canWrite: true,
      categories: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          title: 'Lighting',
          path: 'lighting',
          status: 'published',
        },
      ],
    },
  })
  expect(wrapper.find('a[href="/admin/categories/create"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('Lighting')
  expect(wrapper.text()).toContain('lighting')
  await wrapper.setProps({ canWrite: false, categories: [] })
  expect(wrapper.find('a[href="/admin/categories/create"]').exists()).toBe(false)
  expect(wrapper.text()).toContain('No categories yet.')
})
