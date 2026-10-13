<script setup lang="ts">
export type SchemaField = {
  key: string
  type: string
  unit?: string | null
  required: boolean
  comparable: boolean
  filterable: boolean
  label: string
  options?: string[]
}

const props = defineProps<{
  mode: 'definition' | 'values'
  fields?: SchemaField[]
}>()
const attributes = defineModel<SchemaField[]>('attributes', { default: () => [] })
const values = defineModel<Record<string, string | boolean>>('values', { default: () => ({}) })

function addField() {
  attributes.value = [
    ...attributes.value,
    {
      key: '',
      type: 'string',
      unit: '',
      required: true,
      comparable: true,
      filterable: true,
      label: '',
      options: [],
    },
  ]
}
function removeField(index: number) {
  attributes.value = attributes.value.filter((_, current) => current !== index)
}
function addOption(index: number) {
  const next = [...attributes.value]
  const field = next[index]
  if (field === undefined) {
    return
  }
  next[index] = { ...field, options: [...(field.options ?? []), ''] }
  attributes.value = next
}
</script>

<template>
  <div class="attribute-fields">
    <template v-if="mode === 'definition'">
      <fieldset v-for="(field, index) in attributes" :key="index" class="setup-field">
        <legend>Attribute {{ index + 1 }}</legend>
        <label :for="`attribute-label-${index}`">Label</label>
        <input :id="`attribute-label-${index}`" v-model="field.label" maxlength="120" required />
        <label :for="`attribute-key-${index}`">Key</label>
        <input :id="`attribute-key-${index}`" v-model="field.key" maxlength="80" required />
        <label :for="`attribute-type-${index}`">Type</label>
        <select :id="`attribute-type-${index}`" v-model="field.type">
          <option value="string">Text</option>
          <option value="integer">Whole number</option>
          <option value="decimal">Decimal</option>
          <option value="boolean">Yes / no</option>
          <option value="date">Date</option>
          <option value="enum">Choice list</option>
        </select>
        <label :for="`attribute-unit-${index}`">Unit</label>
        <input :id="`attribute-unit-${index}`" v-model="field.unit" maxlength="16" />
        <label>
          <input v-model="field.required" type="checkbox" />
          Required
        </label>
        <label>
          <input v-model="field.comparable" type="checkbox" />
          Comparable
        </label>
        <label>
          <input v-model="field.filterable" type="checkbox" />
          Filterable
        </label>
        <template v-if="field.type === 'enum'">
          <label
            v-for="(_, optionIndex) in field.options ?? []"
            :key="optionIndex"
            :for="`attribute-option-${index}-${optionIndex}`"
          >
            Choice {{ optionIndex + 1 }}
            <input
              :id="`attribute-option-${index}-${optionIndex}`"
              v-model="field.options![optionIndex]"
              maxlength="40"
              required
            />
          </label>
          <button type="button" class="quiet-button" @click="addOption(index)">Add choice</button>
        </template>
        <button type="button" class="quiet-button" @click="removeField(index)">
          Remove attribute
        </button>
      </fieldset>
      <button type="button" class="quiet-button" @click="addField">Add attribute</button>
    </template>
    <template v-else>
      <div v-for="field in props.fields ?? []" :key="field.key" class="setup-field">
        <label :for="`value-${field.key}`">{{ field.label }}</label>
        <select
          v-if="field.type === 'enum'"
          :id="`value-${field.key}`"
          :value="String(values[field.key] ?? '')"
          :required="field.required"
          @change="values = { ...values, [field.key]: ($event.target as HTMLSelectElement).value }"
        >
          <option value="">Choose</option>
          <option v-for="option in field.options ?? []" :key="option" :value="option">
            {{ option }}
          </option>
        </select>
        <select
          v-else-if="field.type === 'boolean'"
          :id="`value-${field.key}`"
          :value="
            values[field.key] === true || values[field.key] === '1'
              ? '1'
              : values[field.key] === false || values[field.key] === '0'
                ? '0'
                : ''
          "
          :required="field.required"
          @change="values = { ...values, [field.key]: ($event.target as HTMLSelectElement).value }"
        >
          <option value="">Choose</option>
          <option value="1">Yes</option>
          <option value="0">No</option>
        </select>
        <input
          v-else
          :id="`value-${field.key}`"
          :value="String(values[field.key] ?? '')"
          :required="field.required"
          :type="field.type === 'date' ? 'date' : 'text'"
          @input="values = { ...values, [field.key]: ($event.target as HTMLInputElement).value }"
        />
      </div>
    </template>
  </div>
</template>
