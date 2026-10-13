import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import Taxes from './Taxes.vue'

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
  price_mode: 'exclusive',
  shipping_tax_class_key: 'standard',
  version: null as number | null,
  classes: [{ key: 'standard', name: 'Standard' }],
  rules: [],
}

it('saves tax rules through the editor form', async () => {
  const wrapper = mount(Taxes, {
    props: {
      rules: emptyRules,
      countries: [{ code: 'US', label: 'United States' }],
      canWrite: true,
      saved: false,
      preview: null,
    },
  })
  await wrapper.get('#rule-name-0').setValue('United States standard')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('does not execute untrusted tax rule names', () => {
  const wrapper = mount(Taxes, {
    props: {
      canWrite: true,
      saved: false,
      preview: null,
      countries: [{ code: 'US', label: 'United States' }],
      rules: {
        price_mode: 'exclusive',
        shipping_tax_class_key: 'standard',
        version: 1,
        classes: [{ key: 'standard', name: 'Standard' }],
        rules: [
          {
            name: '<script>alert(1)</script>',
            class_key: 'standard',
            status: 'active',
            rate_numerator: 10,
            rate_denominator: 100,
            match: { country_code: 'US', region_code: null, postal_pattern: null },
          },
        ],
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect((wrapper.get('#rule-name-0').element as HTMLInputElement).value).toBe(
    '<script>alert(1)</script>',
  )
})
