import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import StoreNotice from './StoreNotice.vue'

describe('StoreNotice', () => {
  it('opens and closes the answer with an accessible state', async () => {
    const wrapper = mount(StoreNotice, {
      props: { question: 'Can I place an order?', answer: 'This store has not opened yet.' },
    })
    const button = wrapper.get('button')
    expect(button.attributes('aria-expanded')).toBe('false')
    expect(wrapper.find('[role="region"]').exists()).toBe(false)
    await button.trigger('click')
    expect(button.attributes('aria-expanded')).toBe('true')
    expect(wrapper.get('[role="region"]').text()).toBe('This store has not opened yet.')
    expect(wrapper.get('[role="region"]').attributes('id')).toBe(button.attributes('aria-controls'))
    await button.trigger('click')
    expect(wrapper.find('[role="region"]').exists()).toBe(false)
  })

  it('renders supplied text without interpreting HTML', async () => {
    const wrapper = mount(StoreNotice, {
      props: {
        question: '<img src=x onerror=alert(1)>',
        answer: '<script>alert(1)</script>',
      },
    })
    await wrapper.get('button').trigger('click')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.find('script').exists()).toBe(false)
    expect(wrapper.text()).toContain('<script>alert(1)</script>')
  })
})
