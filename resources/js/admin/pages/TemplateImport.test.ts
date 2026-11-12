import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import TemplateImport from './TemplateImport.vue'

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

it('stages a package and applies it to drafts with unique controls', () => {
  const wrapper = mount(TemplateImport, {
    props: {
      canWrite: true,
      applied: null,
      preview: {
        import_id: '11111111-1111-4111-8111-111111111111',
        documents: [{ kind: 'section_draft', page: 'home', slot: 'content', section_count: 2 }],
        media: [{ id: 'hero', path: 'media/hero.png', bytes: 120, content_type: 'image/png' }],
        aliases: { products: [], categories: ['cameras'] },
        can_apply: true,
        errors: [],
      },
    },
  })
  expect(wrapper.text()).toContain('homepage stays closed')
  expect(wrapper.text()).toContain('home content')
  expect(wrapper.get('#template-package').attributes('id')).toBe('template-package')
  expect(wrapper.get('button').text()).toContain('Stage and validate package')
  expect(wrapper.get('input[aria-label="Map category alias cameras"]').attributes('id')).toBe(
    'map-category-cameras',
  )
  expect(wrapper.get('button[aria-label="Apply template to drafts"]').text()).toBe(
    'Apply template to drafts',
  )
  expect(wrapper.get('[role="status"][aria-label="Import staging report"]').text()).toContain(
    'Ready to apply to drafts',
  )
  expect(wrapper.find('a[href="/admin/content"]').exists()).toBe(true)
})

it('names the apply result separately from the staging report', () => {
  const wrapper = mount(TemplateImport, {
    props: {
      canWrite: true,
      preview: {
        import_id: '11111111-1111-4111-8111-111111111111',
        documents: [{ kind: 'section_draft', page: 'home', slot: 'content', section_count: 1 }],
        media: [],
        aliases: { products: [], categories: [] },
        can_apply: true,
        errors: [],
      },
      applied: {
        import_id: '11111111-1111-4111-8111-111111111111',
        applied: true,
        replayed: false,
        documents: [{ page: 'home', slot: 'content', version: 1, section_count: 1 }],
      },
    },
  })
  expect(wrapper.get('[role="status"][aria-label="Import staging report"]').exists()).toBe(true)
  expect(wrapper.get('[role="status"][aria-label="Import apply result"]').text()).toContain(
    'Applied to drafts',
  )
})

it('hides write actions when staff cannot write and does not execute untrusted copy', () => {
  const wrapper = mount(TemplateImport, {
    props: {
      canWrite: false,
      applied: null,
      preview: {
        import_id: '11111111-1111-4111-8111-111111111111',
        documents: [{ kind: 'section_draft', page: 'home', slot: 'content', section_count: 1 }],
        media: [],
        aliases: { products: [], categories: [] },
        can_apply: true,
        errors: ['<script>alert(1)</script>'],
      },
    },
  })
  expect(wrapper.find('form').exists()).toBe(false)
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
})
