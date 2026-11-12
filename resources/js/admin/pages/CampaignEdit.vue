<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'CampaignEdit' })

type Category = { id: string; title: string; path: string }
type Audience = {
  customer_status: string
  category_ids: string[]
  purchased_after: string | null
  purchased_before: string | null
  previous_campaign_id: string | null
  previous_campaign_mode: string
}
type Caps = { max_recipients: number; per_contact_24h: number; per_contact_7d: number }
type Preview = {
  eligible: number
  would_send: number
  capped: number
  excluded: { unverified: number; no_grant: number; suppressed: number; filter: number }
} | null
type Schedule = {
  status: string
  paused: boolean
  due: boolean
  dispatched: boolean
  next_send_at: string | null
  would_send: number
  capped: number
  reason: string | null
} | null
const props = defineProps<{
  campaign: {
    id: string
    name: string
    status: string
    version: number
    subject: string
    body_text: string
    purpose: string
    audience: Audience
    schedule: { send_at: string | null }
    caps: Caps
    offer_reference: string | null
  } | null
  categories: Category[]
  canWrite: boolean
  canActivate: boolean
  canSend: boolean
  saved: boolean
  preview: Preview
  schedule: Schedule
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  campaign: {
    name: props.campaign?.name ?? '',
    subject: props.campaign?.subject ?? '',
    body_text: props.campaign?.body_text ?? '',
    purpose: props.campaign?.purpose ?? 'newsletter',
    audience: {
      customer_status: props.campaign?.audience.customer_status ?? 'any',
      category_ids: [...(props.campaign?.audience.category_ids ?? [])],
      purchased_after: props.campaign?.audience.purchased_after ?? '',
      purchased_before: props.campaign?.audience.purchased_before ?? '',
      previous_campaign_id: props.campaign?.audience.previous_campaign_id ?? '',
      previous_campaign_mode: props.campaign?.audience.previous_campaign_mode ?? 'any',
    },
    schedule: { send_at: props.campaign?.schedule.send_at ?? '' },
    caps: {
      max_recipients: props.campaign?.caps.max_recipients ?? 1000,
      per_contact_24h: props.campaign?.caps.per_contact_24h ?? 2,
      per_contact_7d: props.campaign?.caps.per_contact_7d ?? 3,
    },
    offer_reference: props.campaign?.offer_reference ?? '',
    version: props.campaign?.version,
  },
})
const activateForm = useForm({ expected_version: props.campaign?.version })
const pauseForm = useForm({ expected_version: props.campaign?.version })
const sendForm = useForm({})
function toggleCategory(id: string) {
  const current = form.campaign.audience.category_ids
  if (current.includes(id)) {
    form.campaign.audience.category_ids = current.filter((value) => value !== id)
    return
  }
  form.campaign.audience.category_ids = [...current, id]
}
function submit() {
  const options = {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  }
  if (props.campaign === null) {
    form.post('/admin/marketing/campaigns', options)
    return
  }
  form.patch(`/admin/marketing/campaigns/${props.campaign.id}`, options)
}
function previewAudience() {
  if (props.campaign === null) {
    return
  }
  form.post(`/admin/marketing/campaigns/${props.campaign.id}/preview`, { preserveScroll: true })
}
function activate() {
  if (props.campaign === null) {
    return
  }
  activateForm.expected_version = props.campaign.version
  activateForm.post(`/admin/marketing/campaigns/${props.campaign.id}/activations`, {
    preserveScroll: true,
  })
}
function pause() {
  if (props.campaign === null) {
    return
  }
  pauseForm.expected_version = props.campaign.version
  pauseForm.post(`/admin/marketing/campaigns/${props.campaign.id}/pause`, { preserveScroll: true })
}
function send() {
  if (props.campaign === null) {
    return
  }
  sendForm.post(`/admin/marketing/campaigns/${props.campaign.id}/sends`, { preserveScroll: true })
}
</script>

