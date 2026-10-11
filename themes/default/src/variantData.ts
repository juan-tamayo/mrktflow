export interface PublicVariant {
  id: string
  label: string
  money: { amountMinor: number; currency: string; exponent: number; formatted: string }
}
export interface VariantPickerInput {
  variants: PublicVariant[]
  selectedId: string
}

function record(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}
function keys(value: Record<string, unknown>, allowed: string[]): boolean {
  return (
    Object.keys(value).length === allowed.length &&
    Object.keys(value).every((key) => allowed.includes(key))
  )
}
function variant(value: unknown): value is PublicVariant {
  if (
    !record(value) ||
    !keys(value, ['id', 'label', 'money']) ||
    typeof value.id !== 'string' ||
    !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value.id) ||
    typeof value.label !== 'string' ||
    value.label.length < 1 ||
    value.label.length > 120 ||
    !record(value.money)
  )
    return false
  const money = value.money
  return (
    keys(money, ['amountMinor', 'currency', 'exponent', 'formatted']) &&
    typeof money.amountMinor === 'number' &&
    Number.isSafeInteger(money.amountMinor) &&
    money.amountMinor >= 0 &&
    typeof money.currency === 'string' &&
    /^[A-Z]{3}$/.test(money.currency) &&
    typeof money.exponent === 'number' &&
    Number.isInteger(money.exponent) &&
    money.exponent >= 0 &&
    money.exponent <= 3 &&
    typeof money.formatted === 'string' &&
    money.formatted.length > 0 &&
    money.formatted.length <= 100
  )
}
export function parseVariantPicker(value: unknown): VariantPickerInput | null {
  if (
    !record(value) ||
    !keys(value, ['variants', 'selectedId']) ||
    !Array.isArray(value.variants) ||
    value.variants.length < 1 ||
    value.variants.length > 50 ||
    !value.variants.every(variant) ||
    typeof value.selectedId !== 'string' ||
    !value.variants.some((entry) => entry.id === value.selectedId) ||
    new Set(value.variants.map((entry) => entry.id.toLowerCase())).size !== value.variants.length
  )
    return null
  return { variants: value.variants, selectedId: value.selectedId }
}
