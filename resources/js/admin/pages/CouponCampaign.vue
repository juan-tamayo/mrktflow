<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'CouponCampaign' })

type Grant = {
  id: string
  code: string
  kind: string
  status: string
  recipient_customer_id: string | null
  expires_at: string | null
}
type Batch = {
  id: string
  purpose: string
  status: string
  requested_count: number
  generated_count: number
  source_intent_id: string
}
const props = defineProps<{
  campaign: {
    id: string
    name: string
    kind: string
    status: string
    version: number
    mode: string
    amount: number | null
    percent_numerator: number | null
    percent_denominator: number | null
    min_eligible_subtotal: number
    starts_at: string | null
    ends_at: string | null
    max_redemptions: number | null
    max_redemptions_per_customer: number | null
    product_ids: string[]
    category_ids: string[]
    grants: Grant[]
    batches: Batch[]
  } | null
  saved: boolean
}>()
const errors = ref<HTMLElement | null>(null)
function intentId(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return '11111111-1111-4111-8111-111111111111'
}
const form = useForm({
  campaign: {
    name: props.campaign?.name ?? '',
    kind: props.campaign?.kind ?? 'reusable',
    status: props.campaign?.status ?? 'active',
    mode: props.campaign?.mode ?? 'percentage',
    amount: props.campaign?.amount,
    percent_numerator: props.campaign?.percent_numerator ?? 10,
    percent_denominator: props.campaign?.percent_denominator ?? 100,
    min_eligible_subtotal: props.campaign?.min_eligible_subtotal ?? 0,
    starts_at: props.campaign?.starts_at ?? '',
    ends_at: props.campaign?.ends_at ?? '',
    max_redemptions: props.campaign?.max_redemptions,
    max_redemptions_per_customer: props.campaign?.max_redemptions_per_customer,
    version: props.campaign?.version,
  },
})
const grantForm = useForm({
  grant: {
    source_intent_id: intentId(),
    code: '',
    recipient_customer_id: '',
    expires_at: '',
  },
})
const batchForm = useForm({
  batch: {
    source_intent_id: intentId(),
    requested_count: 10,
  },
})
const importForm = useForm({
  import: {
    source_intent_id: intentId(),
    customer_ids: '',
    expires_at: '',
  },
})
const options = {
  preserveScroll: true,
  onError: async () => {
    await nextTick()
    errors.value?.focus()
  },
}
function submit() {
  if (props.campaign === null) {
    form.post('/admin/coupon-campaigns', options)
    return
  }
  form.patch(`/admin/coupon-campaigns/${props.campaign.id}`, options)
}
function issueGrant() {
  if (props.campaign === null) {
    return
  }
  grantForm.post(`/admin/coupon-campaigns/${props.campaign.id}/grants`, options)
}
function requestBatch() {
  if (props.campaign === null) {
    return
  }
  batchForm.post(`/admin/coupon-campaigns/${props.campaign.id}/batches`, options)
}
function importRecipients() {
  if (props.campaign === null) {
    return
  }
  const ids = importForm.import.customer_ids
    .split(/[\s,]+/)
    .map((value) => value.trim())
    .filter((value) => value !== '')
  importForm
    .transform(() => ({
      import: {
        source_intent_id: importForm.import.source_intent_id,
        customer_ids: ids,
        expires_at: importForm.import.expires_at,
      },
    }))
    .post(`/admin/coupon-campaigns/${props.campaign.id}/imports`, options)
}
</script>

