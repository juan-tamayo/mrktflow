import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ProductSchemaIndex from './ProductSchemaIndex.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('lists schemas and only offers create when catalog write is allowed', async () => {
  const wrapper = mount(ProductSchemaIndex, {
    props: {
      canWrite: true,
      schemas: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          key: 'camera',
          label: 'Camera',
          comparison_group: 'cameras',
          schema_version: 1,
        },
      ],
    },
  })
  expect(wrapper.find('a[href="/admin/schemas/create"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('Camera')
  expect(wrapper.text()).toContain('camera')
  await wrapper.setProps({ canWrite: false, schemas: [] })
  expect(wrapper.find('a[href="/admin/schemas/create"]').exists()).toBe(false)
  expect(wrapper.text()).toContain('No schemas yet.')
})
