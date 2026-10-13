import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CatalogFilters from './CatalogFilters.vue'
import { parseCatalogFilters, searchHref } from './catalogFilters'

const payload = {
  query: {
    q: 'Sensor',
    category: '',
    price_min: null,
    price_max: null,
    available: '' as const,
    sort: 'title',
    page: 2,
    page_size: 1,
    facets: {},
  },
  sorts: [
    { value: 'title', label: 'Title' },
    { value: 'sensor.megapixels', label: 'Megapixels' },
  ],
  categories: [{ path: 'lighting', title: 'Lighting' }],
  facets: [
    {
      key: 'lens.mount',
      label: 'Lens mount',
      type: 'enum',
      unit: null,
      values: [{ value: 'rf', count: 1 }],
    },
  ],
}

describe('default theme catalog filters', () => {
  it('keeps filter state in the generated search URL without purchase controls', async () => {
    const wrapper = mount(CatalogFilters, { props: payload })
    expect(wrapper.get('form').attributes('data-href')).toContain('q=Sensor')
    await wrapper.get('#enhanced-sort').setValue('sensor.megapixels')
    expect(wrapper.get('form').attributes('data-href')).toContain('sort=sensor.megapixels')
    expect(wrapper.get('form').attributes('data-href')).toContain('q=Sensor')
    expect(wrapper.get('form').attributes('data-href')).not.toContain('page=2')
    expect(wrapper.find('button').text()).toBe('Apply filters')
    expect(wrapper.text()).not.toContain('Add to cart')
  })

  it('accepts only bounded public filter payloads', () => {
    expect(parseCatalogFilters(payload)).not.toBeNull()
    expect(parseCatalogFilters({ ...payload, secret: true })).toBeNull()
    expect(
      parseCatalogFilters({ ...payload, query: { ...payload.query, sort: 'products.status' } }),
    ).not.toBeNull()
    expect(
      parseCatalogFilters({
        ...payload,
        query: { ...payload.query, page_size: 500 },
      }),
    ).toBeNull()
    expect(searchHref(payload.query)).toContain('page=2')
  })
})
