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
      canManageCatalog: true,
      canManagePricing: true,
      canManageShipping: true,
      canPreviewTheme: true,
    },
  })
  expect(wrapper.get('section[aria-labelledby="launch-heading"]').text()).toContain(
    'Storefront closed',
  )
  expect(wrapper.find('a[href="/admin/products"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/categories"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/schemas"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/coupons"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/taxes"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/pricing-preview"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/shipping"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/staff"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/theme-preview"]').exists()).toBe(true)
  await wrapper.setProps({
    canManageStaff: false,
    canManageCatalog: false,
    canManagePricing: false,
    canManageShipping: false,
    canPreviewTheme: false,
  })
  expect(wrapper.find('a[href="/admin/products"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/categories"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/schemas"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/coupons"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/taxes"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/pricing-preview"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/shipping"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/staff"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/theme-preview"]').exists()).toBe(false)
  expect(
    wrapper
      .findAll('a')
      .every((link) =>
        [
          '#workspace-main',
          '/admin',
          '/admin/products',
          '/admin/categories',
          '/admin/schemas',
          '/admin/coupons',
          '/admin/taxes',
          '/admin/pricing-preview',
          '/admin/shipping',
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
      canManageCatalog: false,
      canManagePricing: false,
      canManageShipping: false,
      canPreviewTheme: false,
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
})