<template>
  <Head :title="campaign ? 'Edit coupon campaign' : 'Create a coupon campaign'" />
  <a class="skip-link" href="#coupon-campaign-main">Skip to coupon campaign</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/coupon-campaigns" class="text-link"
        >Coupon campaigns</a
      >
    </header>
    <main id="coupon-campaign-main" class="setup-main">
      <p class="eyebrow">Stable issuance</p>
      <h1>
        {{ campaign ? 'Edit coupon campaign' : 'Create a coupon campaign'
        }}<span class="accent">.</span>
      </h1>
      <p class="lede">
        Pricing owns grants. Repeat the same source intent to return the same code. Recipient codes
        cannot be redeemed by another shopper. The store homepage stays closed.
      </p>
      <p v-if="saved" role="status" class="success-notice">Campaign saved.</p>
      <form class="setup-form" @submit.prevent="submit">
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
        <div class="setup-grid">
          <div class="setup-field">
            <label for="campaign-kind">Kind</label>
            <select
              id="campaign-kind"
              v-model="form.campaign.kind"
              name="kind"
              required
              :disabled="campaign !== null"
            >
              <option value="reusable">Reusable public code</option>
              <option value="batch">Unique code batch</option>
              <option value="recipient">Recipient-bound grant</option>
            </select>
          </div>
          <div class="setup-field">
            <label for="campaign-status">Status</label>
            <select id="campaign-status" v-model="form.campaign.status" name="status" required>
              <option value="draft">Draft</option>
              <option value="active">Active</option>
              <option value="paused">Paused</option>
            </select>
          </div>
        </div>
        <div class="setup-field">
          <label for="campaign-mode">Mode</label>
          <select id="campaign-mode" v-model="form.campaign.mode" name="mode" required>
            <option value="percentage">Percentage</option>
            <option value="fixed">Fixed amount</option>
            <option value="free_shipping">Free shipping</option>
          </select>
        </div>
        <div v-if="form.campaign.mode === 'fixed'" class="setup-field">
          <label for="campaign-amount">Amount in the smallest currency unit</label>
          <input
            id="campaign-amount"
            v-model.number="form.campaign.amount"
            name="amount"
            type="number"
            min="1"
            required
          />
        </div>
        <div v-if="form.campaign.mode === 'percentage'" class="setup-field">
          <label for="campaign-percent">Percent</label>
          <input
            id="campaign-percent"
            v-model.number="form.campaign.percent_numerator"
            name="percent_numerator"
            type="number"
            min="1"
            max="100"
            required
          />
        </div>
        <div class="setup-field">
          <label for="campaign-min">Minimum eligible subtotal</label>
          <input
            id="campaign-min"
            v-model.number="form.campaign.min_eligible_subtotal"
            name="min_eligible_subtotal"
            type="number"
            min="0"
            required
          />
        </div>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Saving…' : 'Save campaign' }}
        </button>
      </form>
      <form v-if="campaign?.kind === 'reusable'" class="setup-form" @submit.prevent="issueGrant">
        <h2>Issue reusable code</h2>
        <div class="setup-field">
          <label for="grant-intent">Source intent</label>
          <input id="grant-intent" v-model="grantForm.grant.source_intent_id" required />
        </div>
        <div class="setup-field">
          <label for="grant-code">Code</label>
          <input id="grant-code" v-model="grantForm.grant.code" maxlength="32" required />
        </div>
        <button type="submit" class="primary-button" :disabled="grantForm.processing">
          Issue grant
        </button>
      </form>
      <form v-if="campaign?.kind === 'batch'" class="setup-form" @submit.prevent="requestBatch">
        <h2>Generate unique batch</h2>
        <div class="setup-field">
          <label for="batch-intent">Source intent</label>
          <input id="batch-intent" v-model="batchForm.batch.source_intent_id" required />
        </div>
        <div class="setup-field">
          <label for="batch-count">Code count (1–1000)</label>
          <input
            id="batch-count"
            v-model.number="batchForm.batch.requested_count"
            type="number"
            min="1"
            max="1000"
            required
          />
        </div>
        <button type="submit" class="primary-button" :disabled="batchForm.processing">
          Generate batch
        </button>
      </form>
      <form
        v-if="campaign?.kind === 'recipient'"
        class="setup-form"
        @submit.prevent="importRecipients"
      >
        <h2>Import recipient grants</h2>
        <div class="setup-field">
          <label for="import-intent">Source intent</label>
          <input id="import-intent" v-model="importForm.import.source_intent_id" required />
        </div>
        <div class="setup-field">
          <label for="import-customers">Verified customer IDs</label>
          <textarea id="import-customers" v-model="importForm.import.customer_ids" rows="4" />
        </div>
        <div class="setup-field">
          <label for="import-expires">Expires at (UTC)</label>
          <input id="import-expires" v-model="importForm.import.expires_at" />
        </div>
        <button type="submit" class="primary-button" :disabled="importForm.processing">
          Issue recipient grants
        </button>
      </form>
      <section v-if="campaign && campaign.grants.length > 0">
        <h2>Issued grants</h2>
        <ul class="product-list">
          <li v-for="grant in campaign.grants" :key="grant.id">
            <p>{{ grant.code }} · {{ grant.kind }} · {{ grant.status }}</p>
            <p v-if="grant.recipient_customer_id">Recipient {{ grant.recipient_customer_id }}</p>
          </li>
        </ul>
      </section>
      <section v-if="campaign && campaign.batches.length > 0">
        <h2>Batches</h2>
        <ul class="product-list">
          <li v-for="batch in campaign.batches" :key="batch.id">
            <p>
              {{ batch.purpose }} · {{ batch.generated_count }}/{{ batch.requested_count }} ·
              {{ batch.status }}
            </p>
          </li>
        </ul>
      </section>
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/coupon-campaigns">Return to coupon campaigns</a>
    </footer>
  </div>
</template>
