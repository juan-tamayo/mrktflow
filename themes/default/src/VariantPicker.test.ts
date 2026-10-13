import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VariantPicker from './VariantPicker.vue'
import { parseVariantPicker } from './variantData'

const variants = [
  {
    id: '10000000-0000-4000-8000-000000000002',
    label: 'Pine',
    money: { amountMinor: 8900, currency: 'USD', exponent: 2, formatted: 'USD 89.00' },
    available: true,
    availability: 'in_stock' as const,
    options: [],
    image: { url: '/themes/default/preview-lamp.svg', alt: 'Pine lamp' },
  },
  {
    id: '10000000-0000-4000-8000-000000000003',
    label: '<img src=x onerror=alert(1)>',
    money: { amountMinor: 9500, currency: 'USD', exponent: 2, formatted: 'USD 95.00' },
    available: true,
    availability: 'in_stock' as const,
    options: [],
    image: { url: '/themes/default/preview-lamp.svg', alt: 'Chalk lamp' },
  },
]

const matrix = [
  {
    id: '10000000-0000-4000-8000-000000000002',
    label: 'Pine / S',
    money: { amountMinor: 2499, currency: 'USD', exponent: 2, formatted: 'USD 24.99' },
    available: true,
    availability: 'in_stock' as const,
    options: [
      { name: 'Color', value: 'Pine' },
      { name: 'Size', value: 'S' },
    ],
    image: {
      url: '/storage/media/11111111-1111-4111-8111-111111111111/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.png',
      alt: 'Pine',
    },
  },
  {
    id: '10000000-0000-4000-8000-000000000003',
    label: 'Pine / M',
    money: { amountMinor: 2599, currency: 'USD', exponent: 2, formatted: 'USD 25.99' },
    available: false,
    availability: 'unavailable' as const,
    options: [
      { name: 'Color', value: 'Pine' },
      { name: 'Size', value: 'M' },
    ],
    image: {
      url: '/storage/media/11111111-1111-4111-8111-111111111111/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.png',
      alt: 'Pine M',
    },
  },
]

describe('default theme variant selection', () => {
  it('updates supplied price text when a variant is selected without creating purchase authority', async () => {
    const wrapper = mount(VariantPicker, { props: { variants, selectedId: variants[0]!.id } })
    expect(wrapper.get('[role="status"]').text()).toContain('USD 89.00')
    await wrapper.get('select').setValue(variants[1]!.id)
    expect(wrapper.get('[role="status"]').text()).toContain('USD 95.00')
    expect(wrapper.find('img').exists()).toBe(false)
    expect(wrapper.text()).toContain('<img src=x onerror=alert(1)>')
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('accepts only bounded public variants and a supplied selection', () => {
    expect(parseVariantPicker({ variants, selectedId: variants[0]!.id })).not.toBeNull()
    expect(parseVariantPicker({ variants, selectedId: 'unknown' })).toBeNull()
    expect(
      parseVariantPicker({ variants, selectedId: variants[0]!.id, supplierCost: 500 }),
    ).toBeNull()
    expect(
      parseVariantPicker({ variants: [...variants, variants[0]], selectedId: variants[0]!.id }),
    ).toBeNull()
  })

  it('rejects malformed price payloads and oversized arrays before enhancement', () => {
    const malformed = [
      {
        ...variants[0],
        money: { ...variants[0]!.money, amountMinor: Number.MAX_SAFE_INTEGER + 1 },
      },
    ]
    expect(parseVariantPicker({ variants: malformed, selectedId: variants[0]!.id })).toBeNull()
    expect(
      parseVariantPicker({ variants: Array(51).fill(variants[0]), selectedId: variants[0]!.id }),
    ).toBeNull()
    expect(parseVariantPicker(null)).toBeNull()
  })

  it('shows unavailable combinations without revealing a buyable price', async () => {
    const wrapper = mount(VariantPicker, {
      props: {
        variants: matrix,
        selectedId: matrix[0]!.id,
        optionAxes: [
          { name: 'Color', values: ['Pine'] },
          { name: 'Size', values: ['S', 'M'] },
        ],
        selectionState: 'selected',
      },
    })
    expect(wrapper.get('[role="status"]').text()).toContain('USD 24.99')
    await wrapper.get('#enhanced-Size').setValue('M')
    expect(wrapper.get('[role="status"]').text()).toContain('This combination is unavailable')
    expect(wrapper.get('[role="status"]').text()).not.toContain('USD 25.99')
    expect(wrapper.find('button').exists()).toBe(false)
  })

  it('shows out of stock without revealing a buyable price', async () => {
    const soldOut = {
      ...matrix[1]!,
      availability: 'out_of_stock' as const,
    }
    const wrapper = mount(VariantPicker, {
      props: {
        variants: [matrix[0]!, soldOut],
        selectedId: matrix[0]!.id,
        optionAxes: [
          { name: 'Color', values: ['Pine'] },
          { name: 'Size', values: ['S', 'M'] },
        ],
        selectionState: 'selected',
      },
    })
    await wrapper.get('#enhanced-Size').setValue('M')
    expect(wrapper.get('[role="status"]').text()).toContain('This combination is out of stock')
    expect(wrapper.get('[role="status"]').text()).not.toContain('USD 25.99')
  })
})
