import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import DownloadAccess from './DownloadAccess.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (fields: Record<string, unknown>) => ({
    ...fields,
    processing: false,
    post,
  }),
}))

const orderId = '11111111-1111-4111-8111-111111111111'
const entitlementId = '22222222-2222-4222-8222-222222222222'
const versionTwoId = '33333333-3333-4333-8333-333333333333'

const activeDownload = {
  id: entitlementId,
  line_id: '44444444-4444-4444-8444-444444444444',
  title: 'Studio Lighting Guide',
  sku: 'GUIDE-001',
  asset_label: 'Lighting guide',
  version_id: '55555555-5555-4555-8555-555555555555',
  version: 1,
  purchase_version: 1,
  status: 'active',
  download_limit: 5,
  downloads_used: 2,
  downloads_remaining: 3,
  available_versions: [{ id: versionTwoId, version: 2, bytes: 2048 }],
}

it('renders the pinned and purchase versions with the remaining allowance', () => {
  const wrapper = mount(DownloadAccess, {
    props: { orderId, downloads: [activeDownload] },
  })
  expect(wrapper.text()).toContain('Studio Lighting Guide')
  expect(wrapper.text()).toContain('Lighting guide')
  expect(wrapper.text()).toContain('version 1')
  expect(wrapper.text()).toContain('purchased version 1')
  expect(wrapper.text()).toContain('2 of 5 used')
  expect(wrapper.text()).toContain('version 2')
})

it('posts the asset update grant to the entitlement update URL with a reason', () => {
  post.mockClear()
  const wrapper = mount(DownloadAccess, {
    props: { orderId, downloads: [activeDownload] },
  })
  const updateForm = wrapper.findAll('form')[0]!
  expect(updateForm.find('select[name="version_id"]').exists()).toBe(true)
  expect(updateForm.find('textarea[name="reason"]').exists()).toBe(true)
  updateForm.trigger('submit')
  expect(post).toHaveBeenCalledWith(
    `/admin/orders/${orderId}/entitlements/${entitlementId}/update`,
    {
      preserveScroll: true,
    },
  )
})

it('posts the limit reset to the entitlement reset URL with a reason', () => {
  post.mockClear()
  const wrapper = mount(DownloadAccess, {
    props: { orderId, downloads: [activeDownload] },
  })
  const resetForm = wrapper.findAll('form')[1]!
  expect(resetForm.find('input[name="download_limit"]').exists()).toBe(true)
  expect(resetForm.find('textarea[name="reason"]').exists()).toBe(true)
  resetForm.trigger('submit')
  expect(post).toHaveBeenCalledWith(
    `/admin/orders/${orderId}/entitlements/${entitlementId}/reset-limit`,
    { preserveScroll: true },
  )
})

it('renders no staff forms for a revoked entitlement', () => {
  const wrapper = mount(DownloadAccess, {
    props: {
      orderId,
      downloads: [{ ...activeDownload, status: 'revoked', available_versions: [] }],
    },
  })
  expect(wrapper.text()).toContain('revoked')
  expect(wrapper.findAll('form')).toHaveLength(0)
})
