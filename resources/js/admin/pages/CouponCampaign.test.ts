import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CouponCampaign from './CouponCampaign.vue'

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
    transform: (callback: () => unknown) => ({ post: () => post(callback()) }),
  }),
}))

it('creates a coupon campaign through the editor form', async () => {
  const wrapper = mount(CouponCampaign, {
    props: { campaign: null, saved: false },
  })
  await wrapper.get('#campaign-name').setValue('Studio welcome offer')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('requests a unique batch for a saved campaign', async () => {
  post.mockClear()
  const wrapper = mount(CouponCampaign, {
    props: {
      saved: false,
      campaign: {
        id: '11111111-1111-4111-8111-111111111111',
        name: '<script>alert(1)</script>',
        kind: 'batch',
        status: 'active',
        version: 1,
        mode: 'percentage',
        amount: null,
        percent_numerator: 10,
        percent_denominator: 100,
        min_eligible_subtotal: 0,
        starts_at: null,
        ends_at: null,
        max_redemptions: 1,
        max_redemptions_per_customer: 1,
        product_ids: [],
        category_ids: [],
        allows_stacking: false,
        exclude_sale_priced: false,
        discount_budget: null,
        max_discount_amount: null,
        first_customer_only: false,
        grants: [],
        batches: [],
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect((wrapper.get('#campaign-name').element as HTMLInputElement).value).toBe(
    '<script>alert(1)</script>',
  )
  await wrapper.get('#batch-count').setValue(3)
  await wrapper.get('#batch-intent').setValue('22222222-2222-4222-8222-222222222222')
  const forms = wrapper.findAll('form')
  const batchForm = forms[1]
  if (batchForm === undefined) {
    throw new Error('Missing unique-batch form')
  }
  await batchForm.trigger('submit')
  expect(post).toHaveBeenCalled()
})
