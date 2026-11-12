<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'

defineOptions({ name: 'NotificationFailures' })

type Failure = {
  id: string
  template: string
  policyClass: string
  status: string
  errorCode: string | null
  attempts: number
  recipient: string
  availableAt: string | null
}
defineProps<{
  exactlyOnce: boolean
  disclaimer: string
  failures: Failure[]
  retried: boolean
}>()
function retry(id: string) {
  useForm({}).post(`/admin/notifications/${id}/retry`, { preserveScroll: true })
}
</script>

<template>
  <Head title="Notification failures" />
  <a class="skip-link" href="#failures-main">Skip to notification failures</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="failures-main" class="setup-main">
      <p class="eyebrow">Operations</p>
      <h1>Notification failures<span class="accent">.</span></h1>
      <p class="lede">
        Failed and blocked deliveries stay visible with a public error code. Retry after repairing
        the transport. Marketing retries recheck suppression; a paid order is never reversed.
      </p>
      <p role="note" class="hint">{{ disclaimer }}</p>
      <p v-if="!exactlyOnce" class="hint">Exactly-once SMTP is not claimed.</p>
      <p v-if="retried" role="status" class="success-notice">
        Delivery scheduled for another attempt.
      </p>
      <p><a class="text-link" href="/admin/email">Email settings</a></p>
      <p v-if="failures.length === 0" class="lede">No failed or blocked deliveries.</p>
      <ul v-else class="workspace-tools">
        <li v-for="row in failures" :key="row.id" class="workspace-tool">
          <div>
            <p>
              {{ row.template }} · {{ row.policyClass }} · {{ row.status }} ·
              {{ row.errorCode ?? 'none' }} · attempt {{ row.attempts }} · {{ row.recipient }}
            </p>
            <button
              v-if="row.status === 'failed'"
              type="button"
              class="primary-button"
              @click="retry(row.id)"
            >
              Retry
            </button>
          </div>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
