import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import TwoFactor from './TwoFactor.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  usePage: () => ({ props: { errors: {} } }),
}))

it('shows the enrollment secret without interpreting markup', () => {
  const wrapper = mount(TwoFactor, {
    props: {
      mode: 'enroll',
      secret: '<script>alert(1)</script>',
      uri: 'otpauth://totp/Example:owner@example.test',
      issuer: 'Example Supply',
      label: 'owner@example.test',
      recoveryCodes: [],
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.get('#two-factor-secret').element).toHaveProperty(
    'value',
    '<script>alert(1)</script>',
  )
  expect(wrapper.text()).toContain('Confirm authenticator')
  expect(wrapper.get('form.setup-form').attributes('method')).toBe('post')
  expect(wrapper.get('form.setup-form').attributes('action')).toBe('/admin/two-factor')
})

it('posts the challenge as a native form so Verify completes the staff session', () => {
  const wrapper = mount(TwoFactor, {
    props: {
      mode: 'challenge',
      secret: null,
      uri: null,
      issuer: null,
      label: null,
      recoveryCodes: [],
    },
  })
  expect(wrapper.get('form.setup-unlock').attributes('method')).toBe('post')
  expect(wrapper.get('form.setup-unlock').attributes('action')).toBe('/admin/two-factor/challenge')
  expect(wrapper.get('#two-factor-challenge').attributes('name')).toBe('code')
  expect(wrapper.get('button.primary-button').text()).toBe('Verify')
})

it('lists recovery codes once after enrollment', () => {
  const wrapper = mount(TwoFactor, {
    props: {
      mode: 'codes',
      secret: null,
      uri: null,
      issuer: null,
      label: null,
      recoveryCodes: ['abcd-ef01', '2345-6789'],
    },
  })
  expect(wrapper.findAll('.recovery-code').map((item) => item.text())).toEqual([
    'abcd-ef01',
    '2345-6789',
  ])
  expect(wrapper.get('a.primary-button[href="/admin"]').text()).toContain('Continue to workspace')
})
