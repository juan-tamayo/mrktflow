import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CountryPicker from './CountryPicker.vue'

describe('country selection', () => {
  it('filters choices and reports checkbox selection without losing other countries', async () => {
    const wrapper = mount(CountryPicker, {
      props: {
        modelValue: ['CA'],
        options: [
          { code: 'CA', label: 'Canada' },
          { code: 'US', label: 'United States' },
        ],
      },
    })
    await wrapper.find('input[type="search"]').setValue('United')
    expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(1)
    await wrapper.find('input[type="checkbox"]').setValue(true)
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([['CA', 'US']])
  })
  it('explains an empty search result', async () => {
    const wrapper = mount(CountryPicker, {
      props: { modelValue: [], options: [{ code: 'CA', label: 'Canada' }] },
    })
    await wrapper.find('input[type="search"]').setValue('nowhere')
    expect(wrapper.text()).toContain('No countries match your search.')
  })
})
