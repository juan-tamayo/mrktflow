import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ProductImport from './ProductImport.vue'

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

it('shows sample columns, a mixed-validity dry-run, and an apply action', () => {
  const wrapper = mount(ProductImport, {
    props: {
      sampleColumns: ['sku', 'title', 'slug'],
      applied: null,
      preview: {
        import_id: '11111111-1111-4111-8111-111111111111',
        headers: ['SKU', 'Title'],
        mapping: { SKU: 'sku', Title: 'title' },
        unmapped: [],
        rows: [
          {
            line: 2,
            sku: 'DESK-01',
            intent: 'create',
            valid: true,
            errors: [],
            product_id: null,
          },
          {
            line: 3,
            sku: 'BAD',
            intent: 'create',
            valid: false,
            errors: ['price_amount must be a whole number of currency units.'],
            product_id: null,
          },
        ],
        valid_count: 1,
        error_count: 1,
        create_count: 1,
        update_count: 0,
      },
    },
  })
  expect(wrapper.text()).toContain('sku, title, slug')
  expect(wrapper.text()).toContain('homepage stays closed')
  expect(wrapper.text()).toContain('DESK-01')
  expect(wrapper.text()).toContain('create')
  expect(wrapper.text()).toContain('price_amount')
  expect(wrapper.text()).toContain('No catalog rows were written')
  expect(
    wrapper
      .find('a[href="/admin/products/import/11111111-1111-4111-8111-111111111111/errors.csv"]')
      .exists(),
  ).toBe(true)
  expect(wrapper.text()).toContain('Apply validated rows')
  expect(wrapper.find('a[href="/admin/products"]').exists()).toBe(true)
})
