import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { SectionDefinition } from '../section-editor'
import ContentEditor from './ContentEditor.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { name: 'Head', render: () => null },
}))

const definitions: SectionDefinition[] = [
  {
    type: 'hero',
    version: 1,
    label: 'Hero',
    maxPerPage: 3,
    binding: null,
    settings: [
      { key: 'heading', type: 'text', required: true, maxLength: 200 },
      {
        key: 'alignment',
        type: 'enum',
        required: false,
        options: ['left', 'center'],
        default: 'left',
      },
    ],
  },
  {
    type: 'rich-text',
    version: 1,
    label: 'Rich text',
    maxPerPage: 20,
    binding: null,
    settings: [{ key: 'text', type: 'text', required: true, maxLength: 5000 }],
  },
]

type FetchCall = { url: string; method: string; body: Record<string, unknown> }

const calls: FetchCall[] = []
let saveAttempts = 0

function jsonResponse(status: number, data: unknown) {
  return { status, json: () => Promise.resolve(data) }
}

beforeEach(() => {
  calls.length = 0
  saveAttempts = 0
  document.cookie = 'XSRF-TOKEN=test-token'
  vi.stubGlobal(
    'fetch',
    vi.fn((url: string, options: { method: string; body?: string }) => {
      const body = options.body ? (JSON.parse(options.body) as Record<string, unknown>) : {}
      calls.push({ url, method: options.method, body })
      if (url === '/admin/content-editor/draft' && options.method === 'PUT') {
        saveAttempts += 1
        if (saveAttempts === 1) {
          return Promise.resolve(
            jsonResponse(422, {
              message: 'The sections could not be saved.',
              errors: {
                document: [],
                sections: {
                  hero: ["Section 'hero' (hero) is missing the required setting 'heading'."],
                },
              },
            }),
          )
        }
        return Promise.resolve(
          jsonResponse(200, {
            page: 'home',
            slot: 'content',
            version: 1,
            sections: body.sections,
          }),
        )
      }
      if (url === '/admin/content-editor/preview') {
        return Promise.resolve(
          jsonResponse(200, { html: '<section data-section-type="hero">Rendered</section>' }),
        )
      }
      return Promise.resolve(jsonResponse(404, {}))
    }),
  )
})

function mountEditor() {
  return mount(ContentEditor, {
    attachTo: document.body,
    props: {
      definitions,
      targets: [{ page: 'home', slots: ['announcement', 'content'] }],
      initial: { page: 'home', slot: 'content', version: 0, sections: [] },
      canWrite: true,
    },
  })
}

describe('ContentEditor', () => {
  it('adds a block, recovers from a validation failure without losing work, then saves', async () => {
    const wrapper = mountEditor()

    await wrapper.get('[data-action="add-type"]').setValue('hero')
    await wrapper.get('[data-action="add"]').trigger('click')
    expect(wrapper.find('[data-section-id="hero"]').exists()).toBe(true)
    expect(wrapper.get('[role="status"]').text()).toContain('Hero added as section hero.')

    await wrapper.get('[data-action="save-draft"]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('1 problems to fix')
    expect(wrapper.text()).toContain("missing the required setting 'heading'")
    // The working copy survived the rejection.
    expect(wrapper.find('[data-section-id="hero"]').exists()).toBe(true)
    expect(wrapper.get('[role="status"]').text()).toContain(
      'Your changes are still here — fix them and save again.',
    )

    await wrapper.get('#section-hero-heading').setValue('Find your camera')
    await wrapper.get('[data-action="save-draft"]').trigger('click')
    await flushPromises()

    const saves = calls.filter((call) => call.method === 'PUT')
    expect(saves).toHaveLength(2)
    const secondPayload = saves[1]?.body.sections as { settings: Record<string, unknown> }[]
    expect(secondPayload[0]?.settings.heading).toBe('Find your camera')
    expect(wrapper.get('[role="status"]').text()).toContain('Draft saved (version 1).')
    expect(wrapper.text()).toContain('Draft version 1')
    expect(wrapper.text()).not.toContain('problems to fix')
  })

  it('renders the server preview into the device frame', async () => {
    const wrapper = mountEditor()
    await wrapper.get('[data-action="refresh-preview"]').trigger('click')
    await flushPromises()
    const previewCall = calls.find((call) => call.url === '/admin/content-editor/preview')
    expect(previewCall?.method).toBe('POST')
    const frame = wrapper.get('iframe')
    expect(frame.attributes('srcdoc')).toContain('data-section-type="hero"')
    expect(wrapper.get('[role="status"]').text()).toContain('Preview updated.')
  })

  it('hides saving from read-only staff', () => {
    const wrapper = mount(ContentEditor, {
      props: {
        definitions,
        targets: [{ page: 'home', slots: ['announcement', 'content'] }],
        initial: { page: 'home', slot: 'content', version: 3, sections: [] },
        canWrite: false,
      },
    })
    expect(wrapper.find('[data-action="save-draft"]').exists()).toBe(false)
    expect(wrapper.find('[data-action="add-type"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Draft version 3')
  })
})
