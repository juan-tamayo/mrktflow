import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import CampaignEdit from './CampaignEdit.vue'

const post = vi.fn()
const patch = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (data: unknown) => ({
    ...((data as object) ?? {}),
    errors: {},
    processing: false,
    post,
    patch,
  }),
}))

const emptyProps = {
  categories: [] as { id: string; title: string; path: string }[],
  canWrite: true,
  canActivate: false,
  canSend: false,
  saved: false,
  preview: null,
  schedule: null,
}

it('creates a campaign through the editor form', async () => {
  const wrapper = mount(CampaignEdit, {
    props: { ...emptyProps, campaign: null },
  })
  await wrapper.get('#campaign-name').setValue('November studio drop')
  await wrapper.get('#campaign-subject').setValue('New lamps')
  await wrapper.get('form').trigger('submit')
  expect(post).toHaveBeenCalled()
})

it('does not execute untrusted campaign copy', () => {
  const wrapper = mount(CampaignEdit, {
    props: {
      ...emptyProps,
      campaign: {
        id: '11111111-1111-4111-8111-111111111111',
        name: '<script>alert(1)</script>',
        status: 'draft',
        version: 1,
        subject: '<script>alert(1)</script>',
        body_text: '<b>no html</b>',
        purpose: 'newsletter',
        audience: {
          customer_status: 'any',
          category_ids: [],
          purchased_after: null,
          purchased_before: null,
          previous_campaign_id: null,
          previous_campaign_mode: 'any',
        },
        schedule: { send_at: '2026-11-13T12:00:00Z' },
        caps: { max_recipients: 10, per_contact_24h: 2, per_contact_7d: 3 },
        offer_reference: null,
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect((wrapper.get('#campaign-name').element as HTMLInputElement).value).toBe(
    '<script>alert(1)</script>',
  )
})

it('hides draft controls from send-only staff', () => {
  const wrapper = mount(CampaignEdit, {
    props: {
      ...emptyProps,
      canWrite: false,
      canActivate: false,
      canSend: true,
      campaign: {
        id: '11111111-1111-4111-8111-111111111111',
        name: 'November studio drop',
        status: 'scheduled',
        version: 2,
        subject: 'New lamps',
        body_text: 'Verified subscribers only.',
        purpose: 'newsletter',
        audience: {
          customer_status: 'any',
          category_ids: [],
          purchased_after: null,
          purchased_before: null,
          previous_campaign_id: null,
          previous_campaign_mode: 'any',
        },
        schedule: { send_at: '2026-11-13T12:00:00Z' },
        caps: { max_recipients: 10, per_contact_24h: 2, per_contact_7d: 3 },
        offer_reference: null,
      },
    },
  })
  expect(wrapper.find('#campaign-name').exists()).toBe(false)
  expect(wrapper.text()).toContain('Inspect send readiness')
})
