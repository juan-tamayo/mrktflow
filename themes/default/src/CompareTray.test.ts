import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CompareTray from './CompareTray.vue'
import {
  createCompareTrayStore,
  type CompareTrayEntry,
  type CompareTrayPersistence,
} from './compareData'

const alpha: CompareTrayEntry = {
  productId: '10000000-0000-4000-8000-000000000001',
  variantId: '10000000-0000-4000-8000-000000000011',
  title: 'Alpha Camera',
  variantLabel: 'Standard',
}
const beta: CompareTrayEntry = {
  productId: '10000000-0000-4000-8000-000000000002',
  variantId: null,
  title: 'Beta Camera',
  variantLabel: 'Standard',
}

function memoryStore(initial: CompareTrayEntry[] = []) {
  const persistence: CompareTrayPersistence = { load: () => initial, save: () => undefined }
  return createCompareTrayStore(persistence)
}

describe('CompareTray', () => {
  it('renders nothing while the tray is empty', () => {
    const wrapper = mount(CompareTray, { props: { store: memoryStore() } })
    expect(wrapper.find('section').exists()).toBe(false)
    expect(wrapper.find('a').exists()).toBe(false)
  })

  it('shows entries and asks for one more before comparing a single item', () => {
    const wrapper = mount(CompareTray, { props: { store: memoryStore([alpha]) } })
    expect(wrapper.text()).toContain('Alpha Camera')
    expect(wrapper.text()).toContain('1 of 4')
    expect(wrapper.text()).toContain('Add one more item to compare')
    expect(wrapper.find('a[href^="/compare"]').exists()).toBe(false)
  })

  it('links to the comparison once two items are in the tray', () => {
    const wrapper = mount(CompareTray, { props: { store: memoryStore([alpha, beta]) } })
    const link = wrapper.get('a[href^="/compare"]')
    expect(link.attributes('href')).toBe(
      '/compare?items[]=10000000-0000-4000-8000-000000000011&products[]=10000000-0000-4000-8000-000000000002',
    )
    expect(wrapper.text()).toContain('2 of 4')
  })

  it('removes an entry from the tray with an announcement', async () => {
    const store = memoryStore([alpha, beta])
    const wrapper = mount(CompareTray, { props: { store } })
    await wrapper.get('button[aria-label="Remove Alpha Camera from compare"]').trigger('click')
    expect(store.tray.value).toEqual([beta])
    expect(wrapper.get('[role="status"]').text()).toContain('Alpha Camera')
    expect(wrapper.get('[role="status"]').text()).toContain('removed from compare')
    const items = wrapper.findAll('li')
    expect(items).toHaveLength(1)
    expect(items[0]!.text()).toContain('Beta Camera')
  })

  it('labels a non-standard variant on its entry', () => {
    const wrapper = mount(CompareTray, {
      props: { store: memoryStore([{ ...alpha, variantLabel: 'Pine' }]) },
    })
    expect(wrapper.text()).toContain('Pine')
  })
})
