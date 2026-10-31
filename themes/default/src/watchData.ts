export interface WatchVariantOption {
  id: string
  label: string
}

export interface WatchOptionsInput {
  endpoint: string
  csrfToken: string
  email: string
  selectedId: string | null
  variants: WatchVariantOption[]
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function variantOptions(value: unknown): WatchVariantOption[] | null {
  if (!Array.isArray(value)) return null
  const variants: WatchVariantOption[] = []
  for (const entry of value) {
    if (!record(entry)) return null
    if (typeof entry.id !== 'string' || entry.id === '') return null
    if (typeof entry.label !== 'string') return null
    variants.push({ id: entry.id, label: entry.label })
  }
  return variants
}

/**
 * Parses the product page's watch-options outlet dataset. Anything
 * malformed returns null and the caller leaves the native form in
 * place, so alerts keep working without JavaScript.
 */
export function parseWatchOptions(
  dataset: Record<string, string | undefined>,
): WatchOptionsInput | null {
  const endpoint = dataset.endpoint
  const csrfToken = dataset.csrf
  if (typeof endpoint !== 'string' || !endpoint.startsWith('/')) return null
  if (typeof csrfToken !== 'string' || csrfToken === '') return null
  if (typeof dataset.variants !== 'string') return null
  let parsed: unknown
  try {
    parsed = JSON.parse(dataset.variants)
  } catch {
    return null
  }
  const variants = variantOptions(parsed)
  if (variants === null || variants.length === 0) return null
  const selectedId =
    typeof dataset.selectedId === 'string' &&
    variants.some((variant) => variant.id === dataset.selectedId)
      ? dataset.selectedId
      : null
  return {
    endpoint,
    csrfToken,
    email: typeof dataset.email === 'string' ? dataset.email : '',
    selectedId,
    variants,
  }
}
