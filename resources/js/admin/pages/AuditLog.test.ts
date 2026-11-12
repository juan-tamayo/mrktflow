import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import AuditLog from './AuditLog.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('lists allowlisted audit rows and escapes untrusted action text', () => {
  const wrapper = mount(AuditLog, {
    props: {
      events: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          action: 'staff.created',
          subject_type: 'staff',
          subject_id: '22222222-2222-4222-8222-222222222222',
          actor_type: 'system',
          actor_id: null,
          metadata: { version: 1, reason_code: '<script>alert(1)</script>' },
          correlation_id: '33333333-3333-4333-8333-333333333333',
          occurred_at: '2026-10-16 14:00:00',
        },
      ],
    },
  })
  expect(wrapper.get('h1').text()).toContain('Audit log')
  expect(wrapper.text()).toContain('staff.created')
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
  expect(wrapper.find('a[href="/admin/health"]').exists()).toBe(true)
})
