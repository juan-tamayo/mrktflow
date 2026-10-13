import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import StockEditor from './StockEditor.vue'

const patch = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    patch,
  }),
}))

it('saves tracked stock without executing variant labels', async () => {
  const wrapper = mount(StockEditor, {
    props: {
      productId: '11111111-1111-4111-8111-111111111111',
      variants: [
        {
          id: '22222222-2222-4222-8222-222222222222',
          sku: 'LAMP-<script>',
          tracks_inventory: true,
          on_hand: 2,
          reserved: 1,
          available_quantity: 1,
          low_stock_threshold: 2,
          low_stock: true,
        },
      ],
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('LAMP-<script>')
  expect(wrapper.text()).toContain('Low stock')
  await wrapper.get('form').trigger('submit')
  expect(patch).toHaveBeenCalled()
})
