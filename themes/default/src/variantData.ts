export interface PublicVariant {
  id: string
  label: string
  money: { amountMinor: number; currency: string; exponent: number; formatted: string }
  available: boolean
  options: { name: string; value: string }[]
  image: { url: string; alt: string }
}
export interface OptionAxis {
  name: string
  values: string[]
}
export interface VariantPickerInput {
  variants: PublicVariant[]
  selectedId: string
  optionAxes: OptionAxis[]
  selectionState: 'selected' | 'missing' | 'unavailable'
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
function option(value: unknown): value is { name: string; value: string } {
  return (
    record(value) &&
    keysAllowed(value, ['name', 'value'], ['name', 'value']) &&
    typeof value.name === 'string' &&
    value.name.length >= 1 &&
    value.name.length <= 40 &&
    typeof value.value === 'string' &&
    value.value.length >= 1 &&
    value.value.length <= 40
  )
}
function axis(value: unknown): value is OptionAxis {
  return (
    record(value) &&
    keysAllowed(value, ['name', 'values'], ['name', 'values']) &&
    typeof value.name === 'string' &&
    value.name.length >= 1 &&
    value.name.length <= 40 &&
    Array.isArray(value.values) &&
    value.values.length >= 1 &&
    value.values.length <= 20 &&
    value.values.every(
      (entry) => typeof entry === 'string' && entry.length >= 1 && entry.length <= 40,
    )
  )
}
function variant(value: unknown): value is PublicVariant {
  if (
    !record(value) ||
    !keysAllowed(
      value,
      ['id', 'label', 'money', 'options', 'available', 'image'],
      ['id', 'label', 'money'],
    ) ||
    typeof value.id !== 'string' ||
    !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(value.id) ||
    typeof value.label !== 'string' ||
    value.label.length < 1 ||
    value.label.length > 120 ||
    !record(value.money)
  )
    return false
  const money = value.money
  if (!(
    keysAllowed(
      money,
      ['amountMinor', 'currency', 'exponent', 'formatted'],
      ['amountMinor', 'currency', 'exponent', 'formatted'],
    ) &&
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
  ))
    return false
  const options = value.options ?? []
  const available = value.available ?? true
  const image = value.image
  if (
    !Array.isArray(options) ||
    options.length > 3 ||
    !options.every(option) ||
    typeof available !== 'boolean'
  )
    return false
  if (image !== undefined) {
    if (
      !record(image) ||
      !keysAllowed(image, ['url', 'alt'], ['url', 'alt']) ||
      typeof image.url !== 'string' ||
      !/^\/(?!\/)[A-Za-z0-9/_.-]*$/.test(image.url) ||
      typeof image.alt !== 'string' ||
      image.alt.length < 1 ||
      image.alt.length > 200
    )
      return false
  }
  return true
}
export function parseVariantPicker(value: unknown): VariantPickerInput | null {
  if (
    !record(value) ||
    !keysAllowed(
      value,
      ['variants', 'selectedId', 'optionAxes', 'selectionState'],
      ['variants', 'selectedId'],
    ) ||
    !Array.isArray(value.variants) ||
    value.variants.length < 1 ||
    value.variants.length > 50 ||
    !value.variants.every(variant) ||
    typeof value.selectedId !== 'string' ||
    !value.variants.some((entry) => (entry as PublicVariant).id === value.selectedId) ||
    new Set(value.variants.map((entry) => (entry as PublicVariant).id.toLowerCase())).size !==
      value.variants.length
  )
    return null
  const optionAxes = Array.isArray(value.optionAxes) ? value.optionAxes : []
  if (optionAxes.length > 3 || !optionAxes.every(axis)) return null
  const selectionState =
    value.selectionState === 'missing' || value.selectionState === 'unavailable'
      ? value.selectionState
      : 'selected'
  return {
    variants: value.variants.map((entry) => {
      const item = entry as PublicVariant
      return {
        id: item.id,
        label: item.label,
        money: item.money,
        available: item.available ?? true,
        options: item.options ?? [],
        image: item.image ?? { url: '/brand/mark.svg', alt: item.label },
      }
    }),
    selectedId: value.selectedId,
    optionAxes,
    selectionState,
  }
}
