import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CouponCampaignIndex from './CouponCampaignIndex.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('lists coupon campaigns only when pricing write is allowed', async () => {
  const wrapper = mount(CouponCampaignIndex, {
    props: {
      canWrite: true,
      campaigns: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          name: 'Studio welcome offer',
          kind: 'batch',
          status: 'active',
          revision: 1,
        },
      ],
    },
  })
  expect(wrapper.find('a[href="/admin/coupon-campaigns/create"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('Studio welcome offer')
  await wrapper.setProps({ canWrite: false, campaigns: [] })
  expect(wrapper.find('a[href="/admin/coupon-campaigns/create"]').exists()).toBe(false)
  expect(wrapper.text()).toContain('No coupon campaigns yet.')
})
