import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import Themes from './Themes.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    processing: false,
    post,
  }),
}))

it('activates a reviewed theme through a uniquely labelled control', async () => {
  const wrapper = mount(Themes, {
    props: {
      themes: [
        {
          theme_id: 'mrktflow/default',
          name: 'Default',
          version: '0.1.0',
          status: 'valid',
          activatable: true,
          errors: [],
        },
      ],
      activeThemeId: null,
      canActivate: true,
      activated: false,
    },
  })
  await wrapper.get('form').trigger('submit')
  expect(wrapper.get('button[aria-label="Activate theme mrktflow/default"]').text()).toBe(
    'Activate theme mrktflow/default',
  )
  expect(post).toHaveBeenCalled()
})

it('hides activation when staff cannot activate and does not execute untrusted names', () => {
  const wrapper = mount(Themes, {
    props: {
      themes: [
        {
          theme_id: 'acme/shop',
          name: '<script>alert(1)</script>',
          version: '1.0.0',
          status: 'valid',
          activatable: true,
          errors: [],
        },
      ],
      activeThemeId: 'acme/shop',
      canActivate: false,
      activated: false,
    },
  })
  expect(wrapper.find('button').exists()).toBe(false)
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
  expect(wrapper.text()).toContain('Active')
})
