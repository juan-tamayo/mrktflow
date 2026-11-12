import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import ContentNavigation from './ContentNavigation.vue'

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

const empty = {
  pages: [],
  articles: [],
  menus: [],
  pageSaved: false,
  articleSaved: false,
  menuSaved: false,
}

it('creates a page through the navigation form', async () => {
  const wrapper = mount(ContentNavigation, { props: { ...empty, canWrite: true } })
  await wrapper.get('#page-title').setValue('About the studio')
  await wrapper.get('#page-slug').setValue('about')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('hides write forms when staff cannot write and does not execute untrusted titles', () => {
  const wrapper = mount(ContentNavigation, {
    props: {
      ...empty,
      canWrite: false,
      pages: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          title: '<script>alert(1)</script>',
          slug: 'about',
          path: 'pages/about',
          status: 'draft',
          version: 1,
        },
      ],
    },
  })
  expect(wrapper.find('#page-title').exists()).toBe(false)
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
})
