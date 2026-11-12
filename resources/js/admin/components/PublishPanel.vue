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

function submit() {
  form
    .transform((data) => ({ ...data, version: props.version }))
    .post(action, { preserveScroll: true })
}
</script>

<template>
  <div class="publish-panel">
    <p>
      Draft version {{ version }}
      <span v-if="publishedRevision > 0"> · live revision {{ publishedRevision }}</span>
      <span v-else> · not live</span>
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
  </div>
</template>
