<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import CancelOrderDialog from '../components/CancelOrderDialog.vue'
import DownloadAccess from '../components/DownloadAccess.vue'
import RefundDialog from '../components/RefundDialog.vue'
import ResendReceiptDialog from '../components/ResendReceiptDialog.vue'
import ReturnDialog from '../components/ReturnDialog.vue'
import ShipmentDialog from '../components/ShipmentDialog.vue'
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
  dispute_status: string
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
    shipped_qty: number
    shippable_quantity: number
    returned_qty: number
    restocked_qty: number
    returnable_quantity: number
    subtotal: Money
    discount: Money
    total: Money
  }>
  billing: Address | null
  shipping: Address | null
  notes: Array<{ id: string; body: string; staff_name: string; created_at: string }>
  refunds: Array<{
    id: string
    status: string
    kind: string
    origin: string
    reason: string
    amount: Money
  }>
  disputes: Array<{
    id: string
    provider_dispute_id: string
    status: string
    reason: string | null
    amount: Money
    created_at: string
  }>
  shipments: Array<{
    id: string
    carrier: string | null
    tracking_number: string | null
    tracking_url: string | null
    created_at: string
    lines: Array<{ id: string; quantity: number }>
  }>
  returns: Array<{
    id: string
    status: string
    reason: string
    staff_name: string
    created_at: string
    lines: Array<{
      id: string
      requested: number
      approved: number
      received: number
      restocked: number
      refunds: Array<{
        id: string
        kind: string
        origin: string
        status: string
        quantity: number
        amount: Money
      }>
    }>
  }>
  downloads: Array<{
    id: string
    line_id: string
    title: string
    sku: string
    asset_label: string
    version_id: string
    version: number
    purchase_version: number
    status: string
    download_limit: number
    downloads_used: number
    downloads_remaining: number
    available_versions: Array<{ id: string; version: number; bytes: number }>
  }>
  refundable: { amount: number; money: Money; lines: Array<{ id: string; quantity: number }> }
  cancellable: boolean
  timeline: Array<{ id: string; type: string; created_at: string; amount: Money }>
}
const props = defineProps<{
  order: Order
  canRefund: boolean
  canCancel: boolean
  canResendReceipt: boolean
  canShip: boolean
  canReturn: boolean
  canManageDownloads: boolean
  saved: boolean
  refundSaved: boolean
  cancellationSaved: boolean
  receiptSaved: boolean
  shipmentSaved: boolean
  returnSaved: boolean
  downloadSaved: boolean
}>()
const note = useForm({ note: '' })
const submit = () => {
  note.post(`/admin/orders/${props.order.id}/notes`, {
    preserveScroll: true,
    onSuccess: () => note.reset('note'),
  })
}
type ReturnRecord = Order['returns'][number]
const orderLine = (id: string) => props.order.lines.find((line) => line.id === id)
const returnStepLines = (record: ReturnRecord, step: 'approve' | 'receive' | 'restock') =>
  record.lines.map((line) => ({
    id: line.id,
    title: orderLine(line.id)?.title ?? line.id,
    sku: orderLine(line.id)?.sku ?? '',
    max:
      step === 'approve'
        ? line.requested
        : step === 'receive'
          ? line.approved - line.received
          : line.received - line.restocked,
  }))
