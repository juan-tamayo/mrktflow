import { createApp } from 'vue'
import VariantPicker from './VariantPicker.vue'
import { parseVariantPicker } from './variantData'

const outlet = document.getElementById('variant-picker')
if (outlet?.dataset.variants) {
  let payload: unknown = null
  try {
    payload = JSON.parse(outlet.dataset.variants)
  } catch {
    /* Leave the native form intact. */
  }
  const input = parseVariantPicker(payload)
  if (input)
    createApp(VariantPicker, { variants: input.variants, selectedId: input.selectedId }).mount(
      outlet,
    )
}
