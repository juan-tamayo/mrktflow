export interface CartMoney {
  amount: number
  currency: string
  exponent: number
  formatted: string
}
export interface CartLine {
  id: string
  variantId: string
  productId: string
  title: string
  sku: string
  type: 'physical' | 'digital'
  quantity: number
  available: boolean
  availability: string
  maxQuantity: number
  money: CartMoney
  lineTotal: CartMoney
}
export interface CartSnapshot {
  id: string | null
  revision: number
  lines: CartLine[]
  subtotal: CartMoney
  notices: string[]
}
export interface CartIndicatorInput {
  itemCount: number
  revision: number
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
function uuid(value: unknown): value is string {
  return (
    typeof value === 'string' &&
    /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value)
  )
}
function integer(value: unknown, min: number, max: number): value is number {
  return typeof value === 'number' && Number.isInteger(value) && value >= min && value <= max
}
function money(value: unknown): value is CartMoney {
  if (
    !record(value) ||
    !keysAllowed(
      value,
      ['amount', 'currency', 'exponent', 'formatted'],
      ['amount', 'currency', 'exponent', 'formatted'],
    )
  )
    return false
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
function line(value: unknown): value is CartLine {
  if (
    !record(value) ||
    !keysAllowed(
      value,
      [
        'id',
        'variantId',
        'productId',
        'title',
        'sku',
        'type',
        'quantity',
        'available',
        'availability',
        'maxQuantity',
        'money',
        'lineTotal',
      ],
      [
        'id',
        'variantId',
        'productId',
        'title',
        'sku',
        'type',
        'quantity',
        'available',
        'availability',
        'maxQuantity',
        'money',
        'lineTotal',
      ],
    )
  )
    return false
  return (
    uuid(value.id) &&
    uuid(value.variantId) &&
    uuid(value.productId) &&
    typeof value.title === 'string' &&
    value.title.length >= 1 &&
    value.title.length <= 200 &&
    typeof value.sku === 'string' &&
    value.sku.length >= 1 &&
    value.sku.length <= 64 &&
    (value.type === 'physical' || value.type === 'digital') &&
    integer(value.quantity, 1, 99) &&
    typeof value.available === 'boolean' &&
    typeof value.availability === 'string' &&
    value.availability.length >= 1 &&
    value.availability.length <= 40 &&
    integer(value.maxQuantity, 0, 99) &&
    money(value.money) &&
    money(value.lineTotal)
  )
}

export function parseCart(value: unknown): CartSnapshot | null {
  if (
    !record(value) ||
    !keysAllowed(
      value,
      ['id', 'revision', 'lines', 'subtotal', 'notices'],
      ['id', 'revision', 'lines', 'subtotal', 'notices'],
    )
  )
    return null
  const id = value.id
  const revision = value.revision
  const lines = value.lines
  const subtotal = value.subtotal
  const notices = value.notices
  if (id !== null && !uuid(id)) return null
  if (!integer(revision, 1, 4294967295)) return null
  if (!Array.isArray(lines) || lines.length > 100 || !lines.every(line)) return null
  if (!money(subtotal)) return null
  if (
    !Array.isArray(notices) ||
    notices.length > 20 ||
    !notices.every(
      (notice) => typeof notice === 'string' && notice.length >= 1 && notice.length <= 200,
    )
  )
    return null
  return { id, revision, lines, subtotal, notices }
}

export function parseCartIndicator(value: unknown): CartIndicatorInput | null {
  if (!record(value) || !keysAllowed(value, ['itemCount', 'revision'], ['itemCount', 'revision']))
    return null
  if (!integer(value.itemCount, 0, 9900)) return null
  if (!integer(value.revision, 1, 4294967295)) return null
  return { itemCount: value.itemCount, revision: value.revision }
}
