import { mount } from '@vue/test-utils'
import { expect, it } from 'vitest'
import SchemaMigration from './SchemaMigration.vue'

it('previews affected products and blocks apply until the report allows it', async () => {
  const wrapper = mount(SchemaMigration, {
    props: { assignedCount: 2, preview: null, processing: false },
  })
  expect(wrapper.text()).toContain('2 assigned products keep current values')
  await wrapper.get('button').trigger('click')
  expect(wrapper.emitted('preview')).toHaveLength(1)
  expect(wrapper.get('button.primary-button').attributes('disabled')).toBeDefined()
  await wrapper.setProps({
    preview: {
      affected_products: 2,
      can_apply: true,
      compatible: true,
      invalid_values: [],
      comparison_changes: [
        { key: 'body.weight', change: 'added', comparable: true, filterable: true },
      ],
      incompatible_fields: [],
    },
  })
  expect(wrapper.text()).toContain('Affected products: 2')
  expect(wrapper.text()).toContain('body.weight')
  await wrapper.get('button.primary-button').trigger('click')
  expect(wrapper.emitted('apply')).toHaveLength(1)
})

it('does not execute untrusted invalid-value titles', () => {
  const wrapper = mount(SchemaMigration, {
    props: {
      assignedCount: 1,
      processing: false,
      preview: {
        affected_products: 1,
        can_apply: false,
        compatible: true,
        invalid_values: [
          {
            product_id: '11111111-1111-4111-8111-111111111111',
            title: '<script>alert(1)</script>',
            field: 'body.weight',
            message: 'This attribute is required.',
          },
        ],
        comparison_changes: [],
        incompatible_fields: [],
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
})
