import { createApp } from 'vue'
import ComparisonTable from './ComparisonTable.vue'
import { parseComparisonTable } from './compareData'

const outlet = document.getElementById('comparison-table')
if (outlet) {
  const input = parseComparisonTable(outlet.dataset)
  if (input) createApp(ComparisonTable, { ...input }).mount(outlet)
}