const canRestock = (record: ReturnRecord) =>
  record.lines.some((line) => line.received > line.restocked)
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
      <p v-if="shipmentSaved" class="workspace-note">Shipment recorded.</p>
      <p v-if="returnSaved" class="workspace-note">Return updated.</p>
      <p v-if="downloadSaved" class="workspace-note">Download access updated.</p>
      <p>
        {{ order.email }} · {{ order.created_at }} · order {{ order.status }} · financial
        {{ order.financial_status }} · fulfillment {{ order.fulfillment_status }} · downloads
        {{ order.download_status }}
        <span v-if="order.dispute_status !== 'none'">· dispute {{ order.dispute_status }}</span>
      </p>
      <p v-if="order.coupon_code">Coupon {{ order.coupon_code }}</p>
      <h2>Lines</h2>
      <ul class="product-list">
        <li v-for="line in order.lines" :key="line.id">
          <p>
            {{ line.title }} · {{ line.sku }} · {{ line.type }} · qty {{ line.quantity }} ·
            unshipped {{ line.refundable_quantity }} · shipped {{ line.shipped_qty }} · returned
            {{ line.returned_qty }} · restocked {{ line.restocked_qty }} ·
            {{ line.total.formatted }}
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
          <p>
            {{ refund.kind }} · {{ refund.origin }} · {{ refund.status }} ·
            {{ refund.amount.formatted }}
          </p>
          <p>{{ refund.reason }}</p>
        </li>
      </ul>
      <template v-if="order.disputes.length > 0">
        <h2>Disputes</h2>
        <p class="workspace-note">
          Provider facts from the payment provider. They are read-only here.
        </p>
        <ul class="product-list">
          <li v-for="dispute in order.disputes" :key="dispute.id">
            <p>
              {{ dispute.provider_dispute_id }} · {{ dispute.status }} ·
              {{ dispute.amount.formatted }} · {{ dispute.created_at }}
            </p>
            <p v-if="dispute.reason">{{ dispute.reason }}</p>
          </li>
        </ul>
      </template>
      <RefundDialog
        v-if="canRefund"
        :order-id="order.id"
        :refundable="order.refundable"
        :lines="order.lines"
      />
      <CancelOrderDialog v-if="canCancel" :order-id="order.id" :cancellable="order.cancellable" />
      <ResendReceiptDialog v-if="canResendReceipt" :order-id="order.id" />
      <ShipmentDialog v-if="canShip" :order-id="order.id" :lines="order.lines" />
      <h2>Shipments</h2>
      <p v-if="order.shipments.length === 0" class="workspace-note">No shipments yet.</p>
      <ul v-else class="product-list">
        <li v-for="shipment in order.shipments" :key="shipment.id">
          <p>
            {{ shipment.created_at }}
            <span v-if="shipment.carrier">· {{ shipment.carrier }}</span>
            <span v-if="shipment.tracking_number">· {{ shipment.tracking_number }}</span>
          </p>
          <p v-for="line in shipment.lines" :key="line.id">
            {{ line.quantity }} ×
            {{ order.lines.find((entry) => entry.id === line.id)?.title ?? line.id }}
          </p>
        </li>
      </ul>
      <ReturnDialog
        v-if="canReturn"
        :order-id="order.id"
        action="create"
        :lines="
          order.lines
            .filter((line) => line.type === 'physical')
            .map((line) => ({
              id: line.id,
              title: line.title,
              sku: line.sku,
              max: line.returnable_quantity,
            }))
        "
      />
      <h2>Returns</h2>
      <p class="workspace-note">
        Returns record approval, receipt and restock separately from refunds. Linked refund facts
        below are read from the refund history; a return never moves money by itself.
      </p>
      <p v-if="order.returns.length === 0" class="workspace-note">No returns yet.</p>
      <ul v-else class="product-list">
        <li v-for="record in order.returns" :key="record.id">
          <p>
            {{ record.created_at }} · {{ record.status }}
            <span v-if="record.staff_name">· {{ record.staff_name }}</span>
          </p>
          <p>{{ record.reason }}</p>
          <template v-for="line in record.lines" :key="line.id">
            <p>
              {{ orderLine(line.id)?.title ?? line.id }} · requested {{ line.requested }} · approved
              {{ line.approved }} · received {{ line.received }} · restocked
              {{ line.restocked }}
            </p>
            <p v-for="refund in line.refunds" :key="refund.id">
              refund {{ refund.kind }} · {{ refund.origin }} · {{ refund.status }} ·
              {{ refund.quantity }} · {{ refund.amount.formatted }}
            </p>
          </template>
          <ReturnDialog
            v-if="canReturn && record.status === 'requested'"
            :order-id="order.id"
            action="approve"
            :return-id="record.id"
            :lines="returnStepLines(record, 'approve')"
          />
          <ReturnDialog
            v-if="
              canReturn && (record.status === 'approved' || record.status === 'partially_received')
            "
            :order-id="order.id"
            action="receive"
            :return-id="record.id"
            :lines="returnStepLines(record, 'receive')"
          />
          <ReturnDialog
            v-if="canReturn && canRestock(record)"
            :order-id="order.id"
            action="restock"
            :return-id="record.id"
            :lines="returnStepLines(record, 'restock')"
          />
        </li>
      </ul>
      <DownloadAccess v-if="canManageDownloads" :order-id="order.id" :downloads="order.downloads" />
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
