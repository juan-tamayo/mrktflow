<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import DevicePreview from '../components/DevicePreview.vue'
import SectionList from '../components/SectionList.vue'
import SectionSettings from '../components/SectionSettings.vue'
import {
  createSection,
  EMPTY_ERRORS,
  errorCount,
  errorsForSection,
  toPayload,
  type GroupedSectionErrors,
  type SectionDefinition,
  type SectionInstance,
} from '../section-editor'

defineOptions({ name: 'ContentEditor' })

type Target = { page: string; slots: string[] }
type DraftResponse = {
  version: number
  sections: SectionInstance[]
  currentVersion?: number
}

const props = defineProps<{
  definitions: SectionDefinition[]
  targets: Target[]
  initial: { page: string; slot: string; version: number; sections: SectionInstance[] }
  canWrite: boolean
}>()

const definitionByType = computed(
  () => new Map(props.definitions.map((definition) => [definition.type, definition])),
)

/** Section payloads are JSON-safe by construction; Inertia props are reactive proxies structuredClone refuses. */
function deepClone<T>(value: T): T {
  return JSON.parse(JSON.stringify(value)) as T
}

const page = ref(props.initial.page)
const slot = ref(props.initial.slot)
const sections = ref<SectionInstance[]>(deepClone(props.initial.sections))
const savedVersion = ref(props.initial.version)
const savedSnapshot = ref(JSON.stringify(props.initial.sections))
const selectedId = ref<string | null>(props.initial.sections[0]?.id ?? null)
const errors = ref<GroupedSectionErrors>(EMPTY_ERRORS)
const announcement = ref('')
const previewHtml = ref<string | null>(null)
const busy = ref(false)
const previewing = ref(false)
const conflictVersion = ref<number | null>(null)

const dirty = computed(() => JSON.stringify(sections.value) !== savedSnapshot.value)
const slotsForPage = computed(
  () => props.targets.find((target) => target.page === page.value)?.slots ?? [],
)
const selectedSection = computed(
  () => sections.value.find((section) => section.id === selectedId.value) ?? null,
)
const selectedDefinition = computed(() =>
  selectedSection.value ? (definitionByType.value.get(selectedSection.value.type) ?? null) : null,
)

function announce(message: string) {
  announcement.value = message
}

async function api(
  path: string,
  method: string,
  body?: unknown,
): Promise<{ status: number; data: Record<string, unknown> }> {
  const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
  if (!cookie) throw new Error('Missing request-forgery proof')
  const response = await fetch(path, {
    method,
    credentials: 'same-origin',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)),
    },
    ...(body === undefined ? {} : { body: JSON.stringify(body) }),
  })
  const data = (await response.json().catch(() => ({}))) as Record<string, unknown>
  return { status: response.status, data }
}

function applyDraft(data: DraftResponse) {
  sections.value = deepClone(data.sections)
  savedVersion.value = data.version
  savedSnapshot.value = JSON.stringify(data.sections)
  selectedId.value = data.sections[0]?.id ?? null
  errors.value = EMPTY_ERRORS
  conflictVersion.value = null
}

async function loadTarget() {
  const { status, data } = await api(
    `/admin/content-editor/draft?page=${encodeURIComponent(page.value)}&slot=${encodeURIComponent(slot.value)}`,
    'GET',
  )
  if (status === 200) {
    applyDraft(data as unknown as DraftResponse)
    announce(`Loaded the ${page.value} ${slot.value} draft (version ${savedVersion.value}).`)
  } else {
    announce('The draft could not be loaded. Try again.')
  }
}

async function changeTarget(nextPage: string, nextSlot: string) {
  if (dirty.value && !window.confirm('Discard unsaved changes and load the other draft?')) {
    return
  }
  page.value = nextPage
  slot.value = nextSlot
  await loadTarget()
}

function onPageChange(event: Event) {
  const nextPage = (event.target as HTMLSelectElement).value
  const firstSlot = props.targets.find((target) => target.page === nextPage)?.slots[0] ?? 'content'
  void changeTarget(nextPage, firstSlot)
}

function onSlotChange(event: Event) {
  void changeTarget(page.value, (event.target as HTMLSelectElement).value)
}

function addSection(type: string) {
  const definition = definitionByType.value.get(type)
  if (!definition) return
  const section = createSection(definition, sections.value)
  sections.value = [...sections.value, section]
  selectedId.value = section.id
  announce(`${definition.label} added as section ${section.id}.`)
}

function replaceSections(next: SectionInstance[]) {
  sections.value = next
  if (selectedId.value !== null && !next.some((section) => section.id === selectedId.value)) {
    selectedId.value = next[0]?.id ?? null
  }
}

function updateSection(next: SectionInstance) {
  sections.value = sections.value.map((section) => (section.id === next.id ? next : section))
}

