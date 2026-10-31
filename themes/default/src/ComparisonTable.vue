<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import {
  parseComparison,
  sharedCompareTrayStore,
  visibleComparisonRows,
  type CompareCandidate,
  type CompareTrayStore,
  type Comparison,
  type ComparisonSelection,
} from './compareData'

export interface CompareFetcher {
  (url: string): Promise<{ ok: boolean; json: () => Promise<unknown> }>
}

const props = defineProps<{
  comparison: Comparison
  candidates: CompareCandidate[]
  endpoint: string
  csrfToken: string
  cartRevision: number
  store?: CompareTrayStore
  fetcher?: CompareFetcher
  navigate?: (url: string) => void
}>()

const store = props.store ?? sharedCompareTrayStore()
const fetcher: CompareFetcher =
  props.fetcher ?? ((url: string) => fetch(url, { headers: { Accept: 'application/json' } }))
const navigate =
  props.navigate ??
  ((url: string) => {
    window.location.assign(url)
  })

const current = ref<Comparison | null>(props.comparison)
const remainingSingle = ref<ComparisonSelection | null>(null)
const differencesOnly = ref(false)
const status = ref('')
const busy = ref(false)
const replaceChoices = ref<Record<string, string>>({})
const root = ref<HTMLElement | null>(null)

store.syncSelections(props.comparison.selections)

const visibleRows = computed(() =>
  current.value ? visibleComparisonRows(current.value, differencesOnly.value) : [],
)

function candidatesFor(): CompareCandidate[] {
  if (!current.value) return []
  const compared = new Set(current.value.selections.map((entry) => entry.variant_id))
  return props.candidates.filter((candidate) => !compared.has(candidate.variant_id))
}

function availabilityLabel(availability: string): string {
  if (availability === 'in_stock') return 'In stock'
  if (availability === 'out_of_stock') return 'Out of stock'
  return 'Unavailable'
}

watch(differencesOnly, () => {
  if (!current.value) return
  status.value = differencesOnly.value
    ? `Showing ${visibleRows.value.length} of ${current.value.rows.length} details where these items differ.`
    : `Showing all ${current.value.rows.length} details.`
})

function focusRemoveAt(index: number): void {
  const buttons = root.value?.querySelectorAll<HTMLButtonElement>('button[data-remove]')
  const target = buttons?.[Math.min(index, buttons.length - 1)] ?? buttons?.[0]
  target?.focus()
}

async function refresh(variantIds: string[], message: string, focusIndex: number): Promise<void> {
  busy.value = true
  try {
    const query = variantIds.map((id) => `items[]=${encodeURIComponent(id)}`).join('&')
    const response = await fetcher(`${props.endpoint}?${query}`)
    if (!response.ok) throw new Error('Comparison update failed.')
    const parsed = parseComparison(await response.json())
    if (!parsed) throw new Error('Comparison update was not readable.')
    current.value = parsed
    remainingSingle.value = null
    store.syncSelections(parsed.selections)
    status.value = message
    await nextTick()
    focusRemoveAt(focusIndex)
  } catch {
    navigate(`/compare?${variantIds.map((id) => `items[]=${encodeURIComponent(id)}`).join('&')}`)
  } finally {
    busy.value = false
  }
}

async function removeColumn(selection: ComparisonSelection, index: number): Promise<void> {
  if (!current.value || busy.value) return
  const remaining = current.value.selections.filter(
    (entry) => entry.variant_id !== selection.variant_id,
  )
  if (remaining.length === 1) {
    remainingSingle.value = remaining[0]!
    current.value = null
    store.syncSelections([remaining[0]!])
    status.value = `${selection.title} removed from the comparison.`
    await nextTick()
    root.value?.querySelector<HTMLElement>('[data-remaining]')?.focus()
    return
  }
  await refresh(
    remaining.map((entry) => entry.variant_id),
    `${selection.title} removed from the comparison. ${remaining.length} items compared.`,
    index,
  )
}

