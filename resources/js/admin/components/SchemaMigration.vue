<script setup lang="ts">
export type MigrationPreview = {
  affected_products: number
  can_apply: boolean
  compatible: boolean
  invalid_values: { product_id: string; title: string; field: string; message: string }[]
  comparison_changes: { key: string; change: string; comparable: boolean; filterable: boolean }[]
  incompatible_fields: { key: string; message: string }[]
}

defineProps<{
  assignedCount: number
  preview: MigrationPreview | null
  processing: boolean
}>()
defineEmits<{
  preview: []
  apply: []
}>()
</script>

<template>
  <section class="schema-migration" aria-labelledby="schema-migration-heading">
    <h2 id="schema-migration-heading">Schema migration</h2>
    <p>
      {{ assignedCount }} assigned
      {{ assignedCount === 1 ? 'product keeps' : 'products keep' }}
      current values until a migration succeeds.
    </p>
    <div v-if="preview" class="workspace-note">
      <p>Affected products: {{ preview.affected_products }}</p>
      <p v-if="preview.comparison_changes.length">
        Comparison and filter changes:
        <span v-for="change in preview.comparison_changes" :key="change.key">
          {{ change.key }} ({{ change.change }}{{ change.comparable ? ', comparable' : ''
          }}{{ change.filterable ? ', filterable' : '' }})
        </span>
      </p>
      <p v-if="preview.incompatible_fields.length">Incompatible type or comparison changes:</p>
      <ul v-if="preview.incompatible_fields.length">
        <li v-for="field in preview.incompatible_fields" :key="field.key">
          {{ field.key }}: {{ field.message }}
        </li>
      </ul>
      <p v-if="preview.invalid_values.length">Invalid existing values:</p>
      <ul v-if="preview.invalid_values.length">
        <li v-for="row in preview.invalid_values" :key="`${row.product_id}-${row.field}`">
          {{ row.title }} · {{ row.field }}: {{ row.message }}
        </li>
      </ul>
      <p v-else-if="preview.can_apply">Existing values stay valid for this version.</p>
    </div>
    <div class="setup-grid">
      <button type="button" class="text-link" :disabled="processing" @click="$emit('preview')">
        {{ processing ? 'Working…' : 'Preview migration' }}
      </button>
      <button
        type="button"
        class="primary-button"
        :disabled="processing || preview === null || !preview.can_apply"
        @click="$emit('apply')"
      >
        Apply migration
      </button>
    </div>
  </section>
</template>
