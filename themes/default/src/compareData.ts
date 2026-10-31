import { ref, type Ref } from 'vue'

/**
 * The compare tray (SHOP-05, SHOP-06, WEB-06).
 *
 * A selection's identity is the variant, exactly as the comparison
 * read model resolves it: a product-level entry names the product and
 * the server resolves its default listed variant, while a product-page
 * entry names the shopper's selected variant. A tray holds at most
 * one entry per product and at most four products; adding a fifth
 * product is refused, never resolved by dropping the oldest entry.
 *
 * The tray persists in a first-party session cookie that carries only
 * the selections. That is the same cookie-scoped durable scope as the
 * anonymous guest session that keys the guest cart and wishlist, so
 * the tray survives navigation without inventing a second store or
 * any account state; every page load re-reads the cookie, which is
 * the single source of truth shared by every compare widget.
 */
export interface CompareTrayEntry {
  productId: string
  variantId: string | null
  title: string
  variantLabel: string
}

export interface CompareVariantOption {
  id: string
  label: string
}

export interface ProductActionsInput {
  productId: string
  title: string
  variants: CompareVariantOption[]
  selectedId: string | null
}

export type CompareAddOutcome = 'added' | 'replaced' | 'full'

export interface CompareTrayPersistence {
  load: () => CompareTrayEntry[]
  save: (tray: CompareTrayEntry[]) => void
  /** Cookie-backed persistence is shared by every bundle on the page. */
  live?: boolean
}

export interface CompareTrayEvents {
  addEventListener: (type: string, listener: () => void) => void
  dispatchEvent: (event: unknown) => boolean
}

export interface CompareTrayStoreOptions {
  liveSync?: boolean
  events?: CompareTrayEvents
}

export const COMPARE_TRAY_EVENT = 'mrktflow:compare-tray-changed'

export interface CompareTrayStore {
  tray: Ref<CompareTrayEntry[]>
  announcement: Ref<string>
  add: (entry: CompareTrayEntry) => CompareAddOutcome
  remove: (productId: string) => void
  entryFor: (productId: string) => CompareTrayEntry | undefined
  syncSelections: (
    selections: Array<{
      variant_id: string
      product_id: string
      title: string
      variant_label: string
    }>,
  ) => void
}

export const COMPARE_TRAY_LIMIT = 4
export const COMPARE_TRAY_COOKIE = 'compare-tray'
export const COMPARE_FULL_MESSAGE =
  'Compare holds up to four items. Remove an item before adding another.'

function uuid(value: unknown): value is string {
  return (
    typeof value === 'string' &&
    /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value)
  )
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function trayEntry(value: unknown): value is CompareTrayEntry {
  if (!record(value)) return false
  return (
    uuid(value.productId) &&
    (value.variantId === null || uuid(value.variantId)) &&
    typeof value.title === 'string' &&
    value.title.length >= 1 &&
    value.title.length <= 200 &&
    typeof value.variantLabel === 'string' &&
    value.variantLabel.length <= 120
  )
}

/**
 * Validates an untrusted tray payload (the cookie is shopper-editable).
 * Anything malformed — including a fifth entry or two entries for one
 * product — invalidates the whole payload rather than trusting part
 * of it.
 */
export function parseCompareTray(value: unknown): CompareTrayEntry[] | null {
  if (!Array.isArray(value) || value.length > COMPARE_TRAY_LIMIT) return null
  const seen = new Set<string>()
  for (const entry of value) {
    if (!trayEntry(entry)) return null
    if (seen.has(entry.productId)) return null
    seen.add(entry.productId)
  }
  return value as CompareTrayEntry[]
}

export function addToTray(
  tray: CompareTrayEntry[],
  entry: CompareTrayEntry,
): { tray: CompareTrayEntry[]; outcome: CompareAddOutcome } {
  const existing = tray.findIndex((candidate) => candidate.productId === entry.productId)
  if (existing >= 0) {
    const next = [...tray]
    next[existing] = entry
    return { tray: next, outcome: 'replaced' }
  }
  if (tray.length >= COMPARE_TRAY_LIMIT) return { tray, outcome: 'full' }
  return { tray: [...tray, entry], outcome: 'added' }
}

export function removeFromTray(tray: CompareTrayEntry[], productId: string): CompareTrayEntry[] {
  return tray.filter((entry) => entry.productId !== productId)
}

