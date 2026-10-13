import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CouponEdit from './CouponEdit.vue'

const post = vi.fn()
const patch = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    post,
    patch,
  }),
}))

it('creates a coupon through the editor form', async () => {
  const wrapper = mount(CouponEdit, {
    props: { coupon: null, products: [], categories: [], saved: false },
  })
  await wrapper.get('#coupon-name').setValue('Studio welcome')
  await wrapper.get('#coupon-code').setValue('SAVE10')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('does not execute untrusted coupon names', () => {
  const wrapper = mount(CouponEdit, {
    props: {
      saved: false,
      products: [],
      categories: [],
      coupon: {
        id: '11111111-1111-4111-8111-111111111111',
        code: 'SAVE10',
        name: '<script>alert(1)</script>',
        mode: 'percentage',
        amount: null,
        percent_numerator: 10,
        percent_denominator: 100,
        min_eligible_subtotal: 0,
        starts_at: null,
        ends_at: null,
        max_redemptions: 1,
        max_redemptions_per_customer: 1,
        status: 'active',
        version: 1,
        product_ids: [],
        category_ids: [],
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect((wrapper.get('#coupon-name').element as HTMLInputElement).value).toBe(
    '<script>alert(1)</script>',
  )
})
