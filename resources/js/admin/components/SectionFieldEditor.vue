<script setup lang="ts">
import { defaultSettings, fieldLabel, type SectionFieldDefinition } from '../section-editor'
import SectionFieldEditor from './SectionFieldEditor.vue'

const props = defineProps<{
  fields: SectionFieldDefinition[]
  values: Record<string, unknown>
  path: string
  disabled: boolean
}>()

const emit = defineEmits<{
  update: [values: Record<string, unknown>]
}>()

function set(key: string, value: unknown) {
  emit('update', { ...props.values, [key]: value })
}

function asRecord(value: unknown): Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : {}
}

function asItems(value: unknown): Record<string, unknown>[] {
  return Array.isArray(value) ? (value as Record<string, unknown>[]) : []
}

function setItem(field: SectionFieldDefinition, index: number, item: Record<string, unknown>) {
  const items = [...asItems(props.values[field.key])]
  items[index] = item
  set(field.key, items)
}

function addItem(field: SectionFieldDefinition) {
  set(field.key, [...asItems(props.values[field.key]), defaultSettings(field.itemFields ?? [])])
}

function removeItem(field: SectionFieldDefinition, index: number) {
  set(
    field.key,
    asItems(props.values[field.key]).filter((_, candidate) => candidate !== index),
  )
}

function numberValue(value: unknown): string {
  return typeof value === 'number' ? String(value) : ''
}

function setNumber(key: string, raw: string) {
  set(key, raw === '' ? '' : Number(raw))
}
</script>

<template>
  <div>
    <div v-for="field in fields" :key="field.key" class="setup-field">
      <template v-if="field.type === 'text'">
        <label :for="`${path}-${field.key}`"
          >{{ fieldLabel(field.key) }}{{ field.required ? ' (required)' : '' }}</label
        >
        <input
          :id="`${path}-${field.key}`"
          type="text"
          :maxlength="field.maxLength"
          :value="typeof values[field.key] === 'string' ? values[field.key] : ''"
          :disabled="disabled"
          @input="set(field.key, ($event.target as HTMLInputElement).value)"
        />
      </template>
      <template v-else-if="field.type === 'path'">
        <label :for="`${path}-${field.key}`"
          >{{ fieldLabel(field.key) }}{{ field.required ? ' (required)' : '' }}</label
        >
        <input
          :id="`${path}-${field.key}`"
          type="text"
          placeholder="/local/path"
          :value="typeof values[field.key] === 'string' ? values[field.key] : ''"
          :disabled="disabled"
          @input="set(field.key, ($event.target as HTMLInputElement).value)"
        />
      </template>
      <template v-else-if="field.type === 'integer'">
        <label :for="`${path}-${field.key}`"
          >{{ fieldLabel(field.key) }}{{ field.required ? ' (required)' : '' }}</label
        >
        <input
          :id="`${path}-${field.key}`"
          type="number"
          :min="field.min"
          :max="field.max"
          :value="numberValue(values[field.key])"
          :disabled="disabled"
          @input="setNumber(field.key, ($event.target as HTMLInputElement).value)"
        />
      </template>
      <template v-else-if="field.type === 'boolean'">
        <label :for="`${path}-${field.key}`">
          <input
            :id="`${path}-${field.key}`"
            type="checkbox"
            :checked="values[field.key] === true"
            :disabled="disabled"
            @change="set(field.key, ($event.target as HTMLInputElement).checked)"
          />
          {{ fieldLabel(field.key) }}
        </label>
      </template>
      <template v-else-if="field.type === 'enum'">
        <label :for="`${path}-${field.key}`"
          >{{ fieldLabel(field.key) }}{{ field.required ? ' (required)' : '' }}</label
        >
        <select
          :id="`${path}-${field.key}`"
          :value="typeof values[field.key] === 'string' ? values[field.key] : ''"
          :disabled="disabled"
          @change="set(field.key, ($event.target as HTMLSelectElement).value)"
        >
          <option v-for="option in field.options ?? []" :key="option" :value="option">
            {{ option }}
          </option>
        </select>
      </template>
      <fieldset v-else-if="field.type === 'object'">
        <legend>{{ fieldLabel(field.key) }}{{ field.required ? ' (required)' : '' }}</legend>
        <SectionFieldEditor
          :fields="field.fields ?? []"
          :values="asRecord(values[field.key])"
          :path="`${path}-${field.key}`"
          :disabled="disabled"
          @update="set(field.key, $event)"
        />
      </fieldset>
      <fieldset v-else-if="field.type === 'list'">
        <legend>{{ fieldLabel(field.key) }}{{ field.required ? ' (required)' : '' }}</legend>
        <div
          v-for="(item, index) in asItems(values[field.key])"
          :key="index"
          class="section-list-item-editor"
        >
          <SectionFieldEditor
            :fields="field.itemFields ?? []"
            :values="item"
            :path="`${path}-${field.key}-${index}`"
            :disabled="disabled"
            @update="setItem(field, index, $event)"
          />
          <button
            type="button"
            :disabled="disabled || asItems(values[field.key]).length <= (field.minItems ?? 0)"
            :aria-label="`Remove ${fieldLabel(field.key)} entry ${index + 1}`"
            @click="removeItem(field, index)"
          >
            Remove entry
          </button>
        </div>
        <button
          type="button"
          :disabled="disabled || asItems(values[field.key]).length >= (field.maxItems ?? 0)"
          @click="addItem(field)"
        >
          Add entry
        </button>
      </fieldset>
    </div>
  </div>
</template>
