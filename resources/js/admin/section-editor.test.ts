import { describe, expect, it } from 'vitest'
import {
  createSection,
  defaultSettings,
  errorsForSection,
  moveSection,
  toPayloadSection,
  uniqueSectionId,
  type SectionDefinition,
  type SectionInstance,
} from './section-editor'

const hero: SectionDefinition = {
  type: 'hero',
  version: 1,
  label: 'Hero',
  maxPerPage: 3,
  binding: null,
  settings: [
    { key: 'heading', type: 'text', required: true, maxLength: 200 },
    { key: 'subheading', type: 'text', required: false, maxLength: 300 },
    {
      key: 'alignment',
      type: 'enum',
      required: false,
      options: ['left', 'center'],
      default: 'left',
    },
    {
      key: 'cta',
      type: 'object',
      required: false,
      fields: [
        { key: 'label', type: 'text', required: true, maxLength: 80 },
        { key: 'path', type: 'path', required: true },
      ],
    },
  ],
}

const grid: SectionDefinition = {
  type: 'product-grid',
  version: 1,
  label: 'Product grid',
  maxPerPage: 5,
  binding: { required: true, kinds: ['category', 'products'], maxItems: 20 },
  settings: [
    { key: 'heading', type: 'text', required: false, maxLength: 200 },
    { key: 'limit', type: 'integer', required: false, min: 1, max: 24, default: 8 },
  ],
}

const section = (id: string, type = 'rich-text'): SectionInstance => ({
  id,
  type,
  version: 1,
  settings: {},
})

describe('section editor helpers', () => {
  it('derives defaults from the field definitions', () => {
    expect(defaultSettings(hero.settings)).toEqual({
      heading: '',
      subheading: '',
      alignment: 'left',
      cta: { label: '', path: '' },
    })
    expect(defaultSettings(grid.settings)).toEqual({ heading: '', limit: 8 })
  })

  it('creates sections with unique bounded ids and a default binding', () => {
    const first = createSection(grid, [])
    expect(first.id).toBe('product-grid')
    expect(first.binding).toEqual({ kind: 'category', publicId: '' })
    const second = createSection(grid, [first])
    expect(second.id).toBe('product-grid-2')
    expect(uniqueSectionId('hero', [section('hero', 'hero'), section('hero-2', 'hero')])).toBe(
      'hero-3',
    )
  })

  it('moves sections immutably and stops at the edges', () => {
    const list = [section('a'), section('b'), section('c')]
    expect(moveSection(list, 0, 1).map((entry) => entry.id)).toEqual(['b', 'a', 'c'])
    expect(moveSection(list, 2, -1).map((entry) => entry.id)).toEqual(['a', 'c', 'b'])
    expect(moveSection(list, 0, -1)).toBe(list)
    expect(moveSection(list, 2, 1)).toBe(list)
    expect(list.map((entry) => entry.id)).toEqual(['a', 'b', 'c'])
  })

  it('prunes empty optional values but keeps required fields for the server to name', () => {
    const payload = toPayloadSection(
      {
        id: 'intro',
        type: 'hero',
        version: 1,
        settings: {
          heading: '',
          subheading: '',
          alignment: 'center',
          cta: { label: '', path: '' },
        },
      },
      hero,
    )
    expect(payload.settings).toEqual({ heading: '', alignment: 'center' })

    const filled = toPayloadSection(
      {
        id: 'intro',
        type: 'hero',
        version: 1,
        settings: {
          heading: 'Hi',
          subheading: '',
          alignment: 'left',
          cta: { label: 'Shop', path: '/categories' },
        },
      },
      hero,
    )
    expect(filled.settings).toEqual({
      heading: 'Hi',
      alignment: 'left',
      cta: { label: 'Shop', path: '/categories' },
    })
  })

  it('sends a required binding even before a reference is chosen', () => {
    const created = createSection(grid, [])
    const payload = toPayloadSection(created, grid)
    expect(payload.binding).toEqual({ kind: 'category', publicId: '' })
    const chosen = toPayloadSection(
      { ...created, binding: { kind: 'products', publicIds: ['p-1', '', 'p-2'] } },
      grid,
    )
    expect(chosen.binding).toEqual({ kind: 'products', publicIds: ['p-1', 'p-2'] })
  })

  it('collects the errors grouped for one section by id and by position', () => {
    const errors = {
      document: ['A page may contain at most 100 sections.'],
      sections: { intro: ['Section one problem'], 'position-2': ['Section two problem'] },
    }
    expect(errorsForSection(errors, section('intro'), 0)).toEqual(['Section one problem'])
    expect(errorsForSection(errors, section('other'), 1)).toEqual(['Section two problem'])
    expect(errorsForSection(errors, section('other'), 0)).toEqual([])
  })
})
