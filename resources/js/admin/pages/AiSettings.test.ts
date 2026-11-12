import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import AiSettings from './AiSettings.vue'

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

const settings = {
  enabled: false,
  provider: 'openai',
  model: 'gpt-4o-mini-2024-07-18',
  models: ['gpt-4o-mini-2024-07-18', 'gpt-6-sol'],
  daily_token_budget: 0,
  monthly_token_budget: 0,
  has_secret: false,
  secret_hint: null as string | null,
  author_ids: [] as string[],
  version: 1,
}

it('saves owner AI configuration through the settings form', async () => {
  const wrapper = mount(AiSettings, {
    props: {
      settings,
      authors: [
        { id: '14397058-5ad8-4f45-9bbf-b87153dca0ba', name: 'Example Owner', role: 'owner' },
      ],
      saved: false,
    },
  })
  await wrapper.get('#ai-enabled').setValue(true)
  await wrapper.get('#ai-key').setValue('sk-test-mrktflow-authoring-secret')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
  expect(wrapper.text()).toContain('Manual pages, imports, checkout')
})

it('does not execute untrusted author names or expose a saved secret', () => {
  const wrapper = mount(AiSettings, {
    props: {
      saved: false,
      authors: [
        {
          id: '24397058-5ad8-4f45-9bbf-b87153dca0ba',
          name: '<script>alert(1)</script>',
          role: 'content_editor',
        },
      ],
      settings: {
        ...settings,
        enabled: true,
        has_secret: true,
        secret_hint: 'cret',
        author_ids: ['24397058-5ad8-4f45-9bbf-b87153dca0ba'],
        daily_token_budget: 1000,
        monthly_token_budget: 5000,
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
  expect(wrapper.get('#ai-key').attributes('placeholder')).toBe('Saved key ending in cret')
  expect(wrapper.html()).not.toContain('sk-test-mrktflow-authoring-secret')
})
