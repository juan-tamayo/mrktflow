import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import ComparisonTable from './ComparisonTable.vue'
import {
  createCompareTrayStore,
  type CompareCandidate,
  type CompareTrayPersistence,
  type Comparison,
} from './compareData'

const alpha = {
  variant_id: '10000000-0000-4000-8000-000000000011',
  product_id: '10000000-0000-4000-8000-000000000001',
  title: 'Alpha Camera',
  url: '/products/alpha-camera',
  sku: 'CAM-A1',
  variant_label: 'Standard',
  type: 'physical' as const,
  money: { amount: 1999, currency: 'USD', exponent: 2, formatted: 'USD 19.99' },
  availability: 'in_stock',
  available: true,
}
const beta = {
  variant_id: '10000000-0000-4000-8000-000000000012',
  product_id: '10000000-0000-4000-8000-000000000002',
  title: 'Beta Camera',
  url: '/products/beta-camera',
  sku: 'CAM-A2',
  variant_label: 'Standard',
  type: 'physical' as const,
  money: { amount: 2599, currency: 'USD', exponent: 2, formatted: 'USD 25.99' },
  availability: 'in_stock',
  available: true,
}
const pro = {
  variant_id: '10000000-0000-4000-8000-000000000013',
  product_id: '10000000-0000-4000-8000-000000000003',
  title: 'Pro Camera',
  url: '/products/pro-camera',
  sku: 'CAM-B1',
  variant_label: 'Standard',
  type: 'physical' as const,
  money: { amount: 4999, currency: 'USD', exponent: 2, formatted: 'USD 49.99' },
  availability: 'out_of_stock',
  available: false,
}

function comparison(selections: Comparison['selections']): Comparison {
  return {
    selections,
    rows: [
      {
        key: 'price',
        label: 'Price',
        kind: 'basic',
        type: 'money',
        unit: null,
        differs: true,
        cells: selections.map((selection) => ({
          specified: true,
          display: selection.money.formatted,
        })),
      },
      {
        key: 'attribute.body.weight',
        label: 'Weight',
        kind: 'attribute',
        type: 'integer',
        unit: 'g',
        differs: false,
        cells: selections.map((_, index) => ({
          specified: index !== selections.length - 1,
          display:
            index === 0 ? '1.5 kg' : index === selections.length - 1 ? 'Not specified' : '1500 g',
        })),
      },
    ],
    compatible: true,
    specialized_attributes_omitted: false,
    omitted_reason: null,
    removed: [],
  }
}

const candidates: CompareCandidate[] = [
  {
    variant_id: alpha.variant_id,
    product_id: alpha.product_id,
    title: 'Alpha Camera',
    variant_label: 'Standard',
  },
  {
    variant_id: beta.variant_id,
    product_id: beta.product_id,
    title: 'Beta Camera',
    variant_label: 'Standard',
  },
  {
    variant_id: pro.variant_id,
    product_id: pro.product_id,
    title: 'Pro Camera',
    variant_label: 'Standard',
  },
  {
    variant_id: '10000000-0000-4000-8000-000000000014',
    product_id: '10000000-0000-4000-8000-000000000004',
    title: 'Gamma Camera',
    variant_label: 'Pine',
  },
]

type FetcherResult = { ok: boolean; json: () => Promise<unknown> }

function memoryStore() {
  const persistence: CompareTrayPersistence = { load: () => [], save: () => undefined }
  return createCompareTrayStore(persistence)
}

function mountTable() {
  const store = memoryStore()
  const fetcher = vi.fn<(url: string) => Promise<FetcherResult>>(async () => ({
    ok: true,
    json: async () => comparison([alpha, beta]),
  }))
  const navigate = vi.fn()
  const wrapper = mount(ComparisonTable, {
    props: {
      comparison: comparison([alpha, beta, pro]),
      candidates,
      endpoint: '/store-api/v1/compare',
      csrfToken: 'csrf-token',
      cartRevision: 3,
      store,
      fetcher,
      navigate,
    },
  })
  return { wrapper, store, fetcher, navigate }
}

