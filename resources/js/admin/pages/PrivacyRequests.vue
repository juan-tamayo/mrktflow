<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

defineOptions({ name: 'PrivacyRequests' })

type PrivacyRequest = {
  id: string
  type: string
  status: string
  email: string
  verified_at: string
  processed_at: string | null
}

defineProps<{
  requests: PrivacyRequest[]
  retention: {
    guest_carts_days: number
    ai_prompt_logs_days: number
    order_days: number | null
  }
  processed: boolean
}>()

const csrf = computed(
  () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
)
</script>

<template>
  <Head title="Privacy requests" />
  <a class="skip-link" href="#privacy-main">Skip to privacy requests</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="privacy-main" class="setup-main">
      <p class="eyebrow">Identity</p>
      <h1>Privacy requests<span class="accent">.</span></h1>
      <p class="lede">
        Export and deletion requests are identity-verified. Eligible profile data can be anonymized.
        Order, receipt, and payment reconciliation records stay valid.
      </p>
      <p role="note" class="hint">
        Guest carts expire after {{ retention.guest_carts_days }} days. Unpublished AI prompts
        expire after {{ retention.ai_prompt_logs_days }} days. Order retention
        {{
          retention.order_days === null
            ? 'is configured per store policy before launch'
            : `is ${retention.order_days} days`
        }}. Financial history is never blanket-deleted.
      </p>
      <p v-if="processed" role="status" class="success-notice">Privacy request processed.</p>
      <p v-if="requests.length === 0" class="workspace-note">No privacy requests are waiting.</p>
      <ul v-else class="product-list">
        <li v-for="item in requests" :key="item.id">
          {{ item.type }} · {{ item.status }} · {{ item.email }} · verified {{ item.verified_at }}
          <form
            v-if="item.status === 'pending'"
            method="post"
            :action="`/admin/privacy/${item.id}/process`"
            class="inline-form"
          >
            <input type="hidden" name="_token" :value="csrf" />
            <button type="submit" class="primary-button">Process</button>
          </form>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
