import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ShippingRules from './ShippingRules.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    post,
  }),
}))

const emptyRules = {
  origin: null,
  classes: [{ key: 'general', name: 'General' }],
  zones: [],
}

it('saves shipping rules through the editor form', async () => {
  const wrapper = mount(ShippingRules, {
    props: {
      rules: emptyRules,
      countries: [{ code: 'US', label: 'United States' }],
      canWrite: true,
      saved: false,
      preview: null,
    },
  })
  await wrapper.get('#origin-street').setValue('100 Warehouse Ave')
  await wrapper.get('#origin-city').setValue('Los Angeles')
  await wrapper.get('#origin-postal').setValue('90001')
  await wrapper.get('#zone-name-0').setValue('United States')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('does not execute untrusted origin city names', () => {
  const wrapper = mount(ShippingRules, {
    props: {
      canWrite: true,
      saved: false,
      preview: null,
      countries: [{ code: 'US', label: 'United States' }],
      rules: {
        origin: {
          country_code: 'US',
          region_code: 'CA',
          postal_code: '90001',
          city: '<script>alert(1)</script>',
          street: '100 Warehouse Ave',
          version: 1,
        },
        classes: [{ key: 'general', name: 'General' }],
        zones: [],
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect((wrapper.get('#origin-city').element as HTMLInputElement).value).toBe(
    '<script>alert(1)</script>',
  )
})