async function replaceColumn(selection: ComparisonSelection, index: number): Promise<void> {
  if (!current.value || busy.value) return
  const candidateId = replaceChoices.value[selection.variant_id]
  if (!candidateId) return
  const candidate = props.candidates.find((entry) => entry.variant_id === candidateId)
  const variantIds = current.value.selections.map((entry) =>
    entry.variant_id === selection.variant_id ? candidateId : entry.variant_id,
  )
  await refresh(
    variantIds,
    `${selection.title} replaced with ${candidate?.title ?? 'another item'}.`,
    index,
  )
}
</script>

<template>
  <div ref="root" class="theme-compare-table">
    <p v-if="status !== ''" class="theme-purchase-note" role="status">{{ status }}</p>
    <template v-if="current">
      <p v-for="entry in current.removed" :key="entry.selection" class="theme-purchase-note">
        {{ entry.message }}
      </p>
      <p v-if="current.specialized_attributes_omitted" class="theme-purchase-note">
        {{ current.omitted_reason }}
      </p>
      <div class="setup-field theme-compare-toggle">
        <label>
          <input v-model="differencesOnly" type="checkbox" />
          Show differences only
        </label>
      </div>
      <table class="compare-table">
        <caption>
          Compared items
        </caption>
        <thead>
          <tr>
            <th scope="col">Detail</th>
            <th
              v-for="(selection, index) in current.selections"
              :key="selection.variant_id"
              scope="col"
            >
              <a :href="selection.url">{{ selection.title }}</a>
              <template v-if="selection.variant_label !== 'Standard'">
                · {{ selection.variant_label }}
              </template>
              <p class="theme-purchase-note">
                {{ selection.money.formatted }} · {{ availabilityLabel(selection.availability) }}
              </p>
              <button
                type="button"
                class="text-link"
                data-remove
                :aria-label="`Remove ${selection.title} from comparison`"
                :disabled="busy"
                @click="removeColumn(selection, index)"
              >
                Remove
              </button>
              <div class="setup-field">
                <label :for="`replace-${selection.variant_id}`">Replace with</label>
                <select
                  :id="`replace-${selection.variant_id}`"
                  v-model="replaceChoices[selection.variant_id]"
                  :aria-label="`Replace ${selection.title} with`"
                >
                  <option value="">Choose an item</option>
                  <option
                    v-for="candidate in candidatesFor()"
                    :key="candidate.variant_id"
                    :value="candidate.variant_id"
                  >
                    {{ candidate.title }}
                    <template v-if="candidate.variant_label !== 'Standard'">
                      · {{ candidate.variant_label }}
                    </template>
                  </option>
                </select>
                <button
                  type="button"
                  class="text-link"
                  :aria-label="`Replace ${selection.title}`"
                  :disabled="busy || !replaceChoices[selection.variant_id]"
                  @click="replaceColumn(selection, index)"
                >
                  Replace
                </button>
              </div>
              <form v-if="selection.available" method="post" action="/cart/lines">
                <input type="hidden" name="_token" :value="props.csrfToken" />
                <input type="hidden" name="expected_revision" :value="props.cartRevision" />
                <input type="hidden" name="variant_id" :value="selection.variant_id" />
                <input type="hidden" name="quantity" value="1" />
                <button type="submit" class="text-link">Add to cart</button>
              </form>
              <p v-else class="theme-purchase-note">Unavailable for purchase</p>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in visibleRows" :key="row.key" :class="{ 'compare-differs': row.differs }">
            <th scope="row">{{ row.label }}</th>
            <td
              v-for="(cell, cellIndex) in row.cells"
              :key="cellIndex"
              :data-column="current.selections[cellIndex]?.title ?? ''"
            >
              {{ cell.display }}
            </td>
          </tr>
        </tbody>
      </table>
      <p v-if="differencesOnly && visibleRows.length === 0" class="theme-purchase-note">
        These items match on every compared detail.
      </p>
      <p class="theme-purchase-note">
        Prices and availability are shown as they are right now. “Not specified” means the product
        does not provide that detail.
      </p>
    </template>
    <section v-else-if="remainingSingle" aria-label="Comparison needs another item">
      <p data-remaining tabindex="-1">
        {{ remainingSingle.title }} is the only item left in the comparison.
      </p>
      <p class="theme-purchase-note">
        Add another item to compare it — choose Compare on any other product, then open the
        comparison again.
      </p>
      <a class="text-link" href="/search">Browse products</a>
    </section>
  </div>
</template>
