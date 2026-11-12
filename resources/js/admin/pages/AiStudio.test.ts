import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, expect, it, vi } from 'vitest'
import AiStudio from './AiStudio.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

const candidate = {
  schemaVersion: '1.0',
  kind: 'page',
  title: 'Studio lighting',
  templateKey: 'page.default',
  sections: [
    {
      id: 'intro',
      type: 'hero',
      version: 1,
      settings: { heading: 'Light your studio', alignment: 'left' },
    },
    {
      id: 'copy',
      type: 'rich-text',
      version: 1,
      settings: { text: 'Choose lighting that suits your space.' },
    },
  ],
}

const studioProps = {
  runs: [],
  runId: '11111111-1111-4111-8111-111111111111',
  draftId: '22222222-2222-4222-8222-222222222222',
  status: 'candidate',
  stale: false,
  canApply: true,
  canPublish: false,
  expectedRevisionId: '33333333-3333-4333-8333-333333333333',
  currentRevisionId: '33333333-3333-4333-8333-333333333333',
  pageId: '44444444-4444-4444-8444-444444444444',
  pageVersion: 1,
  publishedRevision: 0,
  prompt: 'Draft a studio lighting guide.',
  model: 'gpt-4o-mini-2024-07-18',
  candidate,
  base: { title: 'Lighting guide', slug: 'lighting-guide', path: 'pages/lighting-guide' },
  usage: { input_tokens: 40, output_tokens: 80, total_tokens: 120, known: true },
  warnings: [
    'Price, stock and campaign facts stay merchant-authored; review product claims before publishing.',
    'Applying writes a content draft only. Publishing stays a separate permission and action.',
  ],
}

beforeEach(() => {
  window.document.cookie = 'XSRF-TOKEN=test-token'
})

it('shows usage and a diff and applies without a publish control', async () => {
  const fetchMock = vi.fn(() =>
    Promise.resolve({
      status: 200,
      json: () => Promise.resolve({ version: 2, published_revision: 0 }),
    }),
  )
  vi.stubGlobal('fetch', fetchMock)
  const wrapper = mount(AiStudio, { props: studioProps })
  expect(wrapper.text()).toContain('120')
  expect(wrapper.text()).toContain('Lighting guide')
  expect(wrapper.text()).toContain('This account cannot publish')
  expect(wrapper.find('button[aria-label="Publish page"]').exists()).toBe(false)
  expect(wrapper.text()).not.toContain('Publish page')
  await wrapper.get('#ai-draft-title').setValue('Studio lighting guide')
  await wrapper.get('button.primary-button').trigger('click')
  await flushPromises()
  expect(fetchMock).toHaveBeenCalledWith(
    `/admin/ai/studio/${studioProps.runId}/apply`,
    expect.objectContaining({
      body: expect.stringContaining(
        '"expected_revision_id":"33333333-3333-4333-8333-333333333333"',
      ),
    }),
  )
  expect(fetchMock).toHaveBeenCalledWith(
    `/admin/ai/studio/${studioProps.runId}/apply`,
    expect.objectContaining({
      body: expect.stringContaining('"title":"Studio lighting guide"'),
    }),
  )
  expect(wrapper.text()).toContain('Publishing remains a separate action')
})
