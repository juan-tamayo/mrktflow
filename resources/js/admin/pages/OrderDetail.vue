<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import CancelOrderDialog from '../components/CancelOrderDialog.vue'
import RefundDialog from '../components/RefundDialog.vue'
import ResendReceiptDialog from '../components/ResendReceiptDialog.vue'
import { Head, useForm } from '@inertiajs/vue3'

defineOptions({ name: 'AdminOrderDetail' })

type Money = { amount: number; currency: string; exponent: number; formatted: string }
type Address = {
  recipient: string
  line1: string
  line2: string
  city: string
  region: string
  postal_code: string
  country_code: string
}
type Order = {
  id: string
  number: string
  email: string
  status: string
  financial_status: string
  fulfillment_status: string
  download_status: string
  created_at: string
  coupon_code: string | null
  totals: {
    merchandise: Money
    discount: Money
    shipping: Money
    tax: Money
    grand_total: Money
  }
  lines: Array<{
    id: string
    type: string
    sku: string
    title: string
    quantity: number
    refundable_quantity: number
    held_qty: number
    cancelled_qty: number
    subtotal: Money
    discount: Money
    total: Money
  }>
  billing: Address | null
  shipping: Address | null
  notes: Array<{ id: string; body: string; staff_name: string; created_at: string }>
  refunds: Array<{ id: string; status: string; kind: string; reason: string; amount: Money }>
  refundable: { amount: number; money: Money; lines: Array<{ id: string; quantity: number }> }
  cancellable: boolean
  timeline: Array<{ id: string; type: string; created_at: string; amount: Money }>
}
const props = defineProps<{
  order: Order
  canRefund: boolean
  canCancel: boolean
  canResendReceipt: boolean
  saved: boolean
  refundSaved: boolean
  cancellationSaved: boolean
  receiptSaved: boolean
}>()
const note = useForm({ note: '' })
const submit = () => {
  note.post(`/admin/orders/${props.order.id}/notes`, {
    preserveScroll: true,
    onSuccess: () => note.reset('note'),
  })
}
const formatAddress = (address: Address) =>
  [
    address.recipient,
    address.line1,
    address.line2,
    address.city,
    address.region,
    address.postal_code,
    address.country_code,
  ]
    .filter((part) => part !== '')
    .join(', ')
</script>

<template>
  <Head :title="`Order ${order.number}`" />
  <a class="skip-link" href="#order-main">Skip to order</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/orders" class="text-link">All orders</a>
    </header>
    <main id="order-main" class="setup-main">
      <p class="eyebrow">Orders</p>
      <h1>Order {{ order.number }}<span class="accent">.</span></h1>
      <p class="lede">
        Frozen lines and totals. Order, financial, fulfillment, and download states stay
        independent.
      </p>
      <p v-if="saved" class="workspace-note">Internal note saved.</p>
      <p v-if="refundSaved" class="workspace-note">Refund submitted.</p>
      <p v-if="cancellationSaved" class="workspace-note">Cancellation submitted.</p>
      <p v-if="receiptSaved" class="workspace-note">Receipt resend queued.</p>
      <p>
        {{ order.email }} · {{ order.created_at }} · order {{ order.status }} · financial
        {{ order.financial_status }} · fulfillment {{ order.fulfillment_status }} · downloads
        {{ order.download_status }}
      </p>
      <p v-if="order.coupon_code">Coupon {{ order.coupon_code }}</p>
      <h2>Lines</h2>
      <ul class="product-list">
        <li v-for="line in order.lines" :key="line.id">
          <p>
            {{ line.title }} · {{ line.sku }} · {{ line.type }} · qty {{ line.quantity }} ·
            unshipped {{ line.refundable_quantity }} · {{ line.total.formatted }}
          </p>
        </li>
      </ul>
      <h2>Totals</h2>
      <p>
        Merchandise {{ order.totals.merchandise.formatted }} · discount
        {{ order.totals.discount.formatted }} · shipping {{ order.totals.shipping.formatted }} · tax
        {{ order.totals.tax.formatted }} · grand {{ order.totals.grand_total.formatted }}
      </p>
      <h2>Addresses</h2>
      <p v-if="order.billing">Billing {{ formatAddress(order.billing) }}</p>
      <p v-if="order.shipping">Shipping {{ formatAddress(order.shipping) }}</p>
      <p v-else>No shipping address.</p>
      <h2>Refunds</h2>
      <p v-if="order.refunds.length === 0" class="workspace-note">No refunds yet.</p>
      <ul v-else class="product-list">
        <li v-for="refund in order.refunds" :key="refund.id">
          <p>{{ refund.kind }} · {{ refund.status }} · {{ refund.amount.formatted }}</p>
          <p>{{ refund.reason }}</p>
        </li>
      </ul>
      <RefundDialog
        v-if="canRefund"
        :order-id="order.id"
        :refundable="order.refundable"
        :lines="order.lines"
      />
      <CancelOrderDialog v-if="canCancel" :order-id="order.id" :cancellable="order.cancellable" />
      <ResendReceiptDialog v-if="canResendReceipt" :order-id="order.id" />
      <h2>Timeline</h2>
      <p v-if="order.timeline.length === 0" class="workspace-note">No recorded events yet.</p>
      <ul v-else class="product-list">
        <li v-for="entry in order.timeline" :key="entry.id">
          <p>{{ entry.type }} · {{ entry.created_at }} · {{ entry.amount.formatted }}</p>
        </li>
      </ul>
      <h2>Internal notes</h2>
      <p v-if="order.notes.length === 0" class="workspace-note">No internal notes yet.</p>
      <ul v-else class="product-list">
        <li v-for="entry in order.notes" :key="entry.id">
          <p>{{ entry.staff_name }} · {{ entry.created_at }}</p>
          <p>{{ entry.body }}</p>
        </li>
      </ul>
      <form class="setup-form" @submit.prevent="submit">
        <label>
          Add a note
          <textarea v-model="note.note" name="note" maxlength="2000" rows="4" required />
        </label>
        <button type="submit" class="primary-button" :disabled="note.processing">
          {{ note.processing ? 'Saving…' : 'Save note' }}
        </button>
      </form>
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/orders">Return to orders</a>
    </footer>
  </div>
</template>
