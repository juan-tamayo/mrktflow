<script setup lang="ts">
import { computed, ref, useId } from 'vue'

const props = defineProps<{ options: { code: string; label: string }[] }>()
const selected = defineModel<string[]>({ required: true })
const search = ref('')
const id = useId()
const choices = computed(() => {
  const query = search.value.trim().toLocaleLowerCase()
  return props.options.filter((option) =>
    `${option.label} ${option.code}`.toLocaleLowerCase().includes(query),
  )
})
const selection = computed(() =>
  props.options
    .filter((option) => selected.value.includes(option.code))
    .map((option) => option.label)
    .join(', '),
)
</script>

<template>
  <div class="country-picker">
    <label :for="`${id}-search`">Find a country</label>
    <input
      :id="`${id}-search`"
      v-model="search"
      type="search"
      placeholder="Search by country name"
    />
    <div class="country-choices" role="group" aria-label="Countries you sell to">
      <label v-for="country in choices" :key="country.code" class="country-choice">
        <input v-model="selected" type="checkbox" :value="country.code" />
        <span>{{ country.label }}</span>
      </label>
      <p v-if="choices.length === 0" class="field-help">No countries match your search.</p>
    </div>
    <p class="field-help" role="status">
      {{
        selected.length === 0
          ? 'No countries selected. Choose at least one.'
          : `Selected: ${selection}`
      }}
    </p>
  </div>
</template>
