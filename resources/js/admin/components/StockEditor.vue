<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'StockEditor' })

type StaffVariant = {
  id: string
  sku: string
  tracks_inventory?: boolean
  on_hand?: number | null
  reserved?: number
  available_quantity?: number | null
  low_stock_threshold?: number | null
  low_stock?: boolean
}

const props = defineProps<{
  productId: string
  variants: StaffVariant[]
}>()
const errors = ref<HTMLElement | null>(null)
const selected = ref(props.variants[0]?.id ?? '')
function current(): StaffVariant | undefined {
  return props.variants.find((variant) => variant.id === selected.value) ?? props.variants[0]
}
const form = useForm({
  variant_id: selected.value,
  operation_key: crypto.randomUUID(),
  tracks_inventory: current()?.tracks_inventory ?? false,
  on_hand: current()?.on_hand ?? 0,
  low_stock_threshold: current()?.low_stock_threshold ?? 0,
})
function selectVariant(id: string) {
  selected.value = id
  const variant = current()
  form.variant_id = id
  form.tracks_inventory = variant?.tracks_inventory ?? false
  form.on_hand = variant?.on_hand ?? 0
  form.low_stock_threshold = variant?.low_stock_threshold ?? 0
  form.operation_key = crypto.randomUUID()
}
function submit() {
  form.operation_key = crypto.randomUUID()
  form.patch(`/admin/products/${props.productId}/inventory`, {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
</script>

<template>
  <section class="stock-editor" aria-labelledby="stock-editor-heading">
    <h2 id="stock-editor-heading">Stock</h2>
    <p class="lede">
      Tracked on-hand stock cannot fall below reserved units. Low-stock uses remaining available
      units.
    </p>
    <p v-if="current()?.low_stock" role="status" class="workspace-note">Low stock</p>
    <form class="setup-form" @submit.prevent="submit">
      <div
        v-if="Object.keys(form.errors).length"
        ref="errors"
        role="alert"
        tabindex="-1"
        class="error-summary"
      >
        <p v-for="(message, field) in form.errors" :key="field">{{ message }}</p>
      </div>
      <div class="setup-field">
        <label for="stock-variant">Variant</label>
        <select
          id="stock-variant"
          :value="selected"
          @change="selectVariant(($event.target as HTMLSelectElement).value)"
        >
          <option v-for="variant in variants" :key="variant.id" :value="variant.id">
            {{ variant.sku }}
          </option>
        </select>
      </div>
      <label class="category-option">
        <input v-model="form.tracks_inventory" type="checkbox" />
        Track inventory
      </label>
      <div v-if="form.tracks_inventory" class="setup-grid">
        <div class="setup-field">
          <label for="stock-on-hand">On-hand quantity</label>
          <input
            id="stock-on-hand"
            v-model.number="form.on_hand"
            type="number"
            min="0"
            step="1"
            required
          />
        </div>
        <div class="setup-field">
          <label for="stock-threshold">Low-stock threshold</label>
          <input
            id="stock-threshold"
            v-model.number="form.low_stock_threshold"
            type="number"
            min="0"
            step="1"
          />
        </div>
      </div>
      <p v-if="current()?.tracks_inventory" class="field-help">
        Reserved {{ current()?.reserved ?? 0 }}. Available {{ current()?.available_quantity ?? 0 }}.
      </p>
      <button type="submit" class="primary-button" :disabled="form.processing">
        {{ form.processing ? 'Saving…' : 'Save stock' }}
      </button>
    </form>
  </section>
</template>
