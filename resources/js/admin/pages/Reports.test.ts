import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import Reports from './Reports.vue'

vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
}))

const money = (amount: number) => ({
  amount,
  currency: 'USD',
  exponent: 2,
  formatted: amount < 0 ? `-USD ${Math.abs(amount) / 100}` : `USD ${amount / 100}`,
})

const totals = {
  paid_orders: 2,
  gross: money(3000),
  discounts: money(750),
  refunds: money(0),
  net: money(2250),
  tax: money(255),
  shipping: money(300),
  aov: money(1125),
}

it('shows store-timezone inclusion rules and activity versus cohort totals', () => {
  const wrapper = mount(Reports, {
    props: {
      report: {
        timezone: 'America/Los_Angeles',
        from: '2026-10-16',
        to: '2026-10-16',
        currency: 'USD',
        exponent: 2,
        rules: {
          include_paid: true,
          include_test: false,
          include_unpaid: false,
          date_basis: 'store_timezone_calendar_days',
          capture_date: 'payments.captured_at',
          refund_date: 'refund_succeeded_at',
          cohort_refunds: 'original_capture_date',
        },
        definitions: {
          paid_orders: 'Live captured payments only.',
          net: 'Gross minus discounts minus merchandise refunds.',
        },
        activity: totals,
        cohort: { ...totals, refunds: money(2250), net: money(0), aov: money(0) },
      },
    },
  })
  expect(wrapper.get('h1').text()).toContain('Sales reports')
  expect(wrapper.get('[role="note"]').text()).toContain('America/Los_Angeles')
  expect(wrapper.get('[role="note"]').text()).toContain('Test included: no')
  expect(wrapper.get('[role="note"]').text()).toContain('Unpaid included: no')
  expect(wrapper.text()).toContain('Paid orders 2')
  expect(wrapper.text()).toContain('Net merchandise USD 22.5')
  expect(wrapper.text()).toContain('Average order value USD 0')
  expect(wrapper.find('a[href="/admin"]').exists()).toBe(true)
})

it('shows an empty range without executing untrusted copy', () => {
  const empty = {
    paid_orders: 0,
    gross: money(0),
    discounts: money(0),
    refunds: money(0),
    net: money(0),
    tax: money(0),
    shipping: money(0),
    aov: money(0),
  }
  const wrapper = mount(Reports, {
    props: {
      report: {
        timezone: 'UTC',
        from: '2026-10-16',
        to: '2026-10-16',
        currency: 'USD',
        exponent: 2,
        rules: {
          include_paid: true,
          include_test: false,
          include_unpaid: false,
          date_basis: 'store_timezone_calendar_days',
          capture_date: 'payments.captured_at',
          refund_date: 'refund_succeeded_at',
          cohort_refunds: 'original_capture_date',
        },
        definitions: { paid_orders: '<script>alert(1)</script>' },
        activity: empty,
        cohort: empty,
      },
    },
  })
  expect(wrapper.find('script').exists()).toBe(false)
  expect(wrapper.text()).toContain('<script>alert(1)</script>')
  expect(wrapper.text()).toContain('No paid live captures or succeeded merchandise refunds')
})
