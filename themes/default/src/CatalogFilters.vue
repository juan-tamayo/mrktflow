<script setup lang="ts">
import { computed, reactive } from 'vue'
import { searchHref, type CatalogFiltersInput } from './catalogFilters'
const props = defineProps<{
  query: CatalogFiltersInput['query']
  sorts: CatalogFiltersInput['sorts']
  categories: CatalogFiltersInput['categories']
  facets: CatalogFiltersInput['facets']
}>()
const query = reactive({ ...props.query, facets: { ...props.query.facets } })
const href = computed(() => searchHref({ ...query, page: 1, facets: { ...query.facets } }))
function choice(facetKey: string): string {
  const current = query.facets[facetKey]
  return typeof current === 'string' ? current : ''
}
function chooseFacet(facetKey: string, value: string) {
  if (value === '') {
    const next = { ...query.facets }
    delete next[facetKey]
    query.facets = next
    return
  }
  query.facets = { ...query.facets, [facetKey]: value }
}
function rangeValue(facetKey: string, bound: 'min' | 'max'): string {
  const current = query.facets[facetKey]
  if (current && typeof current !== 'string') return current[bound] ?? ''
  return ''
}
function chooseRange(facetKey: string, bound: 'min' | 'max', value: string) {
  const current = query.facets[facetKey]
  const range = current && typeof current !== 'string' ? { ...current } : {}
  if (value === '') delete range[bound]
  else range[bound] = value
  const next = { ...query.facets }
  if (!range.min && !range.max) delete next[facetKey]
  else next[facetKey] = range
  query.facets = next
}
</script>

<template>
  <form method="get" action="/search" class="theme-search-form" :data-href="href">
    <div class="setup-field">
      <label for="enhanced-q">Find a product</label>
      <input id="enhanced-q" v-model="query.q" name="q" type="search" maxlength="80" />
    </div>
    <div class="setup-field">
      <label for="enhanced-category">Category</label>
      <select id="enhanced-category" v-model="query.category" name="category">
        <option value="">All categories</option>
        <option v-for="category in categories" :key="category.path" :value="category.path">
          {{ category.title }}
        </option>
      </select>
    </div>
    <div class="setup-field">
      <label for="enhanced-price-min">Minimum price</label>
      <input
        id="enhanced-price-min"
        :value="query.price_min ?? ''"
        name="price_min"
        inputmode="numeric"
        @input="
          query.price_min =
            ($event.target as HTMLInputElement).value === ''
              ? null
              : Number(($event.target as HTMLInputElement).value)
        "
      />
    </div>
    <div class="setup-field">
      <label for="enhanced-price-max">Maximum price</label>
      <input
        id="enhanced-price-max"
        :value="query.price_max ?? ''"
        name="price_max"
        inputmode="numeric"
        @input="
          query.price_max =
            ($event.target as HTMLInputElement).value === ''
              ? null
              : Number(($event.target as HTMLInputElement).value)
        "
      />
    </div>
    <div class="setup-field">
      <label for="enhanced-available">Availability</label>
      <select id="enhanced-available" v-model="query.available" name="available">
        <option value="">Any</option>
        <option value="1">In stock</option>
        <option value="0">Unavailable</option>
      </select>
    </div>
    <template v-for="facet in facets" :key="facet.key">
      <div
        v-if="facet.type === 'enum' || facet.type === 'boolean' || facet.type === 'string'"
        class="setup-field"
      >
        <label :for="`enhanced-facet-${facet.key}`">{{ facet.label }}</label>
        <select
          :id="`enhanced-facet-${facet.key}`"
          :value="choice(facet.key)"
          :name="`f[${facet.key}]`"
          @change="chooseFacet(facet.key, ($event.target as HTMLSelectElement).value)"
        >
          <option value="">Any</option>
          <option v-for="entry in facet.values" :key="entry.value" :value="entry.value">
            {{ facet.type === 'boolean' ? (entry.value === '1' ? 'Yes' : 'No') : entry.value }}
            ({{ entry.count }})
          </option>
        </select>
      </div>
      <template v-else>
        <div class="setup-field">
          <label :for="`enhanced-facet-${facet.key}-min`">{{ facet.label }} from</label>
          <input
            :id="`enhanced-facet-${facet.key}-min`"
            :value="rangeValue(facet.key, 'min')"
            :name="`f[${facet.key}][min]`"
            @input="chooseRange(facet.key, 'min', ($event.target as HTMLInputElement).value)"
          />
        </div>
        <div class="setup-field">
          <label :for="`enhanced-facet-${facet.key}-max`">{{ facet.label }} to</label>
          <input
            :id="`enhanced-facet-${facet.key}-max`"
            :value="rangeValue(facet.key, 'max')"
            :name="`f[${facet.key}][max]`"
            @input="chooseRange(facet.key, 'max', ($event.target as HTMLInputElement).value)"
          />
        </div>
      </template>
    </template>
    <div class="setup-field">
      <label for="enhanced-sort">Sort</label>
      <select id="enhanced-sort" v-model="query.sort" name="sort">
        <option v-for="sort in sorts" :key="sort.value" :value="sort.value">
          {{ sort.label }}
        </option>
      </select>
    </div>
    <input v-if="query.page_size !== 24" type="hidden" name="page_size" :value="query.page_size" />
    <p class="field-help" role="status">Filter changes stay in the address bar.</p>
    <button type="submit" class="primary-button">Apply filters</button>
  </form>
</template>
