import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import NotificationFailures from './NotificationFailures.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: () => ({ processing: false, post }),
}))

it('shows visible failure codes and retries failed deliveries', async () => {
  const wrapper = mount(NotificationFailures, {
    props: {
      exactlyOnce: false,
      disclaimer:
        'Transactional delivery is at-least-once: a crash after the transport accepts a message can send a duplicate. This store does not promise exactly-once SMTP.',
      retried: false,
      failures: [
        {
          id: '14397058-5ad8-4f45-9bbf-b87153dca0ba',
          template: 'order-receipt',
          policyClass: 'transactional',
          status: 'failed',
          errorCode: 'transport_failed',
          attempts: 6,
          recipient: 'buyer@example.test',
          availableAt: null,
        },
      ],
    },
  })
  expect(wrapper.text()).toContain('transport_failed')
  expect(wrapper.text()).toContain('does not promise exactly-once SMTP')
  await wrapper.get('button').trigger('click')
  expect(post).toHaveBeenCalledWith(
    '/admin/notifications/14397058-5ad8-4f45-9bbf-b87153dca0ba/retry',
    expect.objectContaining({ preserveScroll: true }),
  )
})

it('does not execute untrusted recipient text', () => {
  const wrapper = mount(NotificationFailures, {
    props: {
      exactlyOnce: false,
      disclaimer: 'Transactional delivery is at-least-once.',
      retried: false,
      failures: [
        {
          id: '24397058-5ad8-4f45-9bbf-b87153dca0ba',
          template: 'newsletter',
          policyClass: 'marketing',
          status: 'pending',
          errorCode: 'delivery_not_allowed',
          attempts: 0,
          recipient: '<script>alert(1)</script>',
          availableAt: null,
        },
      ],
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
  expect(wrapper.find('button').exists()).toBe(false)
})
