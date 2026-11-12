export interface SpecAttribute {
  label: string
  value: string
}
export interface SpecSheetInput {
  title: string
  attributes: SpecAttribute[]
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
function attribute(value: unknown): value is SpecAttribute {
  return (
    record(value) &&
    keysAllowed(value, ['label', 'value'], ['label', 'value']) &&
    typeof value.label === 'string' &&
    value.label.length >= 1 &&
    value.label.length <= 120 &&
    typeof value.value === 'string' &&
    value.value.length >= 1 &&
    value.value.length <= 500
  )
}

export function parseSpecSheet(value: unknown): SpecSheetInput | null {
  if (!record(value) || !keysAllowed(value, ['title', 'attributes'], ['title', 'attributes']))
    return null
  if (typeof value.title !== 'string' || value.title.length < 1 || value.title.length > 200)
    return null
  if (!Array.isArray(value.attributes) || value.attributes.length > 30) return null
  if (!value.attributes.every(attribute)) return null
  return { title: value.title, attributes: value.attributes }
}