describe('ComparisonTable', () => {
  it('renders columns, rows, absences and add-to-cart only for available items', () => {
    const { wrapper } = mountTable()
    expect(wrapper.text()).toContain('Alpha Camera')
    expect(wrapper.text()).toContain('Pro Camera')
    expect(wrapper.text()).toContain('Not specified')
    const forms = wrapper.findAll('form[action="/cart/lines"]')
    expect(forms).toHaveLength(2)
    expect(forms[0]!.find('input[name="variant_id"]').element).toHaveProperty(
      'value',
      alpha.variant_id,
    )
    expect(forms[0]!.find('input[name="expected_revision"]').element).toHaveProperty('value', '3')
    expect(forms[0]!.find('input[name="_token"]').element).toHaveProperty('value', 'csrf-token')
    const differing = wrapper.findAll('tr.compare-differs')
    expect(differing).toHaveLength(1)
    expect(differing[0]!.text()).toContain('Price')
  })

  it('hides rows the normalized model calls equal when differences-only is on', async () => {
    const { wrapper } = mountTable()
    const toggle = wrapper.get('input[type="checkbox"]')
    await toggle.setValue(true)
    expect(wrapper.text()).not.toContain('Weight')
    expect(wrapper.text()).toContain('Price')
    expect(wrapper.get('[role="status"]').text()).toContain('1 of 2')
  })

  it('removes a column through the read model and syncs the tray', async () => {
    const { wrapper, store, fetcher } = mountTable()
    await wrapper.get('button[aria-label="Remove Pro Camera from comparison"]').trigger('click')
    await flushPromises()
    expect(fetcher).toHaveBeenCalledTimes(1)
    const url = fetcher.mock.calls[0]![0]
    expect(url).toContain(`items[]=${alpha.variant_id}`)
    expect(url).toContain(`items[]=${beta.variant_id}`)
    expect(url).not.toContain(pro.variant_id)
    const columnTitles = wrapper.findAll('thead th a').map((link) => link.text())
    expect(columnTitles).toEqual(['Alpha Camera', 'Beta Camera'])
    expect(wrapper.get('[role="status"]').text()).toContain('Pro Camera')
    expect(wrapper.get('[role="status"]').text()).toContain('removed')
    expect(store.tray.value.map((entry) => entry.variantId)).toEqual([
      alpha.variant_id,
      beta.variant_id,
    ])
  })

  it('shows the add-another prompt instead of fetching when one column would remain', async () => {
    const store = memoryStore()
    const fetcher = vi.fn<(url: string) => Promise<FetcherResult>>(async () => ({
      ok: true,
      json: async () => comparison([alpha]),
    }))
    const wrapper = mount(ComparisonTable, {
      props: {
        comparison: comparison([alpha, beta]),
        candidates,
        endpoint: '/store-api/v1/compare',
        csrfToken: 'csrf-token',
        cartRevision: 1,
        store,
        fetcher,
        navigate: vi.fn(),
      },
    })
    await wrapper.get('button[aria-label="Remove Beta Camera from comparison"]').trigger('click')
    await flushPromises()
    expect(fetcher).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Alpha Camera')
    expect(wrapper.text()).toContain('Add another item')
    expect(store.tray.value.map((entry) => entry.variantId)).toEqual([alpha.variant_id])
  })

  it('replaces a column with the chosen candidate', async () => {
    const gamma = candidates[3]!
    const fetcher = vi.fn<(url: string) => Promise<FetcherResult>>(async () => ({
      ok: true,
      json: async () =>
        comparison([
          alpha,
          {
            variant_id: gamma.variant_id,
            product_id: gamma.product_id,
            title: 'Gamma Camera',
            url: '/products/gamma-camera',
            sku: 'CAM-G1',
            variant_label: 'Pine',
            type: 'physical' as const,
            money: { amount: 3599, currency: 'USD', exponent: 2, formatted: 'USD 35.99' },
            availability: 'in_stock',
            available: true,
          },
        ]),
    }))
    const wrapper = mount(ComparisonTable, {
      props: {
        comparison: comparison([alpha, beta]),
        candidates,
        endpoint: '/store-api/v1/compare',
        csrfToken: 'csrf-token',
        cartRevision: 1,
        store: memoryStore(),
        fetcher,
        navigate: vi.fn(),
      },
    })
    const select = wrapper.get('select[aria-label="Replace Beta Camera with"]')
    await select.setValue(gamma.variant_id)
    await wrapper.get('button[aria-label="Replace Beta Camera"]').trigger('click')
    await flushPromises()
    expect(fetcher).toHaveBeenCalledTimes(1)
    const url = fetcher.mock.calls[0]![0]
    expect(url).toContain(`items[]=${gamma.variant_id}`)
    expect(url).not.toContain(beta.variant_id)
    expect(wrapper.text()).toContain('Gamma Camera')
    expect(wrapper.get('[role="status"]').text()).toContain('replaced')
  })

  it('falls back to the full comparison page when the fetch fails', async () => {
    const navigate = vi.fn()
    const wrapper = mount(ComparisonTable, {
      props: {
        comparison: comparison([alpha, beta, pro]),
        candidates,
        endpoint: '/store-api/v1/compare',
        csrfToken: 'csrf-token',
        cartRevision: 1,
        store: memoryStore(),
        fetcher: vi.fn<(url: string) => Promise<FetcherResult>>(async () => ({
          ok: false,
          json: async () => ({}),
        })),
        navigate,
      },
    })
    await wrapper.get('button[aria-label="Remove Pro Camera from comparison"]').trigger('click')
    await flushPromises()
    expect(navigate).toHaveBeenCalledWith(
      `/compare?items[]=${alpha.variant_id}&items[]=${beta.variant_id}`,
    )
  })

  it('labels every value cell with its column for stacked mobile rows', () => {
    const { wrapper } = mountTable()
    const cells = wrapper.findAll('td[data-column]')
    expect(cells.length).toBeGreaterThan(0)
    expect(cells.every((cell) => (cell.attributes('data-column') ?? '').length > 0)).toBe(true)
    expect(cells[0]!.attributes('data-column')).toBe('Alpha Camera')
  })
})
