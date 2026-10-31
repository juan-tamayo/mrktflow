<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import {
  sharedCompareTrayStore,
  type CompareTrayStore,
  type ProductActionsInput,
} from './compareData'

const props = defineProps<ProductActionsInput & { store?: CompareTrayStore }>()

const store = props.store ?? sharedCompareTrayStore()
const selectId = useId()
const existing = computed(() => store.entryFor(props.productId))
const chosenVariantId = ref(
  (existing.value?.variantId &&
  props.variants.some((variant) => variant.id === existing.value?.variantId)
    ? existing.value.variantId
    : null) ??
    props.selectedId ??
    (props.variants.length === 1 ? props.variants[0]!.id : ''),
)
const needsChoice = computed(
  () => !existing.value && props.variants.length > 1 && chosenVariantId.value === '',
)
const status = ref('')

function toggle(): void {
  if (existing.value) {
    store.remove(props.productId)
    status.value = store.announcement.value
    return
  }
  if (props.variants.length > 0 && chosenVariantId.value === '') return
  const variant = props.variants.find((candidate) => candidate.id === chosenVariantId.value)
  store.add({
    productId: props.productId,
    variantId: props.variants.length === 0 ? null : chosenVariantId.value,
    title: props.title,
    variantLabel: variant?.label ?? 'Standard',
  })
  status.value = store.announcement.value
}
</script>

<template>
  <div class="theme-compare-actions">
    <div v-if="props.variants.length > 1" class="setup-field">
      <label :for="selectId">Variant</label>
      <select :id="selectId" v-model="chosenVariantId">
        <option value="">Choose a combination</option>
        <option v-for="variant in props.variants" :key="variant.id" :value="variant.id">
          {{ variant.label }}
        </option>
      </select>
    </div>
    <button
      type="button"
      class="text-link"
      :aria-pressed="existing ? 'true' : 'false'"
      :aria-label="
        existing ? `Remove ${props.title} from compare` : `Add ${props.title} to compare`
      "
      :disabled="needsChoice"
      @click="toggle"
    >
      {{ existing ? 'Remove from compare' : 'Compare' }}
    </button>
    <p v-if="status !== ''" class="theme-purchase-note" role="status">{{ status }}</p>
  </div>
</template>
