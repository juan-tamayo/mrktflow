<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

defineOptions({ name: 'SystemHealth' })

type Warning = { code: string; message: string }
type JobError = { uuid: string; queue: string; error_code: string; failed_at: string | null }

defineProps<{
  health: {
    version: string
    generated_at: string
    thresholds: {
      scheduler_stale_seconds: number
      worker_lag_seconds: number
      unresolved_payment_seconds: number
      backup_max_age_seconds: number
      disk_warn_percent: number
    }
    scheduler: { current: boolean; lag_seconds: number | null; warning: boolean }
    worker: {
      queue_pending: number
      outbox_pending: number
      oldest_due_seconds: number | null
      warning: boolean
    }
    payments: { unknown_count: number; oldest_unknown_seconds: number | null; warning: boolean }
    jobs: { failed: number; errors: JobError[]; warning: boolean }
    email: { ready: boolean; failed_deliveries: number; warning: boolean }
    disk: {
      path: string
      free_bytes: number
      total_bytes: number
      used_percent: number
      warning: boolean
    }
    storage: { private_writable: boolean; public_writable: boolean; warning: boolean }
    backup: { observed_at: string | null; age_seconds: number | null; warning: boolean }
    warnings: Warning[]
  }
}>()

const csrf = computed(
  () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
)
</script>

<template>
  <Head title="System health" />
  <a class="skip-link" href="#health-main">Skip to system health</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="health-main" class="setup-main">
      <p class="eyebrow">Operations</p>
      <h1>System health<span class="accent">.</span></h1>
      <p class="lede">
        Owner diagnostics for worker and scheduler lag, unknown payments, disk, backups, and
        redacted job errors. Support export never includes private payloads.
      </p>
      <p role="note" class="hint">
        Application version {{ health.version }}. Scheduler stale after
        {{ health.thresholds.scheduler_stale_seconds }}s. Worker lag
        {{ health.thresholds.worker_lag_seconds }}s. Unknown payments
        {{ health.thresholds.unresolved_payment_seconds }}s. Backup policy
        {{ health.thresholds.backup_max_age_seconds }}s. Disk warn
        {{ health.thresholds.disk_warn_percent }}%.
      </p>
      <p v-if="health.warnings.length === 0" class="workspace-note">No operational warnings.</p>
      <ul v-else class="product-list" aria-label="Operational warnings">
        <li v-for="warning in health.warnings" :key="warning.code">
          <strong>{{ warning.code }}.</strong> {{ warning.message }}
        </li>
      </ul>
      <section aria-labelledby="lag-heading">
        <h2 id="lag-heading">Workers and scheduler</h2>
        <ul class="product-list">
          <li>
            Scheduler {{ health.scheduler.current ? 'current' : 'stale' }} · lag
            {{ health.scheduler.lag_seconds ?? 'missing' }}s
          </li>
          <li>
            Queue pending {{ health.worker.queue_pending }} · outbox pending
            {{ health.worker.outbox_pending }} · oldest due
            {{ health.worker.oldest_due_seconds ?? 'none' }}s
          </li>
        </ul>
      </section>
      <section aria-labelledby="payments-heading">
        <h2 id="payments-heading">Unknown payments</h2>
        <p>
          {{ health.payments.unknown_count }} unknown attempt(s). Oldest
          {{ health.payments.oldest_unknown_seconds ?? 'none' }}s.
        </p>
      </section>
      <section aria-labelledby="jobs-heading">
        <h2 id="jobs-heading">Failed jobs</h2>
        <p v-if="health.jobs.errors.length === 0">No failed jobs.</p>
        <ul v-else class="product-list">
          <li v-for="error in health.jobs.errors" :key="error.uuid">
            {{ error.queue }} · {{ error.error_code }} · {{ error.uuid }}
          </li>
        </ul>
      </section>
      <section aria-labelledby="storage-heading">
        <h2 id="storage-heading">Disk, storage, and backups</h2>
        <ul class="product-list">
          <li>Disk used {{ health.disk.used_percent }}% on {{ health.disk.path }}</li>
          <li>
            Private writable {{ health.storage.private_writable ? 'yes' : 'no' }}. Public writable
            {{ health.storage.public_writable ? 'yes' : 'no' }}.
          </li>
          <li>
            Backup age {{ health.backup.age_seconds ?? 'missing' }}s. Observed
            {{ health.backup.observed_at ?? 'never' }}.
          </li>
          <li>
            Email {{ health.email.ready ? 'ready' : 'not ready' }} · failed deliveries
            {{ health.email.failed_deliveries }}
          </li>
        </ul>
      </section>
      <form method="post" action="/admin/health/support-export" class="setup-form">
        <input type="hidden" name="_token" :value="csrf" />
        <button type="submit" class="primary-button">Download support export</button>
      </form>
      <p><a class="text-link" href="/admin/audit">Audit log</a></p>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
