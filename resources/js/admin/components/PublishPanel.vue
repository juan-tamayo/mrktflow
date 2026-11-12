<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

defineOptions({ name: 'PublishPanel' })

const props = defineProps<{
  kind: 'page' | 'article' | 'section_draft'
  subjectId?: string
  page?: string
  slotName?: string
  version: number
  publishedRevision: number
  label: string
  canPublish: boolean
  justPublished?: boolean
}>()

const action = computed(() =>
  props.kind === 'section_draft' ? '/admin/content-editor/publish' : '/admin/content/publish',
)
const previewHref = computed(() => {
  if (props.kind === 'page') {
    return `/admin/preview/pages/${props.subjectId ?? ''}`
  }
  if (props.kind === 'article') {
    return `/admin/preview/articles/${props.subjectId ?? ''}`
  }

  return `/admin/preview/sections?page=${encodeURIComponent(props.page ?? '')}&slot=${encodeURIComponent(props.slotName ?? '')}`
})
const previewName = computed(() => `Preview ${props.label}`)
const publishName = computed(() => `Publish ${props.label}`)
const restoreName = computed(() => `Restore prior published revision of ${props.label}`)
const publicationStatus = computed(() => `Publication status for ${props.label}`)

function submit() {
  const form =
    props.kind === 'section_draft'
      ? useForm({ page: props.page ?? '', slot: props.slotName ?? '', version: props.version })
      : useForm({ kind: props.kind, id: props.subjectId ?? '', version: props.version })
  form.post(action.value)
}

function restore() {
  const form =
    props.kind === 'section_draft'
      ? useForm({
          kind: 'section_draft',
          page: props.page ?? '',
          slot: props.slotName ?? '',
          revision: Math.max(1, props.publishedRevision - 1),
          version: props.version,
        })
      : useForm({
          kind: props.kind,
          id: props.subjectId ?? '',
          revision: Math.max(1, props.publishedRevision - 1),
          version: props.version,
        })
  form.post('/admin/content/restore')
}
</script>

<template>
  <div class="publish-panel">
    <p v-if="justPublished" role="status" aria-label="Publish result">Published {{ label }}.</p>
    <p :aria-label="publicationStatus">
      <template v-if="publishedRevision > 0"
        >Live revision {{ publishedRevision }} of {{ label }}</template
      >
      <template v-else>Not published</template>
    </p>
    <p>
      <a class="text-link" :href="previewHref" :aria-label="previewName">{{ previewName }}</a>
    </p>
    <form v-if="canPublish && version > 0" @submit.prevent="submit">
      <button type="submit" class="primary-button" :aria-label="publishName">
        {{ publishName }}
      </button>
    </form>
    <form v-if="canPublish && publishedRevision > 1" @submit.prevent="restore">
      <button type="submit" class="quiet-button" :aria-label="restoreName">
        {{ restoreName }}
      </button>
    </form>
  </div>
</template>
