import { createApp } from 'vue'
import CatalogFilters from './CatalogFilters.vue'
import { parseCatalogFilters } from './catalogFilters'

const outlet = document.getElementById('catalog-filters')
if (outlet?.dataset.search) {
  let payload: unknown = null
  try {
    payload = JSON.parse(outlet.dataset.search)
  } catch {
    /* Leave the native form intact. */
  }
  const input = parseCatalogFilters(payload)
  if (input)
    createApp(CatalogFilters, {
      query: input.query,
      sorts: input.sorts,
      categories: input.categories,
      facets: input.facets,
    }).mount(outlet)
}
