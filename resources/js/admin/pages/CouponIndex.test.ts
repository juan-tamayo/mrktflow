import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CouponIndex from './CouponIndex.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('lists coupons and only offers create when pricing write is allowed', async () => {
  const wrapper = mount(CouponIndex, {
    props: {
      canWrite: true,
      coupons: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          code: 'SAVE10',
          name: 'Studio welcome',
          status: 'active',
          mode: 'percentage',
          revision: 1,
        },
      ],
    },
  })
  expect(wrapper.find('a[href="/admin/coupons/create"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('Studio welcome')
  expect(wrapper.text()).toContain('SAVE10')
  await wrapper.setProps({ canWrite: false, coupons: [] })
  expect(wrapper.find('a[href="/admin/coupons/create"]').exists()).toBe(false)
  expect(wrapper.text()).toContain('No coupons yet.')
})
