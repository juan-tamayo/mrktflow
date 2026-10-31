<script setup lang="ts">
import { computed, ref } from 'vue'

const props = defineProps<{
  html: string | null
  loading: boolean
}>()

const emit = defineEmits<{
  refresh: []
}>()

const device = ref<'desktop' | 'mobile'>('desktop')

const srcdoc = computed(() => {
  if (props.html === null) return ''
  return `<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:system-ui,sans-serif;margin:16px}</style></head><body>${props.html}</body></html>`
})
</script>

<template>
  <section aria-labelledby="device-preview-heading">
    <h2 id="device-preview-heading">Draft preview</h2>
    <div class="preview-controls">
      <button
        type="button"
        data-device="desktop"
        :aria-pressed="device === 'desktop'"
        @click="device = 'desktop'"
      >
        Desktop
      </button>
      <button
        type="button"
        data-device="mobile"
        :aria-pressed="device === 'mobile'"
        @click="device = 'mobile'"
      >
        Mobile
      </button>
      <button
        type="button"
        data-action="refresh-preview"
        :disabled="loading"
        @click="emit('refresh')"
      >
        {{ loading ? 'Rendering…' : 'Refresh preview' }}
      </button>
    </div>
    <p class="field-help">
      Rendered by the server from the current draft — the same sections view the storefront
      composes. Device buttons change only the preview width.
    </p>
    <div class="preview-frame-wrap">
      <iframe
        v-if="html !== null"
        title="Section draft preview"
        :srcdoc="srcdoc"
        sandbox=""
        :style="{
          width: device === 'mobile' ? '390px' : '100%',
          height: '480px',
          border: '1px solid #ccc',
        }"
      />
      <p v-else>No preview yet. Refresh the preview to render the current draft.</p>
    </div>
  </section>
</template>
