<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'DownloadAccess' })

type AvailableVersion = { id: string; version: number; bytes: number }
type Download = {
  id: string
  line_id: string
  title: string
  sku: string
  asset_label: string
  version_id: string
  version: number
  purchase_version: number
  status: string
  download_limit: number
  downloads_used: number
  downloads_remaining: number
  available_versions: AvailableVersion[]
}
const props = defineProps<{ orderId: string; downloads: Download[] }>()
const actionable = (download: Download) =>
  download.status === 'active' || download.status === 'suspended'
const entries = props.downloads.map((download) => ({
  download,
  update: useForm({
    version_id: download.available_versions[0]?.id ?? '',
    reason: '',
    idempotency_key: '',
  }),
  reset: useForm({
    download_limit: '',
    reason: '',
    idempotency_key: '',
  }),
}))
type Entry = (typeof entries)[number]
const submitUpdate = (entry: Entry) => {
  entry.update.idempotency_key = crypto.randomUUID()
  entry.update.post(`/admin/orders/${props.orderId}/entitlements/${entry.download.id}/update`, {
    preserveScroll: true,
  })
}
const submitReset = (entry: Entry) => {
  entry.reset.idempotency_key = crypto.randomUUID()
  entry.reset.post(`/admin/orders/${props.orderId}/entitlements/${entry.download.id}/reset-limit`, {
    preserveScroll: true,
  })
}
</script>

<template>
  <h2>Downloads</h2>
  <p class="workspace-note">
    Buyers download the version pinned to their purchase. Granting a newer version or resetting the
    allowance is a deliberate staff action recorded with its reason; the purchased version stays on
    the order.
  </p>
  <p v-if="downloads.length === 0" class="workspace-note">No downloads for this order.</p>
  <ul v-else class="product-list">
    <li v-for="entry in entries" :key="entry.download.id">
      <p>
        {{ entry.download.title }} · {{ entry.download.sku }} · {{ entry.download.asset_label }} ·
        version {{ entry.download.version }} · purchased version
        {{ entry.download.purchase_version }} · {{ entry.download.status }}
      </p>
      <p>
        {{ entry.download.downloads_used }} of {{ entry.download.download_limit }} used ·
        {{ entry.download.downloads_remaining }} remaining
      </p>
      <template v-if="actionable(entry.download)">
        <form
          v-if="entry.download.available_versions.length > 0"
          class="setup-form"
          @submit.prevent="submitUpdate(entry)"
        >
          <label>
            Grant a newer version
            <select v-model="entry.update.version_id" name="version_id">
              <option
                v-for="version in entry.download.available_versions"
                :key="version.id"
                :value="version.id"
              >
                version {{ version.version }}
              </option>
            </select>
          </label>
          <label>
            Reason
            <textarea
              v-model="entry.update.reason"
              name="reason"
              maxlength="500"
              rows="2"
              required
            />
          </label>
          <button type="submit" class="primary-button" :disabled="entry.update.processing">
            {{ entry.update.processing ? 'Granting…' : 'Grant update' }}
          </button>
        </form>
        <form class="setup-form" @submit.prevent="submitReset(entry)">
          <label>
            New download limit (optional)
            <input
              v-model="entry.reset.download_limit"
              name="download_limit"
              type="number"
              min="1"
              max="1000"
            />
          </label>
          <label>
            Reason
            <textarea
              v-model="entry.reset.reason"
              name="reason"
              maxlength="500"
              rows="2"
              required
            />
          </label>
          <button type="submit" class="primary-button" :disabled="entry.reset.processing">
            {{ entry.reset.processing ? 'Resetting…' : 'Reset downloads' }}
          </button>
        </form>
      </template>
    </li>
  </ul>
</template>
