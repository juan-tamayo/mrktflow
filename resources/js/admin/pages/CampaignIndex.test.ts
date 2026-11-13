import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CampaignIndex from './CampaignIndex.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('lists campaigns and only offers create when draft permission is allowed', async () => {
  const wrapper = mount(CampaignIndex, {
    props: {
      canWrite: true,
      campaigns: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          name: 'November studio drop',
          status: 'draft',
          revision: 1,
        },
      ],
    },
  })
  expect(wrapper.find('a[href="/admin/marketing/campaigns/create"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('November studio drop')
  await wrapper.setProps({ canWrite: false, campaigns: [] })
  expect(wrapper.find('a[href="/admin/marketing/campaigns/create"]').exists()).toBe(false)
  expect(wrapper.text()).toContain('No campaigns yet.')
})
