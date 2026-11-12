import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import Exports from './Exports.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

it('offers formula-safe private datasets and an expiring download', () => {
  const wrapper = mount(Exports, {
    props: {
      retentionHours: 24,
      queued: true,
      jobs: [
        {
          id: '11111111-1111-4111-8111-111111111111',
          dataset: 'products',
          status: 'ready',
          row_count: 2,
          expires_at: '2026-10-17 14:00:00',
          download_url: '/admin/exports/11111111-1111-4111-8111-111111111111/download?token=abc',
        },
      ],
    },
  })
  expect(wrapper.get('h1').text()).toContain('Private exports')
  expect(wrapper.get('[role="note"]').text()).toContain('24 hours')
  expect(wrapper.get('[role="note"]').text()).toContain('formula')
  expect(wrapper.get('[role="status"]').text()).toContain('Export queued')
  expect(wrapper.get('form').attributes('action')).toBe('/admin/exports')
  expect(wrapper.get('form').attributes('method')).toBe('post')
  expect(wrapper.findAll('option').map((option) => option.attributes('value'))).toEqual([
    'customers',
    'orders',
    'products',
  ])
  expect(
    wrapper
      .find('a[href="/admin/exports/11111111-1111-4111-8111-111111111111/download?token=abc"]')
      .exists(),
  ).toBe(true)
})

it('hides downloads without a private token and does not execute untrusted copy', () => {
  const wrapper = mount(Exports, {
    props: {
      retentionHours: 24,
      queued: false,
      jobs: [
        {
          id: '22222222-2222-4222-8222-222222222222',
          dataset: '<script>alert(1)</script>',
          status: 'pending',
          row_count: 0,
          expires_at: '2026-10-17 14:00:00',
          download_url: null,
        },
      ],
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
  expect(wrapper.find('a[href*="/download?token="]').exists()).toBe(false)
  expect(wrapper.find('[role="status"]').exists()).toBe(false)
})
