import { createApp } from 'vue'
import SpecSheet from './SpecSheet.vue'
import { parseSpecSheet } from './specSheet'

const outlet = document.getElementById('example-spec-sheet')
if (outlet?.dataset.specSheet) {
  let payload: unknown = null
  try {
    payload = JSON.parse(outlet.dataset.specSheet)
  } catch {
    /* Leave the native specification list intact. */
  }
  const input = parseSpecSheet(payload)
  if (input)
    createApp(SpecSheet, { title: input.title, attributes: input.attributes }).mount(outlet)
}
