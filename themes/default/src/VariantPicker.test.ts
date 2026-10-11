import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import VariantPicker from './VariantPicker.vue'
import { parseVariantPicker } from './variantData'

const variants = [
  {
    id: '10000000-0000-4000-8000-000000000002',
    label: 'Pine',
    money: { amountMinor: 8900, currency: 'USD', exponent: 2, formatted: 'USD 89.00' },
  },
  {
    id: '10000000-0000-4000-8000-000000000003',
    label: '<img src=x onerror=alert(1)>',
    money: { amountMinor: 9500, currency: 'USD', exponent: 2, formatted: 'USD 95.00' },
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
})