export function comparePageUrl(tray: CompareTrayEntry[]): string {
  const parts: string[] = []
  for (const entry of tray) {
    parts.push(
      entry.variantId === null
        ? `products[]=${encodeURIComponent(entry.productId)}`
        : `items[]=${encodeURIComponent(entry.variantId)}`,
    )
  }
  return parts.length === 0 ? '/compare' : `/compare?${parts.join('&')}`
}

export function compareApiUrl(variantIds: string[]): string {
  const parts = variantIds.map((id) => `items[]=${encodeURIComponent(id)}`)
  return parts.length === 0 ? '/store-api/v1/compare' : `/store-api/v1/compare?${parts.join('&')}`
}

export function serializeCompareTray(tray: CompareTrayEntry[]): string {
  return encodeURIComponent(JSON.stringify(tray))
}

export function readCompareTray(jar: { cookie: string }): CompareTrayEntry[] {
  for (const segment of jar.cookie.split(';')) {
    const trimmed = segment.trim()
    if (trimmed.startsWith(`${COMPARE_TRAY_COOKIE}=`)) {
      const raw = trimmed.slice(COMPARE_TRAY_COOKIE.length + 1)
      try {
        return parseCompareTray(JSON.parse(decodeURIComponent(raw))) ?? []
      } catch {
        return []
      }
    }
  }
  return []
}

export function writeCompareTray(jar: { cookie: string }, tray: CompareTrayEntry[]): void {
  jar.cookie =
    tray.length === 0
      ? `${COMPARE_TRAY_COOKIE}=; Path=/; SameSite=Lax; Max-Age=0`
      : `${COMPARE_TRAY_COOKIE}=${serializeCompareTray(tray)}; Path=/; SameSite=Lax`
}

export function cookieCompareTrayPersistence(
  jar: { cookie: string } = document,
): CompareTrayPersistence {
  return {
    load: () => readCompareTray(jar),
    save: (tray) => writeCompareTray(jar, tray),
    live: true,
  }
}

export function createCompareTrayStore(
  persistence: CompareTrayPersistence = cookieCompareTrayPersistence(),
  options: CompareTrayStoreOptions = {},
): CompareTrayStore {
  const tray = ref<CompareTrayEntry[]>(persistence.load())
  const announcement = ref('')
  // Each compare widget ships in its own Vite entry (the theme's
  // one-component-per-entry idiom), so widgets on one page hold
  // separate store instances over the same cookie. Live cookie-backed
  // stores announce every save on a window event and peers re-read
  // the cookie — the source of truth — so the tray, the card controls
  // and the table stay in step without a second store.
  const live = options.liveSync === true && persistence.live === true
  const events: CompareTrayEvents | undefined = live
    ? (options.events ??
      (typeof window !== 'undefined'
        ? {
            addEventListener: (type, listener) => window.addEventListener(type, listener),
            dispatchEvent: (event) => window.dispatchEvent(event as Event),
          }
        : undefined))
    : undefined
  const notify = (): void => {
    events?.dispatchEvent(new Event(COMPARE_TRAY_EVENT))
  }
  if (events) {
    events.addEventListener(COMPARE_TRAY_EVENT, () => {
      const next = persistence.load()
      if (JSON.stringify(next) !== JSON.stringify(tray.value)) tray.value = next
    })
  }
  return {
    tray,
    announcement,
    add(entry) {
      const result = addToTray(tray.value, entry)
      if (result.outcome === 'full') {
        announcement.value = COMPARE_FULL_MESSAGE
        return result.outcome
      }
      tray.value = result.tray
      persistence.save(tray.value)
      notify()
      announcement.value =
        result.outcome === 'replaced'
          ? `${entry.title} updated in compare.`
          : `${entry.title} added to compare.`
      return result.outcome
    },
    remove(productId) {
      const entry = tray.value.find((candidate) => candidate.productId === productId)
      if (!entry) return
      tray.value = removeFromTray(tray.value, productId)
      persistence.save(tray.value)
      notify()
      announcement.value = `${entry.title} removed from compare.`
    },
    entryFor(productId) {
      return tray.value.find((candidate) => candidate.productId === productId)
    },
    syncSelections(selections) {
      tray.value = selections.map((selection) => ({
        productId: selection.product_id,
        variantId: selection.variant_id,
        title: selection.title,
        variantLabel: selection.variant_label,
      }))
      persistence.save(tray.value)
      notify()
    },
  }
}

export interface ComparisonMoney {
  amount: number
  currency: string
  exponent: number
  formatted: string
}

export interface ComparisonSelection {
  variant_id: string
  product_id: string
  title: string
  url: string
  sku: string
  variant_label: string
  type: 'physical' | 'digital'
  money: ComparisonMoney
  availability: string
  available: boolean
}

