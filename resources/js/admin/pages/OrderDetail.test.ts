import { mount } from '@vue/test-utils'
import { expect, it, vi } from 'vitest'
import OrderDetail from './OrderDetail.vue'

const post = vi.fn()
vi.mock('@inertiajs/vue3', () => ({
  Head: { template: '<span />' },
  useForm: (fields: Record<string, unknown>) => ({
    ...fields,
    processing: false,
    post,
    reset: vi.fn(),
  }),
}))

it('shows frozen lines totals independent states and notes', () => {
  const wrapper = mount(OrderDetail, {
    props: {
      saved: true,
      canRefund: false,
      canCancel: false,
      canResendReceipt: true,
      canShip: false,
      canReturn: false,
      canManageDownloads: false,
      refundSaved: false,
      cancellationSaved: false,
      receiptSaved: false,
      shipmentSaved: false,
      returnSaved: false,
      downloadSaved: false,
      order: {
        id: '11111111-1111-4111-8111-111111111111',
        number: 'MABCDEF123456',
        email: 'alex@example.test',
        status: 'confirmed',
        financial_status: 'paid',
        fulfillment_status: 'unfulfilled',
        download_status: 'not_required',
        dispute_status: 'none',
        created_at: '2026-10-16T12:00:00Z',
        coupon_code: 'SAVE10',
        totals: {
          merchandise: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
          discount: { amount: 250, currency: 'USD', exponent: 2, formatted: 'USD 2.50' },
          shipping: { amount: 300, currency: 'USD', exponent: 2, formatted: 'USD 3.00' },
          tax: { amount: 255, currency: 'USD', exponent: 2, formatted: 'USD 2.55' },
          grand_total: { amount: 2805, currency: 'USD', exponent: 2, formatted: 'USD 28.05' },
        },
        lines: [
          {
            id: '22222222-2222-4222-8222-222222222222',
            type: 'physical',
            sku: 'LAMP-001',
            title: 'Studio Lamp',
            quantity: 1,
            refundable_quantity: 1,
            held_qty: 0,
            cancelled_qty: 0,
            shipped_qty: 0,
            shippable_quantity: 1,
            returned_qty: 0,
            restocked_qty: 0,
            returnable_quantity: 0,
            subtotal: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
            discount: { amount: 250, currency: 'USD', exponent: 2, formatted: 'USD 2.50' },
            total: { amount: 2250, currency: 'USD', exponent: 2, formatted: 'USD 22.50' },
          },
        ],
        billing: {
          recipient: 'Alex Shopper',
          line1: '10 Market Street',
          line2: '',
          city: 'Los Angeles',
          region: 'CA',
          postal_code: '90001',
          country_code: 'US',
        },
        shipping: {
          recipient: 'Alex Shopper',
          line1: '10 Market Street',
          line2: '',
          city: 'Los Angeles',
          region: 'CA',
          postal_code: '90001',
          country_code: 'US',
        },
        notes: [
          {
            id: '33333333-3333-4333-8333-333333333333',
            body: 'Hold for address confirmation.',
            staff_name: 'Example Owner',
            created_at: '2026-10-16T12:05:00Z',
          },
        ],
        refunds: [],
        disputes: [],
        shipments: [],
        returns: [],
        refundable: {
          amount: 2805,
          money: { amount: 2805, currency: 'USD', exponent: 2, formatted: 'USD 28.05' },
          lines: [{ id: '22222222-2222-4222-8222-222222222222', quantity: 1 }],
        },
        cancellable: true,
        downloads: [],
        timeline: [
          {
            id: '44444444-4444-4444-8444-444444444444',
            type: 'paid',
            created_at: '2026-10-16T12:00:00Z',
            amount: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          },
        ],
      },
    },
  })
  expect(wrapper.text()).toContain('financial paid')
  expect(wrapper.text()).toContain('fulfillment unfulfilled')
  expect(wrapper.text()).toContain('Studio Lamp')
  expect(wrapper.text()).toContain('USD 28.05')
  expect(wrapper.text()).toContain('Hold for address confirmation.')
  expect(wrapper.text()).toContain('Internal note saved.')
  expect(wrapper.find('textarea[name="note"]').exists()).toBe(true)
  expect(wrapper.text()).toContain('paid · 2026-10-16T12:00:00Z')
  expect(wrapper.text()).toContain('Resend receipt')
  expect(wrapper.text()).not.toContain('Disputes')
})

