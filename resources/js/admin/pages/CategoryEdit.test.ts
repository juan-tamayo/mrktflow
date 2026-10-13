import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CategoryEdit from './CategoryEdit.vue'

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

it('creates a draft through the category form', async () => {
  const wrapper = mount(CategoryEdit, { props: { category: null, parents: [], saved: false } })
  await wrapper.get('#category-title').setValue('Lighting')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('does not execute untrusted category titles', () => {
  const wrapper = mount(CategoryEdit, {
    props: {
      saved: false,
      parents: [],
      category: {
        id: '11111111-1111-4111-8111-111111111111',
        title: '<script>alert(1)</script>',
        slug: 'lighting',
        description: 'Lamps',
        status: 'draft',
        version: 1,
        parent_id: null,
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.get('#category-title').element).toHaveProperty(
    'value',
    '<script>alert(1)</script>',
  )
})
