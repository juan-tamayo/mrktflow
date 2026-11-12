<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import DevicePreview from '../components/DevicePreview.vue'
import DraftDiff from '../components/DraftDiff.vue'
import { Head } from '@inertiajs/vue3'
import { computed, ref } from 'vue'

defineOptions({ name: 'AiStudio' })

type PageDocument = {
  schemaVersion: string
  kind: string
  title: string
  templateKey: string
  sections: Record<string, unknown>[]
}
type Usage = {
  input_tokens: number | null
  output_tokens: number | null
  total_tokens: number | null
  known: boolean
}
type RunSummary = {
  runId: string
  draftId: string
  status: string
  title: string
  updatedAt: string
}

const props = defineProps<{
  runs?: RunSummary[]
  runId: string | null
  draftId: string | null
  status: string | null
  stale: boolean
  canApply: boolean
  canPublish: boolean
  expectedRevisionId: string | null
  currentRevisionId: string | null
  pageId: string | null
  pageVersion: number
  publishedRevision: number
  prompt: string
  model: string
  candidate: PageDocument | null
  base: { title: string; slug: string; path: string } | null
  usage: Usage
  warnings: string[]
}>()

const draft = ref<PageDocument | null>(
  props.candidate === null ? null : (JSON.parse(JSON.stringify(props.candidate)) as PageDocument),
)
const previewHtml = ref<string | null>(null)
const previewing = ref(false)
const applying = ref(false)
const announcement = ref('')
const applied = ref(props.status === 'applied')

const afterSummary = computed(() => JSON.stringify(draft.value?.sections ?? [], null, 2))
const beforeSummary = computed(() =>
  props.base === null
    ? ''
    : JSON.stringify({ slug: props.base.slug, path: props.base.path }, null, 2),
)
const usageLabel = computed(() => {
  if (!props.usage.known || props.usage.total_tokens === null) {
    return 'Usage is still pending or unknown.'
  }
  return `${props.usage.input_tokens ?? 0} input · ${props.usage.output_tokens ?? 0} output · ${props.usage.total_tokens} total tokens`
})

function sectionSettings(section: Record<string, unknown>): Record<string, unknown> | null {
  const settings = section.settings
  if (typeof settings !== 'object' || settings === null) return null
  return settings as Record<string, unknown>
}

function sectionText(section: Record<string, unknown>): string {
  const settings = sectionSettings(section)
  return typeof settings?.text === 'string' ? settings.text : ''
}

function sectionHeading(section: Record<string, unknown>): string {
  const settings = sectionSettings(section)
  return typeof settings?.heading === 'string' ? settings.heading : ''
}

function setSectionField(index: number, field: 'text' | 'heading', value: string) {
  const current = draft.value
  if (current === null) return
  const section = current.sections[index]
  if (section === undefined) return
  const settings = sectionSettings(section)
  if (settings === null) return
  settings[field] = value
  section.settings = settings
  current.sections[index] = section
}

async function api(
  path: string,
  body: unknown,
): Promise<{ status: number; data: Record<string, unknown> }> {
  const cookie = window.document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
  if (!cookie) throw new Error('Missing request-forgery proof')
  const response = await fetch(path, {
    method: 'POST',
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)),
    },
    body: JSON.stringify(body),
  })
  const data = (await response.json().catch(() => ({}))) as Record<string, unknown>
  return { status: response.status, data }
}

async function refreshPreview() {
  if (draft.value === null || props.runId === null) return
  previewing.value = true
  try {
    const { status, data } = await api(`/admin/ai/studio/${props.runId}/preview`, {
      document: draft.value,
    })
    if (status === 200 && typeof data.html === 'string') {
      previewHtml.value = data.html
      announcement.value = 'Desktop and mobile preview updated from the current candidate.'
    } else {
      announcement.value =
        typeof data.message === 'string' ? data.message : 'The preview could not be rendered.'
    }
  } finally {
    previewing.value = false
  }
}

