import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import PrivacyRequests from './PrivacyRequests.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('shows configured retention and a native process form', () => {
  const wrapper = mount(PrivacyRequests, {
    props: {
      processed: true,
      retention: { guest_carts_days: 30, ai_prompt_logs_days: 30, order_days: 365 },
      requests: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          type: 'deletion',
          status: 'pending',
          email: 'privacy-shopper@example.test',
          verified_at: '2026-10-16 14:00:00',
          processed_at: null,
        },
      ],
    },
  })
  expect(wrapper.get('h1').text()).toContain('Privacy requests')
  expect(wrapper.get('[role="note"]').text()).toContain('365 days')
  expect(wrapper.get('[role="status"]').text()).toContain('Privacy request processed')
  expect(wrapper.get('form').attributes('action')).toBe(
    '/admin/privacy/11111111-1111-4111-8111-111111111111/process',
  )
  expect(wrapper.get('form').attributes('method')).toBe('post')
})

it('does not execute untrusted request copy', () => {
  const wrapper = mount(PrivacyRequests, {
    props: {
      processed: false,
      retention: { guest_carts_days: 30, ai_prompt_logs_days: 30, order_days: null },
      requests: [
        {
          id: '22222222-2222-4222-8222-222222222222',
          type: '<script>alert(1)</script>',
          status: 'completed',
          email: '<script>alert(1)</script>',
          verified_at: '2026-10-16 14:00:00',
          processed_at: '2026-10-16 15:00:00',
        },
      ],
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
  expect(wrapper.find('form').exists()).toBe(false)
  expect(wrapper.find('[role="status"]').exists()).toBe(false)
  expect(wrapper.get('[role="note"]').text()).toContain('configured per store policy')
})
