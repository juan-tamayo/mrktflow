import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import StaffLogin from './StaffLogin.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  usePage: () => ({ props: { errors: {} } }),
}))

it('posts staff sign-in as a native form so the session is stored before the next navigation', () => {
  const wrapper = mount(StaffLogin)
  expect(wrapper.get('form.setup-unlock').attributes('method')).toBe('post')
  expect(wrapper.get('form.setup-unlock').attributes('action')).toBe('/admin/login')
  expect(wrapper.get('#staff-email').attributes('name')).toBe('email')
  expect(wrapper.get('#staff-password').attributes('name')).toBe('password')
  expect(wrapper.get('button.primary-button').text()).toBe('Sign in')
})
