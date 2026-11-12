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
      canReadOrders: true,
      canPreviewTheme: true,
      canManageContent: true,
      canConfigureAi: true,
      canUseAi: true,
      canManageEmail: true,
      canReadReports: true,
      canExport: true,
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
  expect(
    wrapper
      .get('.tool-links')
      .findAll('.tool-link')
      .map((link) => link.attributes('href')),
  ).toEqual(['/admin/coupons', '/admin/taxes', '/admin/pricing-preview'])
  expect(wrapper.find('a[href="/admin/shipping"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/content"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/content/import"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/themes"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/orders"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/ai"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/ai/studio"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/email"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/notifications"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/reports"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/exports"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/staff"]').exists()).toBe(true)
  expect(wrapper.find('a[href="/admin/theme-preview"]').exists()).toBe(true)
  await wrapper.setProps({
    canManageStaff: false,
    canManageCatalog: false,
    canManagePricing: false,
    canManageShipping: false,
    canReadOrders: false,
    canPreviewTheme: false,
    canManageContent: false,
    canConfigureAi: false,
    canUseAi: false,
    canManageEmail: false,
    canReadReports: false,
    canExport: false,
  })
  expect(wrapper.find('a[href="/admin/products"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/categories"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/schemas"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/coupons"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/taxes"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/pricing-preview"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/shipping"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/content"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/content/import"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/themes"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/orders"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/ai"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/ai/studio"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/email"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/notifications"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/reports"]').exists()).toBe(false)
  expect(wrapper.find('a[href="/admin/exports"]').exists()).toBe(false)
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
          '/admin/content',
          '/admin/content/import',
          '/admin/themes',
          '/admin/orders',
          '/admin/ai',
          '/admin/ai/studio',
          '/admin/email',
          '/admin/notifications',
          '/admin/reports',
          '/admin/exports',
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
      canReadOrders: false,
      canPreviewTheme: false,
      canManageContent: false,
      canConfigureAi: false,
      canUseAi: false,
      canManageEmail: false,
      canReadReports: false,
      canExport: false,
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
})
