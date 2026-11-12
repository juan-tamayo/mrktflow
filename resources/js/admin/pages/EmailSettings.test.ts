import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import EmailSettings from './EmailSettings.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    post,
  }),
}))

it('previews templates and queues a staff test send', async () => {
  const wrapper = mount(EmailSettings, {
    props: {
      sender: { email: 'store@example.test', name: 'Example Supply' },
      mailerReady: true,
      exactlyOnce: false,
      disclaimer:
        'Transactional delivery is at-least-once: a crash after the transport accepts a message can send a duplicate. This store does not promise exactly-once SMTP.',
      templates: [
        {
          key: 'order-receipt',
          label: 'Order receipt',
          policyClass: 'transactional',
          purpose: null,
        },
        {
          key: 'newsletter',
          label: 'Newsletter',
          policyClass: 'marketing',
          purpose: 'newsletter',
        },
      ],
      preview: { template: 'order-receipt', html: '<p>Example preview</p>' },
      tested: false,
    },
  })
  expect(wrapper.text()).toContain('does not promise exactly-once SMTP')
  expect(wrapper.text()).toContain('Exactly-once SMTP is not claimed')
  expect(wrapper.find('a[href="/admin/email?template=newsletter"]').exists()).toBe(true)
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('does not execute untrusted sender names', () => {
  const wrapper = mount(EmailSettings, {
    props: {
      sender: { email: 'store@example.test', name: '<script>alert(1)</script>' },
      mailerReady: false,
      exactlyOnce: false,
      disclaimer: 'Transactional delivery is at-least-once.',
      templates: [],
      preview: { template: 'order-receipt', html: '<p>Example preview</p>' },
      tested: false,
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
})
