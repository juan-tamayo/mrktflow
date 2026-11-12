import { mount } from '@vue/test-utils'
import { expect, it } from 'vitest'
import DraftDiff from './DraftDiff.vue'

it('shows the current draft beside the editable AI candidate', () => {
  const wrapper = mount(DraftDiff, {
    props: {
      beforeTitle: 'Lighting guide',
      afterTitle: 'Studio lighting',
      beforeSummary: '{"slug":"lighting-guide"}',
      afterSummary: '[{"id":"intro"}]',
    },
  })
  expect(wrapper.get('#draft-diff-heading').text()).toBe('Proposed changes')
  expect(wrapper.text()).toContain('Lighting guide')
  expect(wrapper.text()).toContain('Studio lighting')
  expect(wrapper.text()).toContain('lighting-guide')
  expect(wrapper.find('script').exists()).toBe(false)
})
