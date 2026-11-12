<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'
import { computed } from 'vue'

defineOptions({ name: 'StoreExports' })

type ExportJob = {
  id: string
  dataset: string
  status: string
  row_count: number
  expires_at: string
  download_url: string | null
}

defineProps<{
  jobs: ExportJob[]
  retentionHours: number
  queued: boolean
}>()

const csrf = computed(
  () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
)
</script>

<template>
  <Head title="Exports" />
  <a class="skip-link" href="#exports-main">Skip to exports</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="exports-main" class="setup-main">
      <p class="eyebrow">Operations</p>
      <h1>Private exports<span class="accent">.</span></h1>
      <p class="lede">
        Customer, order, and product CSVs are formula-safe, stored on the private disk, and
        downloadable only by the staff member who requested them.
      </p>
      <p role="note" class="hint">
        Downloads expire after {{ retentionHours }} hours. There is no public permanent URL.
        Spreadsheet formula cells are neutralized before download.
      </p>
      <p v-if="queued" role="status" class="success-notice">Export queued for private download.</p>
      <form method="post" action="/admin/exports" class="setup-form">
        <input type="hidden" name="_token" :value="csrf" />
        <label>
          Dataset
          <select name="dataset" required>
            <option value="customers">Customers</option>
            <option value="orders">Orders</option>
            <option value="products">Products</option>
          </select>
        </label>
        <button type="submit" class="primary-button">Queue export</button>
      </form>
      <p v-if="jobs.length === 0" class="workspace-note">No export files are waiting.</p>
      <ul v-else class="product-list">
        <li v-for="job in jobs" :key="job.id">
          {{ job.dataset }} · {{ job.status }} · {{ job.row_count }} rows · expires
          {{ job.expires_at }}
          <a v-if="job.download_url" class="text-link" :href="job.download_url">Download CSV</a>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