<template>
  <Head :title="campaign ? 'Edit campaign' : 'Create a campaign'" />
  <a class="skip-link" href="#campaign-main">Skip to campaign</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/marketing/campaigns" class="text-link"
        >Campaigns</a
      >
    </header>
    <main id="campaign-main" class="setup-main">
      <p class="eyebrow">Verified opt-in audiences</p>
      <h1>{{ campaign ? 'Edit campaign' : 'Create a campaign' }}<span class="accent">.</span></h1>
      <p class="lede">
        Saves write a new version. Activation snapshots the current audience and schedule. Send is a
        separate permission and does not dispatch live mail in this workspace.
      </p>
      <p v-if="saved" role="status" class="success-notice">Campaign saved.</p>
      <p v-if="campaign" class="workspace-note">Status: {{ campaign.status }}.</p>
      <form v-if="canWrite" class="setup-form" @submit.prevent="submit">
        <div
          v-if="Object.keys(form.errors).length"
          ref="errors"
          role="alert"
          tabindex="-1"
          class="error-summary"
        >
          <p v-for="(message, field) in form.errors" :key="field">{{ message }}</p>
        </div>
        <div class="setup-field">
          <label for="campaign-name">Name</label>
          <input
            id="campaign-name"
            v-model="form.campaign.name"
            name="name"
            maxlength="120"
            required
          />
        </div>
        <div class="setup-field">
          <label for="campaign-subject">Subject</label>
          <input
            id="campaign-subject"
            v-model="form.campaign.subject"
            name="subject"
            maxlength="180"
            required
          />
        </div>
        <div class="setup-field">
          <label for="campaign-body">Plain-text body</label>
          <textarea
            id="campaign-body"
            v-model="form.campaign.body_text"
            name="body_text"
            maxlength="8000"
            rows="6"
            required
          />
        </div>
        <div class="setup-field">
          <label for="campaign-status-filter">Customer status</label>
          <select
            id="campaign-status-filter"
            v-model="form.campaign.audience.customer_status"
            name="customer_status"
          >
            <option value="any">Any verified contact</option>
            <option value="customer">Customer accounts</option>
            <option value="guest">Guest contacts</option>
            <option value="new_customer">New customers</option>
          </select>
        </div>
        <fieldset class="setup-field">
          <legend>Category interest</legend>
          <p v-if="categories.length === 0">No categories yet. Empty means no category filter.</p>
          <label v-for="category in categories" :key="category.id">
            <input
              type="checkbox"
              :value="category.id"
              :checked="form.campaign.audience.category_ids.includes(category.id)"
              @change="toggleCategory(category.id)"
            />
            {{ category.title }}
          </label>
        </fieldset>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="campaign-purchased-after">Purchased after (UTC)</label>
            <input
              id="campaign-purchased-after"
              v-model="form.campaign.audience.purchased_after"
              name="purchased_after"
            />
          </div>
          <div class="setup-field">
            <label for="campaign-purchased-before">Purchased before (UTC)</label>
            <input
              id="campaign-purchased-before"
              v-model="form.campaign.audience.purchased_before"
              name="purchased_before"
            />
          </div>
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="campaign-send-at">Send at (UTC)</label>
            <input
              id="campaign-send-at"
              v-model="form.campaign.schedule.send_at"
              name="send_at"
              required
            />
          </div>
          <div class="setup-field">
            <label for="campaign-max">Recipient cap</label>
            <input
              id="campaign-max"
              v-model.number="form.campaign.caps.max_recipients"
              name="max_recipients"
              type="number"
              min="1"
              required
            />
          </div>
        </div>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Saving…' : 'Save campaign' }}
        </button>
      </form>
      <p v-else class="workspace-note">Editing requires the marketing draft permission.</p>
      <section v-if="campaign" class="workspace-note" aria-labelledby="campaign-preview-heading">
        <h2 id="campaign-preview-heading">Audience and schedule</h2>
        <p v-if="preview">
          Eligible {{ preview.eligible }}. Would send {{ preview.would_send }}. Capped
          {{ preview.capped }}. Excluded unverified {{ preview.excluded.unverified }}, no grant
          {{ preview.excluded.no_grant }}, suppressed {{ preview.excluded.suppressed }}, filter
          {{ preview.excluded.filter }}.
        </p>
        <p v-if="schedule">
          Next send {{ schedule.next_send_at ?? 'paused' }}. Due: {{ schedule.due ? 'yes' : 'no' }}.
          Live dispatch is not enabled.
        </p>
        <p v-if="canWrite">
          <button type="button" class="quiet-button" @click="previewAudience">
            Preview audience
          </button>
        </p>
        <p v-if="canActivate && campaign.status !== 'paused'">
          <button type="button" class="quiet-button" @click="activate">Activate schedule</button>
        </p>
        <p v-if="canActivate && campaign.status === 'scheduled'">
          <button type="button" class="quiet-button" @click="pause">Pause schedule</button>
        </p>
        <p v-if="canSend">
          <button type="button" class="quiet-button" @click="send">Inspect send readiness</button>
        </p>
      </section>
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/marketing/campaigns">Return to campaigns</a>
    </footer>
  </div>
</template>