it('shows provider refunds and disputes as read-only provider facts', () => {
  const money = { amount: 550, currency: 'USD', exponent: 2, formatted: 'USD 5.50' }
  const wrapper = mount(OrderDetail, {
    props: {
      saved: false,
      canRefund: false,
      canCancel: false,
      canResendReceipt: false,
      canShip: false,
      canReturn: false,
      canManageDownloads: false,
      refundSaved: false,
      cancellationSaved: false,
      receiptSaved: false,
      shipmentSaved: false,
      returnSaved: false,
      downloadSaved: false,
      order: {
        id: '11111111-1111-4111-8111-111111111111',
        number: 'MABCDEF123456',
        email: 'alex@example.test',
        status: 'on_hold',
        financial_status: 'paid',
        fulfillment_status: 'not_required',
        download_status: 'suspended',
        dispute_status: 'open',
        created_at: '2026-10-16T12:00:00Z',
        coupon_code: null,
        totals: {
          merchandise: money,
          discount: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          shipping: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          tax: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          grand_total: money,
        },
        lines: [],
        billing: null,
        shipping: null,
        notes: [],
        refunds: [
          {
            id: '55555555-5555-4555-8555-555555555555',
            status: 'succeeded',
            kind: 'external',
            origin: 'provider',
            reason: 'Refund created outside MRKTFlow',
            amount: money,
          },
        ],
        disputes: [
          {
            id: '66666666-6666-4666-8666-666666666666',
            provider_dispute_id: 'dp_ext_1',
            status: 'opened',
            reason: 'fraudulent',
            amount: money,
            created_at: '2026-10-16T12:10:00Z',
          },
        ],
        shipments: [],
        returns: [],
        refundable: {
          amount: 0,
          money: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          lines: [],
        },
        cancellable: false,
        downloads: [],
        timeline: [],
      },
    },
  })
  expect(wrapper.text()).toContain('dispute open')
  expect(wrapper.text()).toContain('external · provider · succeeded')
  expect(wrapper.text()).toContain('Refund created outside MRKTFlow')
  expect(wrapper.text()).toContain('dp_ext_1 · opened')
  expect(wrapper.text()).toContain('read-only')
})

it('shows recorded shipments and the ship dialog when the staff can ship', () => {
  const wrapper = mount(OrderDetail, {
    props: {
      saved: false,
      canRefund: false,
      canCancel: false,
      canResendReceipt: false,
      canShip: true,
      canReturn: false,
      canManageDownloads: false,
      refundSaved: false,
      cancellationSaved: false,
      receiptSaved: false,
      shipmentSaved: true,
      returnSaved: false,
      downloadSaved: false,
      order: {
        id: '11111111-1111-4111-8111-111111111111',
        number: 'MABCDEF123456',
        email: 'alex@example.test',
        status: 'confirmed',
        financial_status: 'paid',
        fulfillment_status: 'partially_fulfilled',
        download_status: 'not_required',
        dispute_status: 'none',
        created_at: '2026-10-16T12:00:00Z',
        coupon_code: null,
        totals: {
          merchandise: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
          discount: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          shipping: { amount: 300, currency: 'USD', exponent: 2, formatted: 'USD 3.00' },
          tax: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          grand_total: { amount: 2800, currency: 'USD', exponent: 2, formatted: 'USD 28.00' },
        },
        lines: [
          {
            id: '22222222-2222-4222-8222-222222222222',
            type: 'physical',
            sku: 'LAMP-001',
            title: 'Studio Lamp',
            quantity: 3,
            refundable_quantity: 1,
            held_qty: 0,
            cancelled_qty: 0,
            shipped_qty: 2,
            shippable_quantity: 1,
            returned_qty: 0,
            restocked_qty: 0,
            returnable_quantity: 1,
            subtotal: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
            discount: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
            total: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
          },
        ],
        billing: null,
        shipping: null,
        notes: [],
        refunds: [],
        disputes: [],
        returns: [],
        shipments: [
          {
            id: '77777777-7777-4777-8777-777777777777',
            carrier: 'UPS',
            tracking_number: '1Z999AA10123456784',
            tracking_url: null,
            created_at: '2026-10-16T12:30:00Z',
            lines: [{ id: '22222222-2222-4222-8222-222222222222', quantity: 2 }],
          },
        ],
        refundable: {
          amount: 0,
          money: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          lines: [],
        },
        cancellable: false,
        downloads: [],
        timeline: [],
      },
    },
  })
  expect(wrapper.text()).toContain('fulfillment partially_fulfilled')
  expect(wrapper.text()).toContain('shipped 2')
  expect(wrapper.text()).toContain('Shipment recorded.')
  expect(wrapper.text()).toContain('UPS')
  expect(wrapper.text()).toContain('1Z999AA10123456784')
  expect(wrapper.text()).toContain('2 × Studio Lamp')
  expect(wrapper.text()).toContain('Ship items')
})

