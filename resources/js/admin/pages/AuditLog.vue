<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'AuditLog' })

type AuditEvent = {
  id: string
  action: string
  subject_type: string
  subject_id: string
  actor_type: string
  actor_id: string | null
  metadata: { version?: number; reason_code?: string }
  correlation_id: string
  occurred_at: string
}

defineProps<{
  events: AuditEvent[]
}>()
</script>

<template>
  <Head title="Audit log" />
  <a class="skip-link" href="#audit-main">Skip to audit log</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="audit-main" class="setup-main">
      <p class="eyebrow">Operations</p>
      <h1>Audit log<span class="accent">.</span></h1>
      <p class="lede">
        Allowlisted actor, action, subject, and reason codes. Payloads, secrets, and customer
        records stay out of this view.
      </p>
      <p><a class="text-link" href="/admin/health">System health</a></p>
      <p v-if="events.length === 0" class="workspace-note">No audit events yet.</p>
      <ul v-else class="product-list">
        <li v-for="event in events" :key="event.id">
          {{ event.occurred_at }} · {{ event.action }} · {{ event.subject_type }}/{{
            event.subject_id
          }}
          · {{ event.actor_type }}
          <span v-if="event.metadata.reason_code"> · {{ event.metadata.reason_code }}</span>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
