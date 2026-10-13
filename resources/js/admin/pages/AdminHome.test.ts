import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import AdminHome from './AdminHome.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: () => ({ processing: false, post: vi.fn() }),
}))

it('shows the closed launch status and only authorized workspace actions', async () => {
  const wrapper = mount(AdminHome, {
    props: {
      staff: { name: 'Example Owner', role: 'owner' },
      canManageStaff: true,
      canPreviewTheme: true,
    },
  })
  expect(wrapper.get('section[aria-labelledby="launch-heading"]').text()).toContain(
    'Storefront closed',
  )
  expect(wrapper.find('a[href="/admin/staff"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/theme-preview"]').exists()).toBe(true)
  await wrapper.setProps({ canManageStaff: false, canPreviewTheme: false })
  expect(wrapper.find('a[href="/admin/staff"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/theme-preview"]').exists()).toBe(false)
  expect(
    wrapper
      .findAll('a')
      .every((link) =>
        [
          '#workspace-main',
          '/admin',
          '/admin/staff',
          '/admin/two-factor',
          '/admin/theme-preview',
          '/',
        ].includes(link.attributes('href') ?? ''),
      ),
  ).toBe(true)
})

it('escapes staff names in the branded workspace', () => {
  const wrapper = mount(AdminHome, {
    props: {
      staff: { name: '<script>alert(1)</script>', role: 'content_editor' },
      canManageStaff: false,
      canPreviewTheme: false,
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
})
