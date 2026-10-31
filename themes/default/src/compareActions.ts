import { createApp } from 'vue'
import ProductActions from './ProductActions.vue'
import { parseProductActions } from './compareData'

for (const outlet of document.querySelectorAll('[data-compare-actions]')) {
  if (!(outlet instanceof HTMLElement)) continue
  const raw = outlet.dataset.compare
  if (!raw) continue
  const input = parseProductActions(raw)
  if (input) createApp(ProductActions, { ...input }).mount(outlet)
}