it('shows return stages with linked refund facts and the return dialogs', () => {
  const wrapper = mount(OrderDetail, {
    props: {
      saved: false,
      canRefund: false,
      canCancel: false,
      canResendReceipt: false,
      canShip: false,
      canReturn: true,
      canManageDownloads: false,
      refundSaved: false,
      cancellationSaved: false,
      receiptSaved: false,
      shipmentSaved: false,
      returnSaved: true,
      downloadSaved: false,
      order: {
        id: '11111111-1111-4111-8111-111111111111',
        number: 'MABCDEF123456',
        email: 'alex@example.test',
        status: 'confirmed',
        financial_status: 'paid',
        fulfillment_status: 'fulfilled',
        download_status: 'not_required',
        dispute_status: 'none',
        created_at: '2026-10-16T12:00:00Z',
        coupon_code: null,
        totals: {
          merchandise: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
          discount: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          shipping: { amount: 300, currency: 'USD', exponent: 2, formatted: 'USD 3.00' },
          tax: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          grand_total: { amount: 2800, currency: 'USD', exponent: 2, formatted: 'USD 28.00' },
        },
        lines: [
          {
            id: '22222222-2222-4222-8222-222222222222',
            type: 'physical',
            sku: 'LAMP-001',
            title: 'Studio Lamp',
            quantity: 3,
            refundable_quantity: 0,
            held_qty: 0,
            cancelled_qty: 0,
            shipped_qty: 3,
            shippable_quantity: 0,
            returned_qty: 2,
            restocked_qty: 1,
            returnable_quantity: 1,
            subtotal: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
            discount: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
            total: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
          },
        ],
        billing: null,
        shipping: null,
        notes: [],
        refunds: [],
        disputes: [],
        shipments: [],
        returns: [
          {
            id: '88888888-8888-4888-8888-888888888888',
            status: 'received',
            reason: 'Customer changed their mind.',
            staff_name: 'Example Owner',
            created_at: '2026-10-16T13:00:00Z',
            lines: [
              {
                id: '22222222-2222-4222-8222-222222222222',
                requested: 2,
                approved: 2,
                received: 2,
                restocked: 1,
                refunds: [
                  {
                    id: '99999999-9999-4999-8999-999999999999',
                    kind: 'quantity',
                    origin: 'staff',
                    status: 'succeeded',
                    quantity: 1,
                    amount: { amount: 2500, currency: 'USD', exponent: 2, formatted: 'USD 25.00' },
                  },
                ],
              },
            ],
          },
        ],
        refundable: {
          amount: 0,
          money: { amount: 0, currency: 'USD', exponent: 2, formatted: 'USD 0.00' },
          lines: [],
        },
        cancellable: false,
        downloads: [],
        timeline: [],
      },
    },
  })
  expect(wrapper.text()).toContain('Return updated.')
  expect(wrapper.text()).toContain('returned 2 · restocked 1')
  expect(wrapper.text()).toContain('received')
  expect(wrapper.text()).toContain('Customer changed their mind.')
  expect(wrapper.text()).toContain('requested 2 · approved 2 · received 2 · restocked 1')
  expect(wrapper.text()).toContain('refund quantity · staff · succeeded · 1 · USD 25.00')
  expect(wrapper.text()).toContain('Open a return')
  expect(wrapper.text()).toContain('Restock return')
  expect(wrapper.text()).not.toContain('Approve return')
  expect(wrapper.text()).not.toContain('Receive return')
})
