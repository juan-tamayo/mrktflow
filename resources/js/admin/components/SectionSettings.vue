<script setup lang="ts">
import { computed } from 'vue'
import {
  defaultBinding,
  fieldLabel,
  type SectionDefinition,
  type SectionInstance,
} from '../section-editor'
import SectionFieldEditor from './SectionFieldEditor.vue'

const props = defineProps<{
  definition: SectionDefinition
  section: SectionInstance
  errors: string[]
  canWrite: boolean
}>()

const emit = defineEmits<{
  update: [section: SectionInstance]
}>()

const singleKinds = ['product', 'category']
const spec = computed(() => props.definition.binding)
const isSingle = computed(() => singleKinds.includes(props.section.binding?.kind ?? ''))

function setSettings(settings: Record<string, unknown>) {
  emit('update', { ...props.section, settings })
}

function setKind(kind: string) {
  if (!spec.value) return
  const binding = singleKinds.includes(kind) ? { kind, publicId: '' } : { kind, publicIds: [] }
  emit('update', { ...props.section, binding })
}

function setPublicId(publicId: string) {
  if (!props.section.binding) return
  emit('update', { ...props.section, binding: { ...props.section.binding, publicId } })
}

function publicIds(): string[] {
  return props.section.binding?.publicIds ?? []
}

function setPublicIdAt(index: number, value: string) {
  if (!props.section.binding) return
  const ids = [...publicIds()]
  ids[index] = value
  emit('update', { ...props.section, binding: { ...props.section.binding, publicIds: ids } })
}

function addPublicId() {
  if (!props.section.binding) return
  emit('update', {
    ...props.section,
    binding: { ...props.section.binding, publicIds: [...publicIds(), ''] },
  })
}

function removePublicId(index: number) {
  if (!props.section.binding) return
  emit('update', {
    ...props.section,
    binding: {
      ...props.section.binding,
      publicIds: publicIds().filter((_, candidate) => candidate !== index),
    },
  })
}

function addBinding() {
  if (!spec.value) return
  emit('update', { ...props.section, binding: defaultBinding(spec.value) })
}

function removeBinding() {
  const next = { ...props.section }
  delete next.binding
  emit('update', next)
}
</script>

<template>
  <section aria-labelledby="section-settings-heading">
    <h2 id="section-settings-heading">{{ definition.label }} settings</h2>
    <p class="field-help">
      Block <code>{{ section.type }}</code> · section id <code>{{ section.id }}</code>
    </p>
    <ul v-if="errors.length > 0" class="section-errors" role="alert">
      <li v-for="error in errors" :key="error">{{ error }}</li>
    </ul>
    <SectionFieldEditor
      :fields="definition.settings"
      :values="section.settings"
      :path="`section-${section.id}`"
      :disabled="!canWrite"
      @update="setSettings"
    />
    <fieldset v-if="spec">
      <legend>Displayed data{{ spec.required ? ' (required)' : '' }}</legend>
      <template v-if="section.binding">
        <div class="setup-field">
          <label :for="`binding-kind-${section.id}`">Data kind</label>
          <select
            :id="`binding-kind-${section.id}`"
            :value="section.binding.kind"
            :disabled="!canWrite"
            @change="setKind(($event.target as HTMLSelectElement).value)"
          >
            <option v-for="kind in spec.kinds" :key="kind" :value="kind">{{ kind }}</option>
          </select>
        </div>
        <div v-if="isSingle" class="setup-field">
          <label :for="`binding-ref-${section.id}`">
            {{ fieldLabel(section.binding.kind) }} reference (public id)
          </label>
          <input
            :id="`binding-ref-${section.id}`"
            type="text"
            :value="section.binding.publicId ?? ''"
            :disabled="!canWrite"
            @input="setPublicId(($event.target as HTMLInputElement).value)"
          />
        </div>
        <div v-else>
          <div v-for="(publicId, index) in publicIds()" :key="index" class="setup-field">
            <label :for="`binding-ref-${section.id}-${index}`">
              {{ fieldLabel(section.binding.kind) }} reference {{ index + 1 }} (public id)
            </label>
            <input
              :id="`binding-ref-${section.id}-${index}`"
              type="text"
              :value="publicId"
              :disabled="!canWrite"
              @input="setPublicIdAt(index, ($event.target as HTMLInputElement).value)"
            />
            <button type="button" :disabled="!canWrite" @click="removePublicId(index)">
              Remove reference
            </button>
          </div>
          <button
            type="button"
            :disabled="!canWrite || publicIds().length >= spec.maxItems"
            @click="addPublicId"
          >
            Add reference
          </button>
        </div>
        <button v-if="!spec.required" type="button" :disabled="!canWrite" @click="removeBinding">
          Remove binding
        </button>
      </template>
      <button v-else type="button" :disabled="!canWrite" @click="addBinding">Add binding</button>
    </fieldset>
  </section>
</template>
