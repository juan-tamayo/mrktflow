import { mount } from '@vue/test-utils'
import { expect, it } from 'vitest'
import AttributeFields from './AttributeFields.vue'

it('adds a typed attribute definition row', async () => {
  const wrapper = mount(AttributeFields, {
    props: { mode: 'definition', attributes: [] },
  })
  await wrapper.get('button').trigger('click')
  expect(wrapper.find('#attribute-key-0').exists()).toBe(true)
})

it('collects values for required schema fields without executing labels', () => {
  const wrapper = mount(AttributeFields, {
    props: {
      mode: 'values',
      fields: [
        {
          key: 'lens.mount',
          type: 'enum',
          required: true,
          comparable: true,
          filterable: true,
          label: '<script>alert(1)</script>',
          options: ['rf', 'e'],
        },
      ],
      values: {},
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
  expect(wrapper.find('[id="value-lens.mount"]').exists()).toBe(true)
})
