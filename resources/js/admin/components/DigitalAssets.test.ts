import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import DigitalAssets from './DigitalAssets.vue'

const post = vi.fn()
const put = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    post,
    put,
  }),
}))

it('uploads a private file without executing labels', async () => {
  const wrapper = mount(DigitalAssets, {
    props: {
      productId: '11111111-1111-4111-8111-111111111111',
      variants: [
        {
          id: '22222222-2222-4222-8222-222222222222',
          sku: 'GUIDE-<script>',
          digital_files: [],
        },
      ],
      assets: [
        {
          id: '33333333-3333-4333-8333-333333333333',
          label: 'Guide <script>',
          versions: [
            {
              id: '44444444-4444-4444-8444-444444444444',
              version: 1,
              status: 'ready',
              bytes: 12,
              content_type: 'text/plain',
            },
          ],
        },
      ],
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('GUIDE-<script>')
  expect(wrapper.text()).toContain('Guide <script>')
  await wrapper.get('#digital-label').setValue('Lighting guide')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})
