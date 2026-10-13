<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'DigitalAssets' })

type FileVersion = {
  id: string
  version: number
  status: string
  bytes: number
  content_type: string
}
type ReadyVersion = FileVersion & { label: string }
type Asset = { id: string; label: string; versions: FileVersion[] }
type StaffVariant = { id: string; sku: string; digital_files?: { id?: string; label: string }[] }

const props = defineProps<{
  productId: string
  variants: StaffVariant[]
  assets: Asset[]
}>()
const errors = ref<HTMLElement | null>(null)
const selected = ref(props.variants[0]?.id ?? '')
const upload = useForm({
  label: '',
  digital_asset_id: '',
  file: null as File | null,
})
const attach = useForm({
  version_ids: (props.variants[0]?.digital_files ?? [])
    .map((file) => file.id)
    .filter((id): id is string => typeof id === 'string'),
})
function readyVersions(): ReadyVersion[] {
  const ready: ReadyVersion[] = []
  for (const asset of props.assets) {
    for (const version of asset.versions) {
      if (version.status === 'ready') {
        ready.push({ ...version, label: `${asset.label} (v${version.version})` })
      }
    }
  }
  return ready
}
function submitUpload() {
  upload.post(`/admin/products/${props.productId}/digital-assets`, {
    forceFormData: true,
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
function submitAttach() {
  attach.put(`/admin/products/${props.productId}/variants/${selected.value}/digital-assets`, {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
</script>

<template>
  <section class="digital-assets" aria-labelledby="digital-assets-heading">
    <h2 id="digital-assets-heading">Digital files</h2>
    <p class="lede">
      Upload private versioned files. Shoppers never see storage keys. Only ready versions can be
      attached. Each digital variant is quantity one.
    </p>
    <form class="setup-form" @submit.prevent="submitUpload">
      <div
        v-if="Object.keys(upload.errors).length"
        ref="errors"
        role="alert"
        tabindex="-1"
        class="error-summary"
      >
        <p v-for="(message, field) in upload.errors" :key="field">{{ message }}</p>
      </div>
      <div class="setup-field">
        <label for="digital-label">File label</label>
        <input id="digital-label" v-model="upload.label" name="label" maxlength="120" required />
      </div>
      <div class="setup-field">
        <label for="digital-file">Private file</label>
        <input
          id="digital-file"
          name="file"
          type="file"
          required
          @change="upload.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
        />
      </div>
      <button type="submit" class="quiet-button" :disabled="upload.processing">
        {{ upload.processing ? 'Uploading…' : 'Upload file version' }}
      </button>
    </form>
    <form v-if="assets.length" class="setup-form" @submit.prevent="submitAttach">
      <div class="setup-field">
        <label for="digital-variant">Variant</label>
        <select id="digital-variant" v-model="selected">
          <option v-for="variant in variants" :key="variant.id" :value="variant.id">
            {{ variant.sku }}
          </option>
        </select>
      </div>
      <fieldset class="setup-field">
        <legend>Ready versions</legend>
        <label v-for="version in readyVersions()" :key="version.id" class="category-option">
          <input v-model="attach.version_ids" type="checkbox" :value="version.id" />
          {{ version.label }}
        </label>
      </fieldset>
      <button type="submit" class="primary-button" :disabled="attach.processing">
        {{ attach.processing ? 'Saving…' : 'Attach files' }}
      </button>
    </form>
  </section>
</template>
