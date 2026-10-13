<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'VariantEditor' })

type Axis = { name: string; values: string[] }
type StaffVariant = {
  id?: string
  sku: string
  isDefault: boolean
  available: boolean
  options: { name: string; value: string }[]
  money: { amount: number }
}

const props = defineProps<{
  productId: string
  version: number
  optionAxes: Axis[]
  variants: StaffVariant[]
  slug: string
}>()
const errors = ref<HTMLElement | null>(null)
function optionMap(options: { name: string; value: string }[]): Record<string, string> {
  const mapped: Record<string, string> = {}
  for (const option of options) mapped[option.name] = option.value
  return mapped
}
const form = useForm({
  version: props.version,
  options: props.optionAxes.map((axis) => ({ name: axis.name, values: [...axis.values] })),
  variants: props.variants.map((variant) => ({
    id: variant.id,
    sku: variant.sku,
    price_amount: variant.money.amount,
    is_default: variant.isDefault,
    available: variant.available,
    options: optionMap(variant.options),
  })),
})
function addOption() {
  if (form.options.length >= 3) return
  form.options.push({ name: '', values: [''] })
}
function addValue(index: number) {
  if (form.options[index]!.values.length >= 20) return
  form.options[index]!.values.push('')
}
function addVariant() {
  if (form.variants.length >= 50) return
  const options: Record<string, string> = {}
  for (const axis of form.options) {
    if (axis.name !== '') options[axis.name] = axis.values[0] ?? ''
  }
  form.variants.push({
    id: undefined,
    sku: '',
    price_amount: 0,
    is_default: form.variants.length === 0,
    available: true,
    options,
  })
}
function createCombinations() {
  const axes = form.options.filter(
    (axis) => axis.name !== '' && axis.values.some((value) => value !== ''),
  )
  if (axes.length === 0) return
  const combos: Record<string, string>[] = [{}]
  for (const axis of axes) {
    const values = axis.values.filter((value) => value !== '')
    const next: Record<string, string>[] = []
    for (const combo of combos) {
      for (const value of values) next.push({ ...combo, [axis.name]: value })
    }
    combos.splice(0, combos.length, ...next)
  }
  form.variants = combos.map((options, index) => ({
    id: undefined,
    sku: `${props.slug}-${Object.values(options).join('-')}`
      .toUpperCase()
      .replace(/[^A-Z0-9._-]/g, '-'),
    price_amount: form.variants[0]?.price_amount ?? 0,
    is_default: index === 0,
    available: true,
    options,
  }))
}
function setDefault(index: number) {
  form.variants = form.variants.map((variant, current) => ({
    ...variant,
    is_default: current === index,
  }))
}
function submit() {
  form.options = form.options
    .filter((axis) => axis.name !== '')
    .map((axis) => ({ name: axis.name, values: axis.values.filter((value) => value !== '') }))
  form.put(`/admin/products/${props.productId}/variants`, {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
</script>

<template>
  <section class="variant-editor" aria-labelledby="variant-editor-heading">
    <h2 id="variant-editor-heading">Variants</h2>
    <p class="lede">
      Option combinations must be unique. Shoppers see the selected price and image. Unavailable
      combinations cannot be bought.
    </p>
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
      <fieldset v-for="(axis, axisIndex) in form.options" :key="axisIndex" class="setup-field">
        <legend>Option {{ axisIndex + 1 }}</legend>
        <label :for="`option-name-${axisIndex}`">Name</label>
        <input :id="`option-name-${axisIndex}`" v-model="axis.name" maxlength="40" />
        <label v-for="(_, valueIndex) in axis.values" :key="valueIndex" class="variant-value">
          Value
          <input v-model="axis.values[valueIndex]" maxlength="40" />
        </label>
        <button type="button" class="quiet-button" @click="addValue(axisIndex)">Add value</button>
      </fieldset>
      <button
        type="button"
        class="quiet-button"
        :disabled="form.options.length >= 3"
        @click="addOption"
      >
        Add option
      </button>
      <button type="button" class="quiet-button" @click="createCombinations">
        Create combinations
      </button>
      <fieldset
        v-for="(variant, variantIndex) in form.variants"
        :key="variantIndex"
        class="setup-field"
      >
        <legend>Variant {{ variantIndex + 1 }}</legend>
        <label :for="`variant-sku-${variantIndex}`">SKU</label>
        <input :id="`variant-sku-${variantIndex}`" v-model="variant.sku" maxlength="64" required />
        <label :for="`variant-price-${variantIndex}`">Price in the smallest currency unit</label>
        <input
          :id="`variant-price-${variantIndex}`"
          v-model.number="variant.price_amount"
          type="number"
          min="0"
          step="1"
          required
        />
        <label v-for="axis in form.options.filter((item) => item.name !== '')" :key="axis.name">
          {{ axis.name }}
          <select v-model="variant.options[axis.name]">
            <option
              v-for="value in axis.values.filter((item) => item !== '')"
              :key="value"
              :value="value"
            >
              {{ value }}
            </option>
          </select>
        </label>
        <label class="category-option">
          <input
            type="radio"
            name="default-variant"
            :checked="variant.is_default"
            @change="setDefault(variantIndex)"
          />
          Default
        </label>
        <label class="category-option">
          <input v-model="variant.available" type="checkbox" />
          Available
        </label>
      </fieldset>
      <button type="button" class="quiet-button" @click="addVariant">Add variant</button>
      <button type="submit" class="primary-button" :disabled="form.processing">
        {{ form.processing ? 'Saving…' : 'Save variants' }}
      </button>
    </form>
  </section>
</template>
