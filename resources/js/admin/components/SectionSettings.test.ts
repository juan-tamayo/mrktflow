import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { SectionDefinition, SectionInstance } from '../section-editor'
import SectionSettings from './SectionSettings.vue'

const hero: SectionDefinition = {
  type: 'hero',
  version: 1,
  label: 'Hero',
  maxPerPage: 3,
  binding: null,
  settings: [
    { key: 'heading', type: 'text', required: true, maxLength: 200 },
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

const gallery: SectionDefinition = {
  type: 'gallery',
  version: 1,
  label: 'Gallery',
  maxPerPage: 5,
  binding: null,
  settings: [
    { key: 'heading', type: 'text', required: false, maxLength: 200 },
    {
      key: 'images',
      type: 'list',
      required: true,
      minItems: 1,
      maxItems: 12,
      itemFields: [
        { key: 'url', type: 'path', required: true },
        { key: 'alt', type: 'text', required: true, maxLength: 200 },
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

describe('SectionSettings', () => {
  it('generates controls from the typed field definitions, including nested object fields', async () => {
    const section: SectionInstance = {
      id: 'intro',
      type: 'hero',
      version: 1,
      settings: { heading: 'Hello', alignment: 'left', cta: { label: '', path: '' } },
    }
    const wrapper = mount(SectionSettings, {
      props: { definition: hero, section, errors: [], canWrite: true },
    })
    const heading = wrapper.get('#section-intro-heading')
    expect(heading.attributes('maxlength')).toBe('200')
    expect((heading.element as HTMLInputElement).value).toBe('Hello')
    const alignment = wrapper.get('#section-intro-alignment')
    expect(alignment.findAll('option').map((option) => option.attributes('value'))).toEqual([
      'left',
      'center',
    ])
    expect(wrapper.find('#section-intro-cta-label').exists()).toBe(true)
    expect(wrapper.get('#section-intro-cta-path').attributes('placeholder')).toBe('/local/path')

    await heading.setValue('New heading')
    const update = wrapper.emitted('update')?.at(-1)?.[0] as SectionInstance
    expect(update.settings.heading).toBe('New heading')
    expect(section.settings.heading).toBe('Hello')
  })

  it('edits list items with add and remove controls bounded by the definition', async () => {
    const section: SectionInstance = {
      id: 'photos',
      type: 'gallery',
      version: 1,
      settings: { heading: '', images: [{ url: '/a.jpg', alt: 'A' }] },
    }
    const wrapper = mount(SectionSettings, {
      props: { definition: gallery, section, errors: [], canWrite: true },
    })
    expect(wrapper.find('#section-photos-images-0-url').exists()).toBe(true)
    // minItems is 1: the only entry cannot be removed.
    expect(wrapper.get('[aria-label="Remove Images entry 1"]').attributes('disabled')).toBeDefined()

    const buttons = wrapper.findAll('button')
    const add = buttons.find((button) => button.text() === 'Add entry')
    if (!add) throw new Error('Add entry button missing')
    await add.trigger('click')
    const update = wrapper.emitted('update')?.at(-1)?.[0] as SectionInstance
    expect(update.settings.images).toEqual([
      { url: '/a.jpg', alt: 'A' },
      { url: '', alt: '' },
    ])
  })

  it('renders the binding editor from the definition kinds and switches reference shapes', async () => {
    const section: SectionInstance = {
      id: 'grid',
      type: 'product-grid',
      version: 1,
      settings: { heading: '', limit: 8 },
      binding: { kind: 'category', publicId: '' },
    }
    const wrapper = mount(SectionSettings, {
      props: { definition: grid, section, errors: [], canWrite: true },
    })
    const limit = wrapper.get('#section-grid-limit')
    expect(limit.attributes('min')).toBe('1')
    expect(limit.attributes('max')).toBe('24')
    const kind = wrapper.get('#binding-kind-grid')
    expect(kind.findAll('option').map((option) => option.attributes('value'))).toEqual([
      'category',
      'products',
    ])
    expect(wrapper.find('#binding-ref-grid').exists()).toBe(true)

    await kind.setValue('products')
    const update = wrapper.emitted('update')?.at(-1)?.[0] as SectionInstance
    expect(update.binding).toEqual({ kind: 'products', publicIds: [] })

    await wrapper.setProps({
      section: { ...section, binding: { kind: 'products', publicIds: ['p-1'] } },
    })
    expect(wrapper.find('#binding-ref-grid-0').exists()).toBe(true)
    const addButtons = wrapper.findAll('button')
    const add = addButtons.find((button) => button.text() === 'Add reference')
    if (!add) throw new Error('Add reference button missing')
    await add.trigger('click')
    const updated = wrapper.emitted('update')?.at(-1)?.[0] as SectionInstance
    expect(updated.binding?.publicIds).toEqual(['p-1', ''])
  })

  it('shows the errors for the section above the form', () => {
    const section: SectionInstance = {
      id: 'intro',
      type: 'hero',
      version: 1,
      settings: { heading: '', alignment: 'left', cta: { label: '', path: '' } },
    }
    const wrapper = mount(SectionSettings, {
      props: {
        definition: hero,
        section,
        errors: ["Section 'intro' (hero) is missing the required setting 'heading'."],
        canWrite: true,
      },
    })
    expect(wrapper.text()).toContain("missing the required setting 'heading'")
  })
})
