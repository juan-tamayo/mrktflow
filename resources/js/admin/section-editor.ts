/**
 * Pure helpers for the section editor (THEME-02, WEB-06, T064b).
 *
 * The editor's working copy holds form state for every registry field;
 * the payload sent to the server is derived from it by `toPayload`, which
 * drops empty optional values the typed validator would reject (an empty
 * optional text is not a valid text) while keeping required fields in
 * place so the server can name the exact problem. Nothing here renders:
 * SectionList, SectionSettings and ContentEditor compose these helpers.
 */

export type SectionFieldDefinition = {
  key: string
  type: 'text' | 'integer' | 'boolean' | 'enum' | 'path' | 'object' | 'list'
  required: boolean
  maxLength?: number
  min?: number
  max?: number
  default?: unknown
  options?: string[]
  fields?: SectionFieldDefinition[]
  itemFields?: SectionFieldDefinition[]
  minItems?: number
  maxItems?: number
}

export type SectionBindingSpec = {
  required: boolean
  kinds: string[]
  maxItems: number
  minItems?: number
}

export type SectionDefinition = {
  type: string
  version: number
  label: string
  settings: SectionFieldDefinition[]
  binding: SectionBindingSpec | null
  maxPerPage: number
}

export type SectionBinding = {
  kind: string
  publicId?: string
  publicIds?: string[]
}

export type SectionInstance = {
  id: string
  type: string
  version: number
  settings: Record<string, unknown>
  binding?: SectionBinding
}

export type GroupedSectionErrors = {
  document: string[]
  sections: Record<string, string[]>
}

export const EMPTY_ERRORS: GroupedSectionErrors = { document: [], sections: {} }

/** 'imagePosition' -> 'Image position'; 'cta' -> 'Cta'. */
export function fieldLabel(key: string): string {
  const spaced = key.replace(/([a-z0-9])([A-Z])/g, '$1 $2').toLowerCase()
  return spaced.charAt(0).toUpperCase() + spaced.slice(1)
}

export function defaultFieldValue(field: SectionFieldDefinition): unknown {
  switch (field.type) {
    case 'text':
    case 'path':
      return ''
    case 'integer':
      return typeof field.default === 'number' ? field.default : ''
    case 'boolean':
      return typeof field.default === 'boolean' ? field.default : false
    case 'enum':
      return typeof field.default === 'string' ? field.default : (field.options?.[0] ?? '')
    case 'object':
      return defaultSettings(field.fields ?? [])
    case 'list': {
      const items: Record<string, unknown>[] = []
      const count = field.minItems ?? 0
      for (let index = 0; index < count; index += 1) {
        items.push(defaultSettings(field.itemFields ?? []))
      }
      return items
    }
  }
}

export function defaultSettings(fields: SectionFieldDefinition[]): Record<string, unknown> {
  const settings: Record<string, unknown> = {}
  for (const field of fields) {
    settings[field.key] = defaultFieldValue(field)
  }
  return settings
}

export function defaultBinding(spec: SectionBindingSpec): SectionBinding {
  const kind = spec.kinds[0] ?? 'product'
  if (kind === 'product' || kind === 'category') return { kind, publicId: '' }
  return { kind, publicIds: [] }
}

/** Section ids are bounded lowercase identifiers; derive one from the block type. */
export function uniqueSectionId(type: string, existing: SectionInstance[]): string {
  const taken = new Set(existing.map((section) => section.id))
  if (!taken.has(type)) return type
  let suffix = 2
  while (taken.has(`${type}-${suffix}`)) suffix += 1
  return `${type}-${suffix}`
}

export function createSection(
  definition: SectionDefinition,
  existing: SectionInstance[],
): SectionInstance {
  const section: SectionInstance = {
    id: uniqueSectionId(definition.type, existing),
    type: definition.type,
    version: definition.version,
    settings: defaultSettings(definition.settings),
  }
  if (definition.binding) section.binding = defaultBinding(definition.binding)
  return section
}

export function countByType(sections: SectionInstance[], type: string): number {
  return sections.filter((section) => section.type === type).length
}

