<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'
import { computed } from 'vue'

defineOptions({ name: 'ProductAiPanel' })

export type ApplyDocument = {
  schemaVersion?: string
  kind?: string
  productId?: string
  title?: string
  description?: string
  key?: string
  label?: string
  comparison_group?: string
  default_template_key?: string
  attributes?: {
    key: string
    type: string
    unit?: string
    required?: boolean
    comparable?: boolean
    filterable?: boolean
    label?: string
    options?: string[]
  }[]
}

export type ProductAiReview = {
  runId: string
  draftId: string
  status: string
  operation: string
  canApply: boolean
  expectedVersion: number
  candidate: ApplyDocument
  base: { title: string; description: string }
  usage: {
    input_tokens: number | null
    output_tokens: number | null
    total_tokens: number | null
    known: boolean
  }
  warnings: string[]
  conflicts: { key: string; message: string }[]
  migration: {
    affected_products: number
    can_apply: boolean
    compatible: boolean
    invalid_values: { product_id: string; title: string; field: string; message: string }[]
    comparison_changes: { key: string; change: string; comparable: boolean; filterable: boolean }[]
    incompatible_fields: { key: string; message: string }[]
  } | null
}

const props = defineProps<{
  productId: string
  review: ProductAiReview | null
}>()

const generate = useForm({
  prompt: '',
  locale: 'en-US',
  max_output_tokens: 1024,
})
const applyForm = useForm({
  expected_version: props.review?.expectedVersion ?? 1,
  document: props.review?.candidate ?? {},
})

const usageLabel = computed(() => {
  const usage = props.review?.usage
  if (usage === undefined || !usage.known || usage.total_tokens === null) {
    return 'Usage is still pending or unknown.'
  }
  return `${usage.input_tokens ?? 0} input · ${usage.output_tokens ?? 0} output · ${usage.total_tokens} total tokens`
})

function generateCopy() {
  generate.post(`/admin/products/${props.productId}/ai/copy`, { preserveScroll: true })
}
function generateSchema() {
  generate.post(`/admin/products/${props.productId}/ai/schema`, { preserveScroll: true })
}
function applySuggestion() {
  if (props.review === null) {
    return
  }
  applyForm.expected_version = props.review.expectedVersion
  applyForm.document = props.review.candidate
  applyForm.post(`/admin/products/${props.productId}/ai/${props.review.runId}/apply`, {
    preserveScroll: true,
  })
}
</script>

<template>
  <section class="product-ai-panel" aria-labelledby="product-ai-heading">
    <h2 id="product-ai-heading">AI suggestions</h2>
    <p class="workspace-note">
      Price and stock stay merchant-authored core fields. Review semantic-key conflicts and
      migrations before applying a schema suggestion.
    </p>
    <form class="setup-form" @submit.prevent="generateCopy">
      <div class="setup-field">
        <label for="product-ai-prompt">Prompt</label>
        <textarea
          id="product-ai-prompt"
          v-model="generate.prompt"
          name="prompt"
          rows="3"
          maxlength="4000"
          required
        />
      </div>
      <div class="setup-grid">
        <button type="submit" class="quiet-button" :disabled="generate.processing">
          {{ generate.processing ? 'Generating…' : 'Draft product copy' }}
        </button>
        <button
          type="button"
          class="quiet-button"
          :disabled="generate.processing"
          @click="generateSchema"
        >
          Suggest product schema
        </button>
      </div>
    </form>
    <template v-if="review">
      <p role="status">{{ usageLabel }}</p>
      <p v-for="warning in review.warnings" :key="warning" class="workspace-note">{{ warning }}</p>
      <template v-if="review.operation === 'draft_product_copy'">
        <h3>Proposed copy</h3>
        <p>
          <strong>Title.</strong>
          {{ String(review.candidate.title ?? '') }}
        </p>
        <p>
          <strong>Description.</strong>
          {{ String(review.candidate.description ?? '') }}
        </p>
      </template>
      <template v-else>
        <h3>Proposed schema</h3>
        <pre>{{ JSON.stringify(review.candidate.attributes ?? [], null, 2) }}</pre>
        <div v-if="review.conflicts.length" role="alert">
          <p>Semantic-key conflicts</p>
          <ul>
            <li v-for="conflict in review.conflicts" :key="conflict.key">
              {{ conflict.key }}: {{ conflict.message }}
            </li>
          </ul>
        </div>
        <div v-if="review.migration" class="workspace-note">
          <p>Affected products: {{ review.migration.affected_products }}</p>
          <p v-if="!review.migration.can_apply">
            Migration cannot apply until conflicts are resolved.
          </p>
        </div>
      </template>
      <button
        type="button"
        class="primary-button"
        :disabled="!review.canApply || applyForm.processing"
        @click="applySuggestion"
      >
        {{ applyForm.processing ? 'Applying…' : 'Apply suggestion' }}
      </button>
    </template>
  </section>
</template>
