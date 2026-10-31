import { describe, expect, it, vi } from 'vitest'
import {
  addToTray,
  compareApiUrl,
  comparePageUrl,
  cookieCompareTrayPersistence,
  createCompareTrayStore,
  parseCompareTray,
  parseComparison,
  parseProductActions,
  readCompareTray,
  removeFromTray,
  serializeCompareTray,
  visibleComparisonRows,
  writeCompareTray,
  type CompareTrayEntry,
  type CompareTrayPersistence,
  type Comparison,
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
const gamma: CompareTrayEntry = {
  productId: '10000000-0000-4000-8000-000000000003',
  variantId: '10000000-0000-4000-8000-000000000013',
  title: 'Gamma Camera',
  variantLabel: 'Pine',
}
const delta: CompareTrayEntry = {
  productId: '10000000-0000-4000-8000-000000000004',
  variantId: null,
  title: 'Delta Notebook',
  variantLabel: 'Standard',
}
const epsilon: CompareTrayEntry = {
  productId: '10000000-0000-4000-8000-000000000005',
  variantId: '10000000-0000-4000-8000-000000000015',
  title: 'Epsilon Camera',
  variantLabel: 'Chalk',
}

describe('compare tray state', () => {
  it('adds selections in order and keeps variant and product selections distinct', () => {
    const first = addToTray([], alpha)
    expect(first.outcome).toBe('added')
    const second = addToTray(first.tray, beta)
    expect(second.outcome).toBe('added')
    expect(second.tray).toEqual([alpha, beta])
  })

  it('replaces the entry when the same product is added again with a different selection', () => {
    const start = addToTray([], beta).tray
    const asVariant: CompareTrayEntry = {
      ...beta,
      variantId: gamma.variantId,
      variantLabel: 'Pine',
    }
    const result = addToTray(start, asVariant)
    expect(result.outcome).toBe('replaced')
    expect(result.tray).toEqual([asVariant])
    const backToProduct = addToTray(result.tray, beta)
    expect(backToProduct.outcome).toBe('replaced')
    expect(backToProduct.tray).toEqual([beta])
  })

  it('refuses a fifth product and never drops the oldest entry', () => {
    const full = [alpha, beta, gamma, delta]
    const result = addToTray(full, epsilon)
    expect(result.outcome).toBe('full')
    expect(result.tray).toEqual(full)
  })

  it('still replaces an existing product when the tray is full', () => {
    const full = [alpha, beta, gamma, delta]
    const updated: CompareTrayEntry = { ...delta, variantId: epsilon.variantId }
    const result = addToTray(full, updated)
    expect(result.outcome).toBe('replaced')
    expect(result.tray).toEqual([alpha, beta, gamma, updated])
  })

  it('removes by product and leaves other entries untouched', () => {
    expect(removeFromTray([alpha, beta, gamma], beta.productId)).toEqual([alpha, gamma])
    expect(removeFromTray([alpha], epsilon.productId)).toEqual([alpha])
  })

  it('builds the compare page URL from variant and product selections', () => {
    expect(comparePageUrl([alpha, beta])).toBe(
      '/compare?items[]=10000000-0000-4000-8000-000000000011&products[]=10000000-0000-4000-8000-000000000002',
    )
    expect(comparePageUrl([])).toBe('/compare')
  })

  it('builds the read-model fetch URL from variant ids only', () => {
    expect(
      compareApiUrl([
        '10000000-0000-4000-8000-000000000011',
        '10000000-0000-4000-8000-000000000013',
      ]),
    ).toBe(
      '/store-api/v1/compare?items[]=10000000-0000-4000-8000-000000000011&items[]=10000000-0000-4000-8000-000000000013',
    )
  })
})

describe('compare tray cookie payload', () => {
  it('round-trips a tray through serialization', () => {
    const raw = serializeCompareTray([alpha, beta])
    expect(parseCompareTray(JSON.parse(decodeURIComponent(raw)))).toEqual([alpha, beta])
  })

  it('rejects malformed payloads instead of trusting them', () => {
    expect(parseCompareTray(null)).toBeNull()
    expect(parseCompareTray('tray')).toBeNull()
    expect(
      parseCompareTray([
        { productId: 'not-a-uuid', variantId: null, title: 'X', variantLabel: '' },
      ]),
    ).toBeNull()
    expect(
      parseCompareTray([
        { productId: alpha.productId, variantId: 'nope', title: 'X', variantLabel: '' },
      ]),
    ).toBeNull()
    expect(
      parseCompareTray([
        { productId: alpha.productId, variantId: null, title: '', variantLabel: '' },
      ]),
    ).toBeNull()
    expect(parseCompareTray([alpha, beta, gamma, delta, epsilon])).toBeNull()
    expect(parseCompareTray([alpha, { ...beta, productId: alpha.productId }])).toBeNull()
  })

  it('writes a session cookie and reads it back from a cookie jar', () => {
    const jar = { cookie: 'other=1' }
    writeCompareTray(jar, [alpha, beta])
    expect(jar.cookie).toContain('compare-tray=')
    expect(jar.cookie).toContain('Path=/')
    expect(jar.cookie).toContain('SameSite=Lax')
    expect(jar.cookie).not.toContain('Expires')
    expect(jar.cookie).not.toContain('Max-Age')
    const reread = { cookie: `session=abc; ${jar.cookie.split(';')[0]}` }
    expect(readCompareTray(reread)).toEqual([alpha, beta])
  })

  it('reads an absent or malformed cookie as an empty tray', () => {
    expect(readCompareTray({ cookie: '' })).toEqual([])
    expect(readCompareTray({ cookie: 'compare-tray=not-json' })).toEqual([])
    expect(readCompareTray({ cookie: 'compare-tray=%7B%22broken%22%3Atrue%7D' })).toEqual([])
  })

  it('clears the cookie when the tray is emptied', () => {
    const jar = { cookie: '' }
    writeCompareTray(jar, [])
    expect(jar.cookie).toContain('compare-tray=')
    expect(jar.cookie).toContain('Max-Age=0')
    expect(readCompareTray({ cookie: jar.cookie.split(';')[0]! })).toEqual([])
  })
})

describe('compare tray store', () => {
  function memoryPersistence(
    initial: CompareTrayEntry[],
  ): CompareTrayPersistence & { saved: CompareTrayEntry[][] } {
    const saved: CompareTrayEntry[][] = []
    return {
      saved,
      load: () => initial,
      save: (tray) => {
        saved.push(tray)
      },
    }
  }

  it('loads from persistence, announces changes and saves every mutation', () => {
    const persistence = memoryPersistence([alpha])
    const store = createCompareTrayStore(persistence)
    expect(store.tray.value).toEqual([alpha])
    expect(store.add(beta)).toBe('added')
    expect(store.announcement.value).toContain('Beta Camera')
    expect(store.announcement.value).toContain('added to compare')
    expect(persistence.saved.at(-1)).toEqual([alpha, beta])
    store.remove(alpha.productId)
    expect(store.tray.value).toEqual([beta])
    expect(store.announcement.value).toContain('Alpha Camera')
    expect(store.announcement.value).toContain('removed from compare')
    expect(persistence.saved.at(-1)).toEqual([beta])
  })

  it('announces the four-item refusal without mutating or saving', () => {
    const persistence = memoryPersistence([alpha, beta, gamma, delta])
    const store = createCompareTrayStore(persistence)
    const saves = persistence.saved.length
    expect(store.add(epsilon)).toBe('full')
    expect(store.tray.value).toEqual([alpha, beta, gamma, delta])
    expect(store.announcement.value).toContain('up to four items')
    expect(persistence.saved.length).toBe(saves)
  })

  it('syncs a fetched comparison back into the tray by variant identity', () => {
    const persistence = memoryPersistence([alpha, beta])
    const store = createCompareTrayStore(persistence)
    store.syncSelections([
      {
        variant_id: gamma.variantId!,
        product_id: gamma.productId,
        title: gamma.title,
        variant_label: gamma.variantLabel,
      },
      {
        variant_id: alpha.variantId!,
        product_id: alpha.productId,
        title: alpha.title,
        variant_label: 'Standard',
      },
    ])
    expect(store.tray.value).toEqual([
      {
        productId: gamma.productId,
        variantId: gamma.variantId,
        title: gamma.title,
        variantLabel: gamma.variantLabel,
      },
      alpha,
    ])
    expect(persistence.saved.at(-1)).toEqual(store.tray.value)
  })

  it('keeps live cookie-backed stores in step through tray change events', () => {
    const jar = { cookie: '' }
    const listeners = new Set<() => void>()
    const events = {
      addEventListener: (_type: string, listener: () => void) => {
        listeners.add(listener)
      },
      dispatchEvent: () => {
        for (const listener of listeners) listener()
        return true
      },
    }
    const first = createCompareTrayStore(cookieCompareTrayPersistence(jar), {
      liveSync: true,
      events,
    })
    const second = createCompareTrayStore(cookieCompareTrayPersistence(jar), {
      liveSync: true,
      events,
    })
    first.add(alpha)
    expect(second.tray.value).toEqual([alpha])
    second.add(beta)
    expect(first.tray.value).toEqual([alpha, beta])
    second.remove(alpha.productId)
    expect(first.tray.value).toEqual([beta])
    // A store that is not live (unit tests, other pages) never dispatches.
    const passive = createCompareTrayStore(memoryPersistence([]), { liveSync: true, events })
    passive.add(gamma)
    expect(first.tray.value).toEqual([beta])
  })

  it('exposes the entry for a product regardless of selection kind', () => {
    const store = createCompareTrayStore(memoryPersistence([alpha, beta]))
    expect(store.entryFor(alpha.productId)?.variantId).toBe(alpha.variantId)
    expect(store.entryFor(beta.productId)?.variantId).toBeNull()
    expect(store.entryFor(epsilon.productId)).toBeUndefined()
    const spy = vi.fn()
    expect(spy).not.toHaveBeenCalled()
  })
})

describe('parseProductActions', () => {
  it('parses a card outlet with no variants and a product outlet with a known selection', () => {
    expect(
      parseProductActions(JSON.stringify({ productId: beta.productId, title: 'Beta Camera' })),
    ).toEqual({
      productId: beta.productId,
      title: 'Beta Camera',
      variants: [],
      selectedId: null,
    })
    const input = parseProductActions(
      JSON.stringify({
        productId: alpha.productId,
        title: 'Alpha Camera',
        variants: [
          { id: alpha.variantId, label: 'Standard' },
          { id: gamma.variantId, label: 'Pine' },
        ],
        selectedId: gamma.variantId,
      }),
    )
    expect(input?.selectedId).toBe(gamma.variantId)
    expect(input?.variants).toHaveLength(2)
  })

  it('drops an unknown selected variant and rejects malformed payloads', () => {
    const input = parseProductActions(
      JSON.stringify({
        productId: alpha.productId,
        title: 'Alpha Camera',
        variants: [{ id: alpha.variantId, label: 'Standard' }],
        selectedId: epsilon.variantId,
      }),
    )
    expect(input?.selectedId).toBeNull()
    expect(parseProductActions('not json')).toBeNull()
    expect(parseProductActions(JSON.stringify({ title: 'No id' }))).toBeNull()
    expect(parseProductActions(JSON.stringify({ productId: 'nope', title: 'Bad id' }))).toBeNull()
    expect(
      parseProductActions(
        JSON.stringify({
          productId: alpha.productId,
          title: 'A',
          variants: [{ id: 'bad', label: 'X' }],
        }),
      ),
    ).toBeNull()
  })
})

const comparisonFixture: Comparison = {
  selections: [
    {
      variant_id: '10000000-0000-4000-8000-000000000011',
      product_id: '10000000-0000-4000-8000-000000000001',
      title: 'Alpha Camera',
      url: '/products/alpha-camera',
      sku: 'CAM-A1',
      variant_label: 'Standard',
      type: 'physical',
      money: { amount: 1999, currency: 'USD', exponent: 2, formatted: 'USD 19.99' },
      availability: 'in_stock',
      available: true,
    },
    {
      variant_id: '10000000-0000-4000-8000-000000000012',
      product_id: '10000000-0000-4000-8000-000000000002',
      title: 'Beta Camera',
      url: '/products/beta-camera',
      sku: 'CAM-A2',
      variant_label: 'Standard',
      type: 'physical',
      money: { amount: 2599, currency: 'USD', exponent: 2, formatted: 'USD 25.99' },
      availability: 'in_stock',
      available: true,
    },
  ],
  rows: [
    {
      key: 'price',
      label: 'Price',
      kind: 'basic',
      type: 'money',
      unit: null,
      differs: true,
      cells: [
        { specified: true, display: 'USD 19.99' },
        { specified: true, display: 'USD 25.99' },
      ],
    },
    {
      key: 'attribute.body.weight',
      label: 'Weight',
      kind: 'attribute',
      type: 'integer',
      unit: 'g',
      differs: false,
      cells: [
        { specified: true, display: '1.5 kg' },
        { specified: true, display: '1500 g' },
      ],
    },
    {
      key: 'attribute.sensor.megapixels',
      label: 'Megapixels',
      kind: 'attribute',
      type: 'decimal',
      unit: 'MP',
      differs: true,
      cells: [
        { specified: true, display: '24.2' },
        { specified: false, display: 'Not specified' },
      ],
    },
  ],
  compatible: true,
  specialized_attributes_omitted: false,
  omitted_reason: null,
  removed: [],
}

describe('parseComparison', () => {
  it('accepts the read model as the endpoint serves it', () => {
    const parsed = parseComparison(JSON.parse(JSON.stringify(comparisonFixture)))
    expect(parsed?.selections.map((selection) => selection.title)).toEqual([
      'Alpha Camera',
      'Beta Camera',
    ])
    expect(parsed?.rows).toHaveLength(3)
  })

  it('rejects comparisons whose rows do not align with their columns', () => {
    const broken = JSON.parse(JSON.stringify(comparisonFixture)) as typeof comparisonFixture
    broken.rows[0]!.cells = [{ specified: true, display: 'USD 19.99' }]
    expect(parseComparison(broken)).toBeNull()
  })

  it('rejects single-column and malformed comparisons', () => {
    const single = JSON.parse(JSON.stringify(comparisonFixture)) as typeof comparisonFixture
    single.selections = [single.selections[0]!]
    single.rows = single.rows.map((row) => ({ ...row, cells: [row.cells[0]!] }))
    expect(parseComparison(single)).toBeNull()
    expect(parseComparison(null)).toBeNull()
    expect(parseComparison({ selections: [], rows: [] })).toBeNull()
    const badMoney = JSON.parse(JSON.stringify(comparisonFixture)) as typeof comparisonFixture
    badMoney.selections[0]!.money = { amount: -1, currency: 'USD', exponent: 2, formatted: 'x' }
    expect(parseComparison(badMoney)).toBeNull()
  })
})

describe('visibleComparisonRows', () => {
  it('shows every row until differences-only is on', () => {
    expect(visibleComparisonRows(comparisonFixture, false)).toHaveLength(3)
  })

  it('trusts the model differs flag, never the display strings', () => {
    const visible = visibleComparisonRows(comparisonFixture, true)
    expect(visible.map((row) => row.key)).toEqual(['price', 'attribute.sensor.megapixels'])
    // "1.5 kg" and "1500 g" display differently but are the same
    // normalized value, so the weight row stays hidden.
    expect(visible.some((row) => row.key === 'attribute.body.weight')).toBe(false)
  })
})