async function applyDraft() {
  if (draft.value === null || props.runId === null || props.expectedRevisionId === null) return
  applying.value = true
  try {
    const { status, data } = await api(`/admin/ai/studio/${props.runId}/apply`, {
      expected_revision_id: props.expectedRevisionId,
      document: draft.value,
    })
    if (status === 200) {
      applied.value = true
      announcement.value = 'Applied to the content draft. Publishing remains a separate action.'
    } else if (status === 409) {
      announcement.value =
        typeof data.message === 'string'
          ? data.message
          : 'This page changed since generation. Apply is blocked.'
    } else {
      announcement.value =
        typeof data.message === 'string' ? data.message : 'The draft could not be applied.'
    }
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <Head title="AI studio" />
  <a class="skip-link" href="#ai-studio-main">Skip to AI studio</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="ai-studio-main" class="setup-main">
      <p class="eyebrow">Author review</p>
      <h1>AI studio<span class="accent">.</span></h1>
      <p class="lede">
        Review usage, edit the candidate, preview desktop and mobile, and apply onto a matching
        content draft. Publishing stays on pages, articles, and menus.
      </p>
      <p v-if="announcement" role="status">{{ announcement }}</p>
      <p v-if="applied" role="status">Applied to draft. Live publication is unchanged.</p>
      <ul v-if="runs && runs.length > 0 && candidate === null">
        <li v-for="run in runs" :key="run.runId">
          <a class="text-link" :href="`/admin/ai/studio/${run.runId}`">{{ run.title }}</a>
          <span class="hint"> {{ run.status }}</span>
        </li>
      </ul>
      <p v-else-if="candidate === null" class="hint">No drafts are ready for review.</p>
      <template v-if="candidate !== null && draft !== null && base !== null">
        <p class="hint">
          {{ usageLabel }} · {{ model }} · page {{ pageId }} version {{ pageVersion }} · draft
          {{ draftId }} · current {{ currentRevisionId }} · published {{ publishedRevision }}
        </p>
        <p class="hint">{{ prompt }}</p>
        <ul>
          <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
        </ul>
        <div class="setup-field">
          <label for="ai-draft-title">Draft title</label>
          <input id="ai-draft-title" v-model="draft.title" maxlength="200" />
        </div>
        <div v-for="(section, index) in draft.sections" :key="index" class="setup-field">
          <label v-if="sectionText(section)" :for="`ai-section-text-${index}`">
            Section {{ index + 1 }} text
            <textarea
              :id="`ai-section-text-${index}`"
              :value="sectionText(section)"
              maxlength="5000"
              @input="setSectionField(index, 'text', ($event.target as HTMLTextAreaElement).value)"
            />
          </label>
          <label v-else-if="sectionHeading(section)" :for="`ai-section-heading-${index}`">
            Section {{ index + 1 }} heading
            <input
              :id="`ai-section-heading-${index}`"
              :value="sectionHeading(section)"
              maxlength="200"
              @input="setSectionField(index, 'heading', ($event.target as HTMLInputElement).value)"
            />
          </label>
        </div>
        <DraftDiff
          :before-title="base.title"
          :after-title="draft.title"
          :before-summary="beforeSummary"
          :after-summary="afterSummary"
        />
        <DevicePreview :html="previewHtml" :loading="previewing" @refresh="refreshPreview" />
        <p v-if="stale" role="alert">
          This page changed since generation. Apply is blocked until you generate from the current
          revision.
        </p>
        <button
          v-if="canApply && !applied"
          type="button"
          class="primary-button"
          :disabled="applying || stale"
          @click="applyDraft"
        >
          {{ applying ? 'Applying…' : 'Apply to draft' }}
        </button>
        <p class="hint">
          Publishing requires a separate permission on
          <a class="text-link" href="/admin/content">pages, articles, and menus</a>.
          <span v-if="!canPublish"> This account cannot publish.</span>
        </p>
      </template>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
