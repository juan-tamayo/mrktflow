import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import PublishPanel from './PublishPanel.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    processing: false,
    transform: (callback: (data: unknown) => unknown) => {
      callback({ version: 2, revision: 1 })
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
      publishedRevision: 2,
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
  expect(
    wrapper
      .find('button[aria-label="Restore prior published revision of page About the studio"]')
      .exists(),
  ).toBe(true)
})

it('retargets the preview link when the draft page changes', async () => {
  const wrapper = mount(PublishPanel, {
    props: {
      kind: 'section_draft',
      page: 'home',
      slotName: 'content',
      version: 1,
      publishedRevision: 0,
      label: 'home content sections',
      canPublish: true,
    },
  })
  expect(wrapper.get('a').text()).toBe('Preview home content sections')
  expect(wrapper.get('a').attributes('href')).toBe('/admin/preview/sections?page=home&slot=content')
  await wrapper.setProps({
    page: 'search',
    slotName: 'content',
    label: 'search content sections',
  })
  expect(wrapper.get('a[aria-label="Preview search content sections"]').text()).toBe(
    'Preview search content sections',
  )
  expect(wrapper.get('a').attributes('href')).toBe(
    '/admin/preview/sections?page=search&slot=content',
  )
  expect(wrapper.get('button[aria-label="Publish search content sections"]').text()).toBe(
    'Publish search content sections',
  )
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
