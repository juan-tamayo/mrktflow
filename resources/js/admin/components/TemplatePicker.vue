<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

defineOptions({ name: 'TemplatePicker' })

const props = defineProps<{
  subjectType: 'product' | 'category' | 'page'
  subjectId: string
  subjectLabel: string
  templateKey: string
  options: { key: string; label: string }[]
  canAssign: boolean
  saved: boolean
}>()

const selectId = computed(() => `${props.subjectType}-visual-template-${props.subjectId}`)
const fieldLabel = computed(() => `Visual template for ${props.subjectLabel}`)
const assignLabel = computed(() => `Assign visual template for ${props.subjectLabel}`)
const form = useForm({
  subject_type: props.subjectType,
  subject_id: props.subjectId,
  template_key: props.templateKey,
})

function assign() {
  form.put('/admin/layouts', { preserveScroll: true })
}
</script>

<template>
  <form class="setup-form" @submit.prevent="assign">
    <p
      v-if="saved"
      role="status"
      class="success-notice"
      aria-label="Visual template assignment result"
    >
      Visual template assigned.
    </p>
    <div class="setup-field">
      <label :for="selectId">{{ fieldLabel }}</label>
      <select
        :id="selectId"
        v-model="form.template_key"
        name="template_key"
        :disabled="!canAssign || form.processing"
      >
        <option v-for="option in options" :key="option.key" :value="option.key">
          {{ option.label }}
        </option>
      </select>
      <p class="field-help">
        Layout is independent of product schemas. Live prices stay on the catalog.
      </p>
    </div>
    <button
      v-if="canAssign"
      type="submit"
      class="quiet-button"
      :aria-label="assignLabel"
      :disabled="form.processing"
    >
      {{ form.processing ? 'Assigning…' : 'Assign layout' }}
    </button>
  </form>
</template>
