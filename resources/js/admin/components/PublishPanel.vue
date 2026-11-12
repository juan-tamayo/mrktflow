<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

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
}>()

const form = useForm(
  props.kind === 'section_draft'
    ? { page: props.page ?? '', slot: props.slotName ?? '', version: props.version }
    : { kind: props.kind, id: props.subjectId ?? '', version: props.version },
)
const restoreForm = useForm(
  props.kind === 'section_draft'
    ? {
        kind: 'section_draft',
        page: props.page ?? '',
        slot: props.slotName ?? '',
        revision: Math.max(1, props.publishedRevision - 1),
        version: props.version,
      }
    : {
        kind: props.kind,
        id: props.subjectId ?? '',
        revision: Math.max(1, props.publishedRevision - 1),
        version: props.version,
      },
)

const action =
  props.kind === 'section_draft' ? '/admin/content-editor/publish' : '/admin/content/publish'
const previewHref =
  props.kind === 'page'
    ? `/admin/preview/pages/${props.subjectId ?? ''}`
    : props.kind === 'article'
      ? `/admin/preview/articles/${props.subjectId ?? ''}`
      : `/admin/preview/sections?page=${encodeURIComponent(props.page ?? '')}&slot=${encodeURIComponent(props.slotName ?? '')}`
const previewName = `Preview ${props.label}`
const publishName = `Publish ${props.label}`
const restoreName = `Restore prior published revision of ${props.label}`

function submit() {
  form
    .transform((data) => ({ ...data, version: props.version }))
    .post(action, { preserveScroll: true })
}

function restore() {
  restoreForm
    .transform((data) => ({
      ...data,
      version: props.version,
      revision: Math.max(1, props.publishedRevision - 1),
    }))
    .post('/admin/content/restore', { preserveScroll: true })
}
</script>

<template>
  <div class="publish-panel">
    <p :aria-label="`Publication status for ${label}`">
      <template v-if="publishedRevision > 0">Live revision {{ publishedRevision }}</template>
      <template v-else>Not published</template>
    </p>
    <p>
      <a class="text-link" :href="previewHref">{{ previewName }}</a>
    </p>
    <form v-if="canPublish && version > 0" @submit.prevent="submit">
      <button
        type="submit"
        class="primary-button"
        :aria-label="publishName"
        :disabled="form.processing"
      >
        {{ form.processing ? 'Publishing…' : publishName }}
      </button>
    </form>
    <form v-if="canPublish && publishedRevision > 1" @submit.prevent="restore">
      <button
        type="submit"
        class="quiet-button"
        :aria-label="restoreName"
        :disabled="restoreForm.processing"
      >
        {{ restoreForm.processing ? 'Restoring…' : restoreName }}
      </button>
    </form>
  </div>
</template>
