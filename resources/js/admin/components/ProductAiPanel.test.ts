import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ProductAiPanel from './ProductAiPanel.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    post,
  }),
}))

const review = {
  runId: '11111111-1111-4111-8111-111111111111',
  draftId: '22222222-2222-4222-8222-222222222222',
  status: 'candidate',
  operation: 'suggest_product_schema',
  canApply: false,
  expectedVersion: 1,
  candidate: {
    schemaVersion: '1.0',
    kind: 'product_schema',
    key: 'camera',
    attributes: [{ key: 'sensor.megapixels', type: 'integer' }],
  },
  base: { title: 'Field Camera', description: 'A compact field camera for the workbench.' },
  usage: { input_tokens: 30, output_tokens: 60, total_tokens: 90, known: true },
  warnings: [
    'Price and stock stay merchant-authored core fields. Review semantic keys before applying.',
  ],
  conflicts: [
    {
      key: 'sensor.megapixels',
      message: 'This attribute key already has a different type or comparison meaning.',
    },
  ],
  migration: {
    affected_products: 1,
    can_apply: false,
    compatible: false,
    invalid_values: [],
    comparison_changes: [],
    incompatible_fields: [
      {
        key: 'sensor.megapixels',
        message: 'This attribute key already has a different type or comparison meaning.',
      },
    ],
  },
}

it('shows semantic-key conflicts and keeps apply disabled', () => {
  const wrapper = mount(ProductAiPanel, {
    props: {
      productId: '33333333-3333-4333-8333-333333333333',
      review,
    },
  })
  expect(wrapper.text()).toContain('sensor.megapixels')
  expect(wrapper.text()).toContain('Price and stock stay merchant-authored')
  expect(wrapper.get('button.primary-button').attributes('disabled')).toBeDefined()
})
