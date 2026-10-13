<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import type { OptionAxis, PublicVariant } from './variantData'
const props = defineProps<{
  variants: PublicVariant[]
  selectedId: string
  optionAxes?: OptionAxis[]
  selectionState?: 'selected' | 'missing' | 'unavailable' | 'out_of_stock'
}>()
const selectedId = ref(props.selectionState === 'missing' ? '' : props.selectedId)
const selections = ref<Record<string, string>>({})
const axes = computed(() => props.optionAxes ?? [])
function variantFor(id: string): PublicVariant | undefined {
  return props.variants.find((variant) => variant.id === id)
}
function matchCombination(current: Record<string, string>): PublicVariant | undefined {
  return props.variants.find((variant) =>
    axes.value.every((axis) =>
      variant.options.some(
        (option) => option.name === axis.name && option.value === current[axis.name],
      ),
    ),
  )
}
const selected = computed(() =>
  selectedId.value === '' ? undefined : variantFor(selectedId.value),
)
const status = computed(() => {
  if (axes.value.length > 0) {
    const complete = axes.value.every((axis) => (selections.value[axis.name] ?? '') !== '')
    if (!complete) return 'missing' as const
    const match = matchCombination(selections.value)
    if (match === undefined) return 'unavailable' as const
    if (match.available === false) {
      return match.availability === 'out_of_stock'
        ? ('out_of_stock' as const)
        : ('unavailable' as const)
    }
    return 'selected' as const
  }
  if (selectedId.value === '') return 'missing' as const
  if (selected.value?.available === false) {
    return selected.value.availability === 'out_of_stock'
      ? ('out_of_stock' as const)
      : ('unavailable' as const)
  }
  return 'selected' as const
})
watch(
  selected,
  (variant) => {
    const image = document.getElementById('product-hero')
    if (image instanceof HTMLImageElement && variant?.image && status.value === 'selected') {
      image.src = variant.image.url
      image.alt = variant.image.alt
    }
  },
  { immediate: true },
)
watch(
  () => props.selectedId,
  (id) => {
    if (props.selectionState === 'missing') {
      selectedId.value = ''
      selections.value = {}
      return
    }
    const current = variantFor(id)
    if (current === undefined) return
    const next: Record<string, string> = {}
    for (const option of current.options ?? []) next[option.name] = option.value
    selections.value = next
    selectedId.value = id
  },
  { immediate: true },
)
function chooseAxis(name: string, value: string) {
  selections.value = { ...selections.value, [name]: value }
  const match = matchCombination(selections.value)
  selectedId.value = match?.id ?? ''
}
</script>

<template>
  <div class="theme-variant-form">
    <template v-if="axes.length">
      <div v-for="axis in axes" :key="axis.name" class="setup-field">
        <label :for="`enhanced-${axis.name}`">{{ axis.name }}</label>
        <select
          :id="`enhanced-${axis.name}`"
          :value="selections[axis.name] ?? ''"
          @change="chooseAxis(axis.name, ($event.target as HTMLSelectElement).value)"
        >
          <option value="">Choose {{ axis.name.toLowerCase() }}</option>
          <option v-for="value in axis.values" :key="value" :value="value">{{ value }}</option>
        </select>
      </div>
    </template>
    <div v-else class="setup-field">
      <label for="enhanced-variant">Finish</label>
      <select id="enhanced-variant" v-model="selectedId">
        <option v-for="variant in variants" :key="variant.id" :value="variant.id">
          {{ variant.label }}
        </option>
      </select>
    </div>
    <p v-if="status === 'missing'" class="theme-variant-prompt" role="status">
      Choose a finish and size to see the current price.
    </p>
    <p v-else-if="status === 'unavailable'" class="theme-variant-prompt" role="status">
      This combination is unavailable.
    </p>
    <p v-else-if="status === 'out_of_stock'" class="theme-variant-prompt" role="status">
      {{ axes.length ? 'This combination is out of stock.' : 'This product is out of stock.' }}
    </p>
    <p v-else class="theme-price" role="status" aria-live="polite" aria-atomic="true">
      <span>{{ optionAxes?.length ? 'Price' : 'Illustrative price' }}</span
      ><strong v-if="selected">{{ selected.money.formatted }}</strong>
    </p>
    <p v-if="!optionAxes?.length" class="field-help">The price updates when you choose a finish.</p>
  </div>
</template>
