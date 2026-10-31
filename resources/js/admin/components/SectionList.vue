<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import {
  countByType,
  errorsForSection,
  moveSection,
  type GroupedSectionErrors,
  type SectionDefinition,
  type SectionInstance,
} from '../section-editor'

const props = defineProps<{
  sections: SectionInstance[]
  definitions: SectionDefinition[]
  errors: GroupedSectionErrors
  selectedId: string | null
  canWrite: boolean
}>()

const emit = defineEmits<{
  update: [sections: SectionInstance[]]
  select: [id: string]
  add: [type: string]
  announce: [message: string]
}>()

const root = ref<HTMLElement | null>(null)
const addType = ref('')

const definitionByType = computed(
  () => new Map(props.definitions.map((definition) => [definition.type, definition])),
)

function label(section: SectionInstance): string {
  return definitionByType.value.get(section.type)?.label ?? section.type
}

function atLimit(definition: SectionDefinition): boolean {
  return countByType(props.sections, definition.type) >= definition.maxPerPage
}

function focusControl(sectionId: string, action: string) {
  root.value
    ?.querySelector<HTMLElement>(`[data-section-id="${sectionId}"] [data-action="${action}"]`)
    ?.focus()
}

async function move(index: number, delta: -1 | 1) {
  const section = props.sections[index]
  if (!section) return
  const next = moveSection(props.sections, index, delta)
  if (next === props.sections) return
  emit('update', next)
  emit('announce', `${label(section)} moved to position ${index + delta + 1} of ${next.length}.`)
  await nextTick()
  focusControl(section.id, delta === -1 ? 'move-up' : 'move-down')
}

async function remove(index: number) {
  const section = props.sections[index]
  if (!section) return
  const next = props.sections.filter((_, candidate) => candidate !== index)
  emit('update', next)
  emit('announce', `${label(section)} removed. ${next.length} sections remain.`)
  await nextTick()
  const neighbour = next[Math.min(index, next.length - 1)]
  if (neighbour) focusControl(neighbour.id, 'configure')
  else root.value?.querySelector<HTMLElement>('[data-action="add-type"]')?.focus()
}

function onKeydown(event: KeyboardEvent, index: number) {
  if (!event.altKey) return
  if (event.key === 'ArrowUp') {
    event.preventDefault()
    void move(index, -1)
  } else if (event.key === 'ArrowDown') {
    event.preventDefault()
    void move(index, 1)
  }
}

function add() {
  if (addType.value === '') return
  emit('add', addType.value)
  addType.value = ''
}
</script>

<template>
  <section ref="root" aria-labelledby="section-list-heading">
    <h2 id="section-list-heading">Sections</h2>
    <p class="field-help">
      Use the move buttons — or Alt + arrow keys on a section — to reorder. Every change is
      announced.
    </p>
    <p v-if="sections.length === 0">No sections yet. Add the first block below.</p>
    <ol v-else class="section-list">
      <li
        v-for="(section, index) in sections"
        :key="section.id"
        :data-section-id="section.id"
        class="section-list-item"
        @keydown="onKeydown($event, index)"
      >
        <div class="section-list-row">
          <button
            type="button"
            data-action="configure"
            :aria-pressed="selectedId === section.id"
            @click="emit('select', section.id)"
          >
            {{ label(section) }} · {{ section.id }}
          </button>
          <button
            type="button"
            data-action="move-up"
            :disabled="!canWrite || index === 0"
            :aria-label="`Move ${label(section)} up`"
            @click="move(index, -1)"
          >
            Move up
          </button>
          <button
            type="button"
            data-action="move-down"
            :disabled="!canWrite || index === sections.length - 1"
            :aria-label="`Move ${label(section)} down`"
            @click="move(index, 1)"
          >
            Move down
          </button>
          <button
            type="button"
            data-action="remove"
            :disabled="!canWrite"
            :aria-label="`Remove ${label(section)}`"
            @click="remove(index)"
          >
            Remove
          </button>
        </div>
        <ul
          v-if="errorsForSection(errors, section, index).length > 0"
          class="section-errors"
          role="alert"
        >
          <li v-for="error in errorsForSection(errors, section, index)" :key="error">
            {{ error }}
          </li>
        </ul>
      </li>
    </ol>
    <div v-if="canWrite" class="section-add">
      <label for="add-section-type">Add a block</label>
      <select id="add-section-type" v-model="addType" data-action="add-type">
        <option value="">Choose a block…</option>
        <option
          v-for="definition in definitions"
          :key="definition.type"
          :value="definition.type"
          :disabled="atLimit(definition)"
        >
          {{ definition.label
          }}{{ atLimit(definition) ? ` (limit ${definition.maxPerPage} reached)` : '' }}
        </option>
      </select>
      <button type="button" data-action="add" :disabled="addType === ''" @click="add">
        Add block
      </button>
    </div>
  </section>
</template>
