export interface SearchQuery {
  q: string
  category: string
  price_min: number | null
  price_max: number | null
  available: '' | '0' | '1'
  sort: string
  page: number
  page_size: number
  facets: Record<string, string | { min?: string; max?: string }>
}
export interface CatalogFiltersInput {
  query: SearchQuery
  sorts: { value: string; label: string }[]
  categories: { path: string; title: string }[]
  facets: {
    key: string
    label: string
    type: string
    unit: string | null
    values: { value: string; count: number }[]
  }[]
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function keysAllowed(
  value: Record<string, unknown>,
  allowed: string[],
  required: string[],
): boolean {
  return (
    required.every((key) => Object.hasOwn(value, key)) &&
    Object.keys(value).every((key) => allowed.includes(key))
  )
}
function sortOption(value: unknown): value is { value: string; label: string } {
  return (
    record(value) &&
    keysAllowed(value, ['value', 'label'], ['value', 'label']) &&
    typeof value.value === 'string' &&
    value.value.length >= 1 &&
    value.value.length <= 80 &&
    typeof value.label === 'string' &&
    value.label.length >= 1 &&
    value.label.length <= 120
  )
}
function categoryOption(value: unknown): value is { path: string; title: string } {
  return (
    record(value) &&
    keysAllowed(value, ['path', 'title'], ['path', 'title']) &&
    typeof value.path === 'string' &&
    value.path.length >= 1 &&
    value.path.length <= 200 &&
    typeof value.title === 'string' &&
    value.title.length >= 1 &&
    value.title.length <= 200
  )
}
export function searchHref(query: SearchQuery): string {
  const params = new URLSearchParams()
  if (query.q !== '') params.set('q', query.q)
  if (query.category !== '') params.set('category', query.category)
  if (query.price_min !== null) params.set('price_min', String(query.price_min))
  if (query.price_max !== null) params.set('price_max', String(query.price_max))
  if (query.available !== '') params.set('available', query.available)
  if (query.sort !== 'title') params.set('sort', query.sort)
  params.set('page', String(query.page))
  if (query.page_size !== 24) params.set('page_size', String(query.page_size))
  for (const [key, value] of Object.entries(query.facets)) {
    if (typeof value === 'string') {
      if (value !== '') params.set(`f[${key}]`, value)
      continue
    }
    if (value.min) params.set(`f[${key}][min]`, value.min)
    if (value.max) params.set(`f[${key}][max]`, value.max)
  }
  const encoded = params.toString()
  return encoded === '' ? '/search' : `/search?${encoded}`
}
export function parseCatalogFilters(value: unknown): CatalogFiltersInput | null {
  if (
    !record(value) ||
    !keysAllowed(
      value,
      ['query', 'sorts', 'categories', 'facets'],
      ['query', 'sorts', 'categories', 'facets'],
    ) ||
    !record(value.query) ||
    !keysAllowed(
      value.query,
      [
        'q',
        'category',
        'price_min',
        'price_max',
        'available',
        'sort',
        'page',
        'page_size',
        'facets',
      ],
      [
        'q',
        'category',
        'price_min',
        'price_max',
        'available',
        'sort',
        'page',
        'page_size',
        'facets',
      ],
    ) ||
    typeof value.query.q !== 'string' ||
    value.query.q.length > 80 ||
    typeof value.query.category !== 'string' ||
    typeof value.query.sort !== 'string' ||
    value.query.sort.length > 80 ||
    (value.query.available !== '' &&
      value.query.available !== '0' &&
      value.query.available !== '1') ||
    typeof value.query.page !== 'number' ||
    !Number.isInteger(value.query.page) ||
    value.query.page < 1 ||
    typeof value.query.page_size !== 'number' ||
    !Number.isInteger(value.query.page_size) ||
    value.query.page_size < 1 ||
    value.query.page_size > 100 ||
    (value.query.price_min !== null &&
      (typeof value.query.price_min !== 'number' ||
        !Number.isSafeInteger(value.query.price_min) ||
        value.query.price_min < 0)) ||
    (value.query.price_max !== null &&
      (typeof value.query.price_max !== 'number' ||
        !Number.isSafeInteger(value.query.price_max) ||
        value.query.price_max < 0)) ||
    !(
      record(value.query.facets) ||
      (Array.isArray(value.query.facets) && value.query.facets.length === 0)
    ) ||
    (record(value.query.facets) && Object.keys(value.query.facets).length > 30) ||
    !Array.isArray(value.sorts) ||
    value.sorts.length > 40 ||
    !value.sorts.every((entry) => sortOption(entry)) ||
    !Array.isArray(value.categories) ||
    value.categories.length > 100 ||
    !value.categories.every((entry) => categoryOption(entry)) ||
    !Array.isArray(value.facets) ||
    value.facets.length > 30
  )
    return null
  const facets: CatalogFiltersInput['facets'] = []
  for (const facet of value.facets) {
    if (
      !record(facet) ||
      !keysAllowed(
        facet,
        ['key', 'label', 'type', 'unit', 'values'],
        ['key', 'label', 'type', 'unit', 'values'],
      ) ||
      typeof facet.key !== 'string' ||
      facet.key.length < 1 ||
      facet.key.length > 80 ||
      typeof facet.label !== 'string' ||
      typeof facet.type !== 'string' ||
      (facet.unit !== null && typeof facet.unit !== 'string') ||
      !Array.isArray(facet.values) ||
      facet.values.length > 50 ||
      !facet.values.every(
        (entry) =>
          record(entry) &&
          keysAllowed(entry, ['value', 'count'], ['value', 'count']) &&
          typeof entry.value === 'string' &&
          typeof entry.count === 'number' &&
          Number.isInteger(entry.count) &&
          entry.count >= 0,
      )
    )
      return null
    facets.push({
      key: facet.key,
      label: facet.label,
      type: facet.type,
      unit: facet.unit,
      values: facet.values as { value: string; count: number }[],
    })
  }
  return {
    query: {
      q: value.query.q,
      category: value.query.category,
      price_min: value.query.price_min,
      price_max: value.query.price_max,
      available: value.query.available,
      sort: value.query.sort,
      page: value.query.page,
      page_size: value.query.page_size,
      facets: (Array.isArray(value.query.facets)
        ? {}
        : value.query.facets) as SearchQuery['facets'],
    },
    sorts: value.sorts,
    categories: value.categories,
    facets,
  }
}