export interface ComparisonCell {
  specified: boolean
  display: string
}

export interface ComparisonRow {
  key: string
  label: string
  kind: string
  type: string
  unit: string | null
  differs: boolean
  cells: ComparisonCell[]
}

export interface ComparisonRemoved {
  selection: string
  reason: string
  message: string
}

export interface Comparison {
  selections: ComparisonSelection[]
  rows: ComparisonRow[]
  compatible: boolean
  specialized_attributes_omitted: boolean
  omitted_reason: string | null
  removed: ComparisonRemoved[]
}

export interface CompareCandidate {
  variant_id: string
  product_id: string
  title: string
  variant_label: string
}

export interface ComparisonTableInput {
  comparison: Comparison
  candidates: CompareCandidate[]
  endpoint: string
  csrfToken: string
  cartRevision: number
}

function integer(value: unknown, min: number, max: number): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= min && value <= max
}

function comparisonMoney(value: unknown): value is ComparisonMoney {
  if (!record(value)) return false
  return (
    integer(value.amount, 0, 9007199254740991) &&
    typeof value.currency === 'string' &&
    value.currency.length >= 3 &&
    value.currency.length <= 8 &&
    integer(value.exponent, 0, 3) &&
    typeof value.formatted === 'string' &&
    value.formatted.length >= 1 &&
    value.formatted.length <= 40
  )
}

function comparisonSelection(value: unknown): value is ComparisonSelection {
  if (!record(value)) return false
  return (
    uuid(value.variant_id) &&
    uuid(value.product_id) &&
    typeof value.title === 'string' &&
    value.title.length >= 1 &&
    value.title.length <= 200 &&
    typeof value.url === 'string' &&
    value.url.startsWith('/') &&
    value.url.length <= 500 &&
    typeof value.sku === 'string' &&
    value.sku.length >= 1 &&
    value.sku.length <= 64 &&
    typeof value.variant_label === 'string' &&
    value.variant_label.length <= 120 &&
    (value.type === 'physical' || value.type === 'digital') &&
    comparisonMoney(value.money) &&
    typeof value.availability === 'string' &&
    value.availability.length >= 1 &&
    value.availability.length <= 40 &&
    typeof value.available === 'boolean'
  )
}

/**
 * Validates the read model the comparison endpoint serves before the
 * table trusts it. A payload whose rows do not align positionally
 * with its columns is rejected whole — the server-rendered table
 * stays in place instead.
 */
export function parseComparison(value: unknown): Comparison | null {
  if (!record(value)) return null
  if (!Array.isArray(value.selections)) return null
  if (value.selections.length < 2 || value.selections.length > COMPARE_TRAY_LIMIT) return null
  if (!value.selections.every(comparisonSelection)) return null
  const columns = value.selections.length
  if (!Array.isArray(value.rows) || value.rows.length > 60) return null
  const rows: ComparisonRow[] = []
  for (const row of value.rows) {
    if (!record(row)) return null
    if (typeof row.key !== 'string' || row.key.length < 1 || row.key.length > 80) return null
    if (typeof row.label !== 'string' || row.label.length < 1 || row.label.length > 120) return null
    if (row.kind !== 'basic' && row.kind !== 'attribute') return null
    if (typeof row.type !== 'string' || row.type.length > 16) return null
    if (row.unit !== null && (typeof row.unit !== 'string' || row.unit.length > 16)) return null
    if (typeof row.differs !== 'boolean') return null
    if (!Array.isArray(row.cells) || row.cells.length !== columns) return null
    const cells: ComparisonCell[] = []
    for (const cell of row.cells) {
      if (!record(cell)) return null
      if (typeof cell.specified !== 'boolean') return null
      if (typeof cell.display !== 'string' || cell.display.length > 500) return null
      cells.push({ specified: cell.specified, display: cell.display })
    }
    rows.push({
      key: row.key,
      label: row.label,
      kind: row.kind,
      type: row.type,
      unit: row.unit,
      differs: row.differs,
      cells,
    })
  }
  if (typeof value.compatible !== 'boolean') return null
  if (typeof value.specialized_attributes_omitted !== 'boolean') return null
  if (
    value.omitted_reason !== null &&
    (typeof value.omitted_reason !== 'string' || value.omitted_reason.length > 300)
  ) {
    return null
  }
  if (!Array.isArray(value.removed) || value.removed.length > 3) return null
  const removed: ComparisonRemoved[] = []
  for (const entry of value.removed) {
    if (!record(entry)) return null
    if (typeof entry.selection !== 'string' || entry.selection.length > 64) return null
    if (typeof entry.reason !== 'string' || entry.reason.length > 40) return null
    if (typeof entry.message !== 'string' || entry.message.length > 200) return null
    removed.push({ selection: entry.selection, reason: entry.reason, message: entry.message })
  }
  return {
    selections: value.selections as ComparisonSelection[],
    rows,
    compatible: value.compatible,
    specialized_attributes_omitted: value.specialized_attributes_omitted,
    omitted_reason: value.omitted_reason,
    removed,
  }
}

