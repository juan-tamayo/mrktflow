import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SpecSheet from './SpecSheet.vue'
import { parseSpecSheet } from './specSheet'

describe('example theme spec sheet', () => {
  it('renders public attributes without purchase controls', () => {
    const wrapper = mount(SpecSheet, {
      props: {
        title: 'The studio lamp',
        attributes: [{ label: 'Material', value: 'Steel' }],
      },
    })
    expect(wrapper.text()).toContain('Specifications')
    expect(wrapper.text()).toContain('Material')
    expect(wrapper.text()).toContain('Steel')
    expect(wrapper.find('button').exists()).toBe(false)
    expect(wrapper.find('form').exists()).toBe(false)
  })

  it('accepts only bounded public specification payloads', () => {
    expect(
      parseSpecSheet({ title: 'Lamp', attributes: [{ label: 'Material', value: 'Steel' }] }),
    ).not.toBeNull()
    expect(
      parseSpecSheet({
        title: 'Lamp',
        attributes: [{ label: 'Material', value: 'Steel' }],
        supplierCost: 12,
      }),
    ).toBeNull()
    expect(parseSpecSheet({ title: 'Lamp', attributes: 'secret' })).toBeNull()
  })
})
