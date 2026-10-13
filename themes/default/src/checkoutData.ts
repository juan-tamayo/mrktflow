export interface QuoteMoney {
  amount: number
  currency: string
  exponent: number
  formatted: string
}
export interface QuoteLine {
  id: string
  title: string
  sku: string
  quantity: number
  subtotal: QuoteMoney
  discount: QuoteMoney
  total: QuoteMoney
}
export interface CheckoutQuote {
  id: string
  cartRevision: number
  email: string
  fingerprint: string
  expiresAt: string
  acceptedAt: string | null
  priceMode: string
  requiresDelivery: boolean
  digitalOnly: boolean
  coupon: string | null
  merchandise: QuoteMoney
  discount: QuoteMoney
  shipping: QuoteMoney
  tax: QuoteMoney
  grandTotal: QuoteMoney
  lines: QuoteLine[]
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
function money(value: unknown): value is QuoteMoney {
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
function line(value: unknown): value is QuoteLine {
  if (
    !record(value) ||
    !keysAllowed(
      value,
      ['id', 'title', 'sku', 'quantity', 'subtotal', 'discount', 'total'],
      ['id', 'title', 'sku', 'quantity', 'subtotal', 'discount', 'total'],
    )
  )
    return false
  return (
    uuid(value.id) &&
    typeof value.title === 'string' &&
    value.title.length >= 1 &&
    value.title.length <= 200 &&
    typeof value.sku === 'string' &&
    value.sku.length >= 1 &&
    value.sku.length <= 64 &&
    integer(value.quantity, 1, 99) &&
    money(value.subtotal) &&
    money(value.discount) &&
    money(value.total)
  )
}

export function parseCheckoutQuote(value: unknown): CheckoutQuote | null {
  if (!record(value)) return null
  const allowed = [
    'id',
    'cartRevision',
    'email',
    'fingerprint',
    'expiresAt',
    'acceptedAt',
    'priceMode',
    'requiresDelivery',
    'digitalOnly',
    'coupon',
    'merchandise',
    'discount',
    'shipping',
    'tax',
    'grandTotal',
    'lines',
    'eligibleMethods',
    'billing',
    'shippingAddress',
    'shippingMethodId',
  ]
  const required = [
    'id',
    'cartRevision',
    'email',
    'fingerprint',
    'expiresAt',
    'acceptedAt',
    'priceMode',
    'requiresDelivery',
    'digitalOnly',
    'coupon',
    'merchandise',
    'discount',
    'shipping',
    'tax',
    'grandTotal',
    'lines',
  ]
  if (!keysAllowed(value, allowed, required)) return null
  if (
    !uuid(value.id) ||
    !integer(value.cartRevision, 1, 4294967295) ||
    typeof value.email !== 'string' ||
    value.email.length < 3 ||
    value.email.length > 254 ||
    typeof value.fingerprint !== 'string' ||
    value.fingerprint.length !== 64 ||
    typeof value.expiresAt !== 'string' ||
    (value.acceptedAt !== null && typeof value.acceptedAt !== 'string') ||
    (value.priceMode !== 'exclusive' && value.priceMode !== 'inclusive') ||
    typeof value.requiresDelivery !== 'boolean' ||
    typeof value.digitalOnly !== 'boolean' ||
    (value.coupon !== null && typeof value.coupon !== 'string') ||
    !money(value.merchandise) ||
    !money(value.discount) ||
    !money(value.shipping) ||
    !money(value.tax) ||
    !money(value.grandTotal) ||
    !Array.isArray(value.lines) ||
    value.lines.length < 1 ||
    value.lines.length > 100 ||
    !value.lines.every(line)
  )
    return null
  return {
    id: value.id,
    cartRevision: value.cartRevision,
    email: value.email,
    fingerprint: value.fingerprint,
    expiresAt: value.expiresAt,
    acceptedAt: value.acceptedAt,
    priceMode: value.priceMode,
    requiresDelivery: value.requiresDelivery,
    digitalOnly: value.digitalOnly,
    coupon: value.coupon,
    merchandise: value.merchandise,
    discount: value.discount,
    shipping: value.shipping,
    tax: value.tax,
    grandTotal: value.grandTotal,
    lines: value.lines,
  }
}
