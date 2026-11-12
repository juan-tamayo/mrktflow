import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import TemplatePicker from './TemplatePicker.vue'

const put = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    put,
  }),
}))

it('assigns a product layout through a uniquely labelled control', async () => {
  const wrapper = mount(TemplatePicker, {
    props: {
      subjectType: 'product',
      subjectId: '11111111-1111-4111-8111-111111111111',
      subjectLabel: 'Studio Lamp',
      templateKey: 'product.default',
      options: [
        { key: 'product.default', label: 'Default product layout' },
        { key: 'product.compact', label: 'Compact product layout' },
      ],
      canAssign: true,
      saved: false,
    },
  })
  expect(
    wrapper.get('#product-visual-template-11111111-1111-4111-8111-111111111111').element,
  ).toHaveProperty('value', 'product.default')
  expect(
    wrapper.get('label[for="product-visual-template-11111111-1111-4111-8111-111111111111"]').text(),
  ).toBe('Visual template for Studio Lamp')
  await wrapper
    .get('#product-visual-template-11111111-1111-4111-8111-111111111111')
    .setValue('product.compact')
  await wrapper.get('form').trigger('submit')
  expect(put).toHaveBeenCalled()
  expect(wrapper.get('button[aria-label="Assign visual template for Studio Lamp"]').text()).toBe(
    'Assign layout',
  )
})

it('names the assignment result separately from other status regions', () => {
  const wrapper = mount(TemplatePicker, {
    props: {
      subjectType: 'product',
      subjectId: '11111111-1111-4111-8111-111111111111',
      subjectLabel: 'Studio Lamp',
      templateKey: 'product.compact',
      options: [{ key: 'product.compact', label: 'Compact product layout' }],
      canAssign: true,
      saved: true,
    },
  })
  expect(
    wrapper.get('[role="status"][aria-label="Visual template assignment result"]').text(),
  ).toBe('Visual template assigned.')
})

it('hides the assign action when staff cannot write', () => {
  const wrapper = mount(TemplatePicker, {
    props: {
      subjectType: 'category',
      subjectId: '22222222-2222-4222-8222-222222222222',
      subjectLabel: 'Lighting',
      templateKey: 'category.default',
      options: [{ key: 'category.default', label: 'Default category layout' }],
      canAssign: false,
      saved: false,
    },
  })
  expect(wrapper.find('button').exists()).toBe(false)
  expect(
    wrapper
      .get('#category-visual-template-22222222-2222-4222-8222-222222222222')
      .attributes('disabled'),
  ).toBeDefined()
})