/**
 * The differences-only filter trusts the read model's `differs` flag,
 * which the server decided on normalized values — never a comparison
 * of the display strings, which can differ for equal values
 * ("1.5 kg" vs "1500 g").
 */
export function visibleComparisonRows(
  comparison: Comparison,
  differencesOnly: boolean,
): ComparisonRow[] {
  return differencesOnly ? comparison.rows.filter((row) => row.differs) : comparison.rows
}

export function parseCompareCandidates(value: unknown): CompareCandidate[] | null {
  if (!Array.isArray(value) || value.length > 100) return null
  const candidates: CompareCandidate[] = []
  for (const candidate of value) {
    if (!record(candidate)) return null
    if (!uuid(candidate.variant_id) || !uuid(candidate.product_id)) return null
    if (
      typeof candidate.title !== 'string' ||
      candidate.title.length < 1 ||
      candidate.title.length > 200
    ) {
      return null
    }
    if (typeof candidate.variant_label !== 'string' || candidate.variant_label.length > 120) {
      return null
    }
    candidates.push({
      variant_id: candidate.variant_id,
      product_id: candidate.product_id,
      title: candidate.title,
      variant_label: candidate.variant_label,
    })
  }
  return candidates
}

/**
 * Parses the compare page's table outlet dataset. Anything malformed
 * returns null and the server-rendered table stays in place.
 */
export function parseComparisonTable(
  dataset: Record<string, string | undefined>,
): ComparisonTableInput | null {
  const endpoint = dataset.endpoint
  const csrfToken = dataset.csrf
  if (typeof endpoint !== 'string' || !endpoint.startsWith('/')) return null
  if (typeof csrfToken !== 'string' || csrfToken === '') return null
  if (typeof dataset.comparison !== 'string' || typeof dataset.candidates !== 'string') return null
  let comparisonJson: unknown
  let candidatesJson: unknown
  try {
    comparisonJson = JSON.parse(dataset.comparison)
    candidatesJson = JSON.parse(dataset.candidates)
  } catch {
    return null
  }
  const comparison = parseComparison(comparisonJson)
  const candidates = parseCompareCandidates(candidatesJson)
  if (comparison === null || candidates === null) return null
  const cartRevision = Number.parseInt(dataset.cartRevision ?? '', 10)
  return {
    comparison,
    candidates,
    endpoint,
    csrfToken,
    cartRevision: Number.isInteger(cartRevision) && cartRevision >= 1 ? cartRevision : 1,
  }
}

let sharedStore: CompareTrayStore | null = null

/** The one tray store every compare widget on the page shares. */
export function sharedCompareTrayStore(): CompareTrayStore {
  sharedStore ??= createCompareTrayStore(cookieCompareTrayPersistence(), { liveSync: true })
  return sharedStore
}

/**
 * Parses a product/card compare outlet's JSON payload. Anything
 * malformed returns null and the caller leaves the page without a
 * compare control, so catalog pages keep working without it.
 */
export function parseProductActions(raw: string): ProductActionsInput | null {
  let parsed: unknown
  try {
    parsed = JSON.parse(raw)
  } catch {
    return null
  }
  if (!record(parsed)) return null
  if (!uuid(parsed.productId)) return null
  if (typeof parsed.title !== 'string' || parsed.title.length < 1 || parsed.title.length > 200) {
    return null
  }
  const variants: CompareVariantOption[] = []
  if (parsed.variants !== undefined) {
    if (!Array.isArray(parsed.variants)) return null
    for (const variant of parsed.variants) {
      if (!record(variant) || !uuid(variant.id)) return null
      if (typeof variant.label !== 'string' || variant.label.length > 120) return null
      variants.push({ id: variant.id, label: variant.label })
    }
  }
  const selectedId =
    typeof parsed.selectedId === 'string' &&
    variants.some((variant) => variant.id === parsed.selectedId)
      ? parsed.selectedId
      : null
  return { productId: parsed.productId, title: parsed.title, variants, selectedId }
}
