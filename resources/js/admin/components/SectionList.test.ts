import { mount } from '@vue/test-utils'
import { afterEach, describe, expect, it } from 'vitest'
import type { SectionDefinition, SectionInstance } from '../section-editor'
import SectionList from './SectionList.vue'

const definitions: SectionDefinition[] = [
  { type: 'hero', version: 1, label: 'Hero', settings: [], binding: null, maxPerPage: 1 },
  {
    type: 'rich-text',
    version: 1,
    label: 'Rich text',
    settings: [],
    binding: null,
    maxPerPage: 20,
  },
]

const hero: SectionInstance = { id: 'hero', type: 'hero', version: 1, settings: {} }
const copy: SectionInstance = { id: 'copy', type: 'rich-text', version: 1, settings: {} }
const notes: SectionInstance = { id: 'notes', type: 'rich-text', version: 1, settings: {} }

function mountList(sections: SectionInstance[]) {
  return mount(SectionList, {
    attachTo: document.body,
    props: {
      sections,
      definitions,
      errors: { document: [], sections: {} },
      selectedId: null,
      canWrite: true,
    },
  })
}

afterEach(() => {
  document.body.innerHTML = ''
})

describe('SectionList', () => {
  it('moves a section down with the button, announces it and keeps focus on the moved section', async () => {
    const wrapper = mountList([hero, copy])
    await wrapper.get('[data-section-id="hero"] [data-action="move-down"]').trigger('click')

    const update = wrapper.emitted('update')?.[0]?.[0] as SectionInstance[]
    expect(update.map((section) => section.id)).toEqual(['copy', 'hero'])
    expect(wrapper.emitted('announce')?.[0]).toEqual(['Hero moved to position 2 of 2.'])

    await wrapper.setProps({ sections: update })
    const moved = wrapper.get('[data-section-id="hero"] [data-action="move-down"]')
    expect(document.activeElement).toBe(moved.element)
  })

  it('reorders with Alt + arrow keys on a section', async () => {
    const wrapper = mountList([hero, copy, notes])
    await wrapper
      .get('[data-section-id="copy"]')
      .trigger('keydown', { key: 'ArrowUp', altKey: true })
    const update = wrapper.emitted('update')?.[0]?.[0] as SectionInstance[]
    expect(update.map((section) => section.id)).toEqual(['copy', 'hero', 'notes'])
    expect(wrapper.emitted('announce')?.[0]).toEqual(['Rich text moved to position 1 of 3.'])
  })

  it('moves focus to the neighbouring section after a removal', async () => {
    const wrapper = mountList([hero, copy, notes])
    await wrapper.get('[data-section-id="copy"] [data-action="remove"]').trigger('click')
    const update = wrapper.emitted('update')?.[0]?.[0] as SectionInstance[]
    expect(update.map((section) => section.id)).toEqual(['hero', 'notes'])
    await wrapper.setProps({ sections: update })
    expect(document.activeElement).toBe(
      wrapper.get('[data-section-id="notes"] [data-action="configure"]').element,
    )
  })

  it('moves focus to the add control when the last section is removed', async () => {
    const wrapper = mountList([hero])
    await wrapper.get('[data-section-id="hero"] [data-action="remove"]').trigger('click')
    await wrapper.setProps({ sections: [] })
    expect(document.activeElement).toBe(wrapper.get('[data-action="add-type"]').element)
  })

  it('disables move buttons at the edges and block types at their limit', async () => {
    const wrapper = mountList([hero, copy])
    expect(
      wrapper.get('[data-section-id="hero"] [data-action="move-up"]').attributes('disabled'),
    ).toBeDefined()
    expect(
      wrapper.get('[data-section-id="copy"] [data-action="move-down"]').attributes('disabled'),
    ).toBeDefined()
    const heroOption = wrapper.get('option[value="hero"]')
    expect(heroOption.attributes('disabled')).toBeDefined()
    expect(heroOption.text()).toContain('limit 1 reached')
  })

  it('emits add for a chosen block type', async () => {
    const wrapper = mountList([copy])
    await wrapper.get('[data-action="add-type"]').setValue('rich-text')
    await wrapper.get('[data-action="add"]').trigger('click')
    expect(wrapper.emitted('add')?.[0]).toEqual(['rich-text'])
  })

  it('shows the errors grouped for a section under that section', () => {
    const wrapper = mount(SectionList, {
      props: {
        sections: [hero, copy],
        definitions,
        errors: {
          document: [],
          sections: { copy: ["Section 'copy' (rich-text) setting 'text' is missing."] },
        },
        selectedId: null,
        canWrite: true,
      },
    })
    const item = wrapper.get('[data-section-id="copy"]')
    expect(item.text()).toContain("setting 'text' is missing")
    expect(wrapper.get('[data-section-id="hero"]').text()).not.toContain('is missing')
  })
})
