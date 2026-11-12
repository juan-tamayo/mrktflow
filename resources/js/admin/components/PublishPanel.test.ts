import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import PublishPanel from './PublishPanel.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    processing: false,
    transform: (callback: (data: unknown) => unknown) => {
      callback({ version: 2 })
      return { post }
    },
  }),
}))

it('publishes a page through a uniquely labelled native control', async () => {
  const wrapper = mount(PublishPanel, {
    props: {
      kind: 'page',
      subjectId: '11111111-1111-4111-8111-111111111111',
      version: 2,
      publishedRevision: 1,
      label: 'page About the studio',
      canPublish: true,
    },
  })
  expect(
    wrapper.get('a[href="/admin/preview/pages/11111111-1111-4111-8111-111111111111"]').text(),
  ).toBe('Preview page About the studio')
  await wrapper.get('form').trigger('submit')
  expect(wrapper.get('button[aria-label="Publish page About the studio"]').text()).toBe(
    'Publish page About the studio',
  )
  expect(post).toHaveBeenCalled()
})

it('hides publish when staff cannot publish and still offers preview', () => {
  const wrapper = mount(PublishPanel, {
    props: {
      kind: 'section_draft',
      page: 'home',
      slotName: 'content',
      version: 1,
      publishedRevision: 0,
      label: 'home content sections',
      canPublish: false,
    },
  })
  expect(wrapper.find('button').exists()).toBe(false)
  expect(wrapper.get('a').text()).toBe('Preview home content sections')
  expect(wrapper.get('a').attributes('href')).toBe('/admin/preview/sections?page=home&slot=content')
})