function applyErrors(data: Record<string, unknown>) {
  const grouped = (data.errors ?? EMPTY_ERRORS) as GroupedSectionErrors
  errors.value = {
    document: grouped.document ?? [],
    sections: grouped.sections ?? {},
  }
}

async function save() {
  if (!props.canWrite || busy.value) return
  busy.value = true
  try {
    const { status, data } = await api('/admin/content-editor/draft', 'PUT', {
      page: page.value,
      slot: slot.value,
      version: savedVersion.value,
      sections: toPayload(sections.value, definitionByType.value),
    })
    if (status === 200) {
      applyDraft(data as unknown as DraftResponse)
      announce(`Draft saved (version ${savedVersion.value}).`)
    } else if (status === 422) {
      applyErrors(data)
      announce(
        `The draft could not be saved: ${errorCount(errors.value)} problems to fix. Your changes are still here — fix them and save again.`,
      )
    } else if (status === 409) {
      conflictVersion.value = (data.currentVersion as number) ?? null
      announce('The draft changed since you loaded it. Reload the saved draft before saving again.')
    } else {
      announce('The draft could not be saved. Try again.')
    }
  } catch {
    announce('The draft could not be saved. Try again.')
  } finally {
    busy.value = false
  }
}

async function refreshPreview() {
  if (previewing.value) return
  previewing.value = true
  try {
    const { status, data } = await api('/admin/content-editor/preview', 'POST', {
      page: page.value,
      slot: slot.value,
      sections: toPayload(sections.value, definitionByType.value),
    })
    if (status === 200) {
      previewHtml.value = data.html as string
      errors.value = EMPTY_ERRORS
      announce('Preview updated.')
    } else if (status === 422) {
      applyErrors(data)
      announce(
        `The preview could not be rendered: ${errorCount(errors.value)} problems to fix first.`,
      )
    } else {
      announce('The preview could not be rendered. Try again.')
    }
  } catch {
    announce('The preview could not be rendered. Try again.')
  } finally {
    previewing.value = false
  }
}
</script>

<template>
  <main>
    <Head title="Content editor" />
    <h1>Content editor</h1>
    <p class="field-help">
      Edit the section draft for one page area. Drafts are validated by the platform before they
      save; nothing here publishes — publication is a separate step.
    </p>
    <p role="status">{{ announcement }}</p>

    <div class="setup-grid">
      <div class="setup-field">
        <label for="editor-page">Page</label>
        <select id="editor-page" :value="page" @change="onPageChange">
          <option v-for="target in targets" :key="target.page" :value="target.page">
            {{ target.page }}
          </option>
        </select>
      </div>
      <div class="setup-field">
        <label for="editor-slot">Area</label>
        <select id="editor-slot" :value="slot" @change="onSlotChange">
          <option v-for="name in slotsForPage" :key="name" :value="name">{{ name }}</option>
        </select>
      </div>
    </div>
    <p>
      Draft version {{ savedVersion }}{{ dirty ? ' · unsaved changes' : '' }}
      <button
        v-if="canWrite"
        type="button"
        class="primary-button"
        data-action="save-draft"
        :disabled="busy"
        @click="save"
      >
        {{ busy ? 'Saving…' : 'Save draft' }}
      </button>
    </p>

    <div v-if="conflictVersion !== null" class="error-summary" role="alert">
      <p>
        This draft is now at version {{ conflictVersion }} — someone saved after you loaded it. Your
        changes are still here, but saving needs the current draft.
      </p>
      <button type="button" data-action="reload-draft" @click="loadTarget">
        Reload saved draft
      </button>
    </div>

    <div v-if="errorCount(errors) > 0" class="error-summary" role="alert">
      <p>{{ errorCount(errors) }} problems to fix:</p>
      <ul>
        <li v-for="error in errors.document" :key="error">{{ error }}</li>
        <template v-for="(list, key) in errors.sections" :key="key">
          <li v-for="error in list" :key="error">
            <button type="button" @click="selectedId = String(key)">{{ error }}</button>
          </li>
        </template>
      </ul>
    </div>

    <SectionList
      :sections="sections"
      :definitions="definitions"
      :errors="errors"
      :selected-id="selectedId"
      :can-write="canWrite"
      @update="replaceSections"
      @select="selectedId = $event"
      @add="addSection"
      @announce="announce"
    />

    <SectionSettings
      v-if="selectedSection && selectedDefinition"
      :definition="selectedDefinition"
      :section="selectedSection"
      :errors="
        errorsForSection(
          errors,
          selectedSection,
          sections.findIndex((section) => section.id === selectedSection?.id),
        )
      "
      :can-write="canWrite"
      @update="updateSection"
    />

    <DevicePreview :html="previewHtml" :loading="previewing" @refresh="refreshPreview" />
  </main>
</template>