/** Returns a new list with the section at `index` swapped by `delta`, or the same list at an edge. */
export function moveSection(
  sections: SectionInstance[],
  index: number,
  delta: -1 | 1,
): SectionInstance[] {
  const target = index + delta
  if (index < 0 || index >= sections.length || target < 0 || target >= sections.length) {
    return sections
  }
  const next = [...sections]
  const [moved] = next.splice(index, 1)
  if (!moved) return sections
  next.splice(target, 0, moved)
  return next
}

function isEmptyValue(value: unknown): boolean {
  return value === '' || value === null || value === undefined
}

/** True when a pruned value carries no merchant input at all (only empty leaves). */
function isDeepEmpty(value: unknown): boolean {
  if (isEmptyValue(value)) return true
  if (Array.isArray(value)) return value.every((item) => isDeepEmpty(item))
  if (typeof value === 'object') {
    return Object.values(value as Record<string, unknown>).every((entry) => isDeepEmpty(entry))
  }
  return false
}

function pruneFields(
  fields: SectionFieldDefinition[],
  values: Record<string, unknown>,
): Record<string, unknown> {
  const pruned: Record<string, unknown> = {}
  for (const field of fields) {
    const value = values[field.key]
    if (isEmptyValue(value)) {
      // Required empties stay so the server names the missing field;
      // optional empties are omitted — an empty optional text is invalid.
      if (field.required) pruned[field.key] = field.type === 'list' ? [] : ''
      continue
    }
    if (field.type === 'object' && typeof value === 'object' && !Array.isArray(value)) {
      const inner = pruneFields(field.fields ?? [], value as Record<string, unknown>)
      // An untouched optional object (every leaf empty) is omitted, not
      // sent to fail validation on fields the merchant never filled.
      if (field.required || !isDeepEmpty(inner)) pruned[field.key] = inner
      continue
    }
    if (field.type === 'list' && Array.isArray(value)) {
      let items = value.map((item) =>
        pruneFields(field.itemFields ?? [], (item ?? {}) as Record<string, unknown>),
      )
      if (!field.required) items = items.filter((item) => !isDeepEmpty(item))
      if (items.length > 0 || field.required) pruned[field.key] = items
      continue
    }
    pruned[field.key] = value
  }
  return pruned
}

/**
 * The wire form of one section: settings pruned against the definition,
 * binding included only when it carries a reference (an untouched
 * optional binding is omitted; a required one is sent so the server can
 * name it).
 */
export function toPayloadSection(
  section: SectionInstance,
  definition: SectionDefinition,
): Record<string, unknown> {
  const payload: Record<string, unknown> = {
    id: section.id,
    type: section.type,
    version: section.version,
    settings: pruneFields(definition.settings, section.settings),
  }
  const spec = definition.binding
  const binding = section.binding
  if (spec && binding) {
    if (binding.kind === 'product' || binding.kind === 'category') {
      if ((binding.publicId ?? '') !== '' || spec.required) {
        payload.binding = { kind: binding.kind, publicId: binding.publicId ?? '' }
      }
    } else {
      const ids = (binding.publicIds ?? []).filter((id) => id !== '')
      if (ids.length > 0 || spec.required) {
        payload.binding = { kind: binding.kind, publicIds: ids }
      }
    }
  }
  return payload
}

export function toPayload(
  sections: SectionInstance[],
  definitions: Map<string, SectionDefinition>,
): Record<string, unknown>[] {
  return sections.map((section) => {
    const definition = definitions.get(section.type)
    if (!definition) {
      return {
        id: section.id,
        type: section.type,
        version: section.version,
        settings: section.settings,
        ...(section.binding ? { binding: section.binding } : {}),
      }
    }
    return toPayloadSection(section, definition)
  })
}

/** Errors the server grouped for one section: by id, plus its document position when the id itself failed. */
export function errorsForSection(
  errors: GroupedSectionErrors,
  section: SectionInstance,
  index: number,
): string[] {
  return [
    ...(errors.sections[section.id] ?? []),
    ...(errors.sections[`position-${index + 1}`] ?? []),
  ]
}

export function errorCount(errors: GroupedSectionErrors): number {
  return (
    errors.document.length +
    Object.values(errors.sections).reduce((total, list) => total + list.length, 0)
  )
}
