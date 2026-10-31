import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ProductActions from './ProductActions.vue'
import {
  createCompareTrayStore,
  type CompareTrayEntry,
  type CompareTrayPersistence,
} from './compareData'

const alphaProduct = '10000000-0000-4000-8000-000000000001'
const alphaVariant = '10000000-0000-4000-8000-000000000011'
const pineVariant = '10000000-0000-4000-8000-000000000012'

function memoryStore(initial: CompareTrayEntry[] = []) {
  const persistence: CompareTrayPersistence = {
    load: () => initial,
    save: () => undefined,
  }
  return createCompareTrayStore(persistence)
}

describe('ProductActions', () => {
  it('toggles a product-card selection on and off with announcements', async () => {
    const store = memoryStore()
    const wrapper = mount(ProductActions, {
      props: {
        productId: alphaProduct,
        title: 'Alpha Camera',
        variants: [],
        selectedId: null,
        store,
      },
    })
    const button = wrapper.get('button')
    expect(button.attributes('aria-pressed')).toBe('false')
    expect(button.text()).toContain('Compare')
    await button.trigger('click')
    expect(store.tray.value).toEqual([
      { productId: alphaProduct, variantId: null, title: 'Alpha Camera', variantLabel: 'Standard' },
    ])
    expect(button.attributes('aria-pressed')).toBe('true')
    expect(wrapper.get('[role="status"]').text()).toContain('added to compare')
    await button.trigger('click')
    expect(store.tray.value).toEqual([])
    expect(button.attributes('aria-pressed')).toBe('false')
    expect(wrapper.get('[role="status"]').text()).toContain('removed from compare')
  })

  it('uses the server-selected variant on the product page', async () => {
    const store = memoryStore()
    const wrapper = mount(ProductActions, {
      props: {
        productId: alphaProduct,
        title: 'Alpha Camera',
        variants: [
          { id: alphaVariant, label: 'Standard' },
          { id: pineVariant, label: 'Pine' },
        ],
        selectedId: pineVariant,
        store,
      },
    })
    await wrapper.get('button').trigger('click')
    expect(store.tray.value).toEqual([
      {
        productId: alphaProduct,
        variantId: pineVariant,
        title: 'Alpha Camera',
        variantLabel: 'Pine',
      },
    ])
  })

  it('asks for a variant before adding when none is selected', async () => {
    const store = memoryStore()
    const wrapper = mount(ProductActions, {
      props: {
        productId: alphaProduct,
        title: 'Alpha Camera',
        variants: [
          { id: alphaVariant, label: 'Standard' },
          { id: pineVariant, label: 'Pine' },
        ],
        selectedId: null,
        store,
      },
    })
    const button = wrapper.get('button')
    expect(button.attributes('disabled')).toBeDefined()
    await wrapper.get('select').setValue(alphaVariant)
    expect(button.attributes('disabled')).toBeUndefined()
    await button.trigger('click')
    expect(store.tray.value[0]?.variantId).toBe(alphaVariant)
  })

  it('shows a card-added product as pressed on the product page and removes that entry', async () => {
    const store = memoryStore([
      { productId: alphaProduct, variantId: null, title: 'Alpha Camera', variantLabel: 'Standard' },
    ])
    const wrapper = mount(ProductActions, {
      props: {
        productId: alphaProduct,
        title: 'Alpha Camera',
        variants: [{ id: alphaVariant, label: 'Standard' }],
        selectedId: alphaVariant,
        store,
      },
    })
    const button = wrapper.get('button')
    expect(button.attributes('aria-pressed')).toBe('true')
    await button.trigger('click')
    expect(store.tray.value).toEqual([])
  })

  it('refuses a fifth product with a clear message and keeps the tray intact', async () => {
    const full: CompareTrayEntry[] = [2, 3, 4, 5].map((n) => ({
      productId: `10000000-0000-4000-8000-00000000000${n}`,
      variantId: null,
      title: `Product ${n}`,
      variantLabel: 'Standard',
    }))
    const store = memoryStore(full)
    const wrapper = mount(ProductActions, {
      props: {
        productId: alphaProduct,
        title: 'Alpha Camera',
        variants: [],
        selectedId: null,
        store,
      },
    })
    await wrapper.get('button').trigger('click')
    expect(store.tray.value).toEqual(full)
    expect(wrapper.get('[role="status"]').text()).toContain('up to four items')
    expect(wrapper.get('[role="status"]').text()).toContain('Remove an item')
  })
})
