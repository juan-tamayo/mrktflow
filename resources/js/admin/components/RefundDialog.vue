<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'AdminRefundDialog' })

type Money = { amount: number; currency: string; exponent: number; formatted: string }
type Line = {
  id: string
  title: string
  sku: string
  type: string
  quantity: number
  refundable_quantity: number
  total: Money
}
const props = defineProps<{
  orderId: string
  refundable: { amount: number; money: Money; lines: Array<{ id: string; quantity: number }> }
  lines: Line[]
}>()
const form = useForm({
  reason: '',
  amount: 0,
  shipping_amount: 0,
  idempotency_key: crypto.randomUUID(),
  lines: props.lines.map((line) => ({ id: line.id, quantity: 0 })),
})
const submit = () => {
  form.idempotency_key = crypto.randomUUID()
  form.post(`/admin/orders/${props.orderId}/refunds`, { preserveScroll: true })
}
const catalogLine = (id: string): Line | undefined => props.lines.find((line) => line.id === id)
</script>

<template>
  <form class="setup-form" @submit.prevent="submit">
    <h2>Refund</h2>
    <p class="workspace-note">
      Remaining {{ refundable.money.formatted }}. Quantity refunds hold unshipped units.
    </p>
    <fieldset v-for="item in form.lines" :key="item.id">
      <label>
        {{ catalogLine(item.id)?.title }} ({{ catalogLine(item.id)?.sku }}) unshipped
        {{ catalogLine(item.id)?.refundable_quantity }}
        <input
          v-model.number="item.quantity"
          type="number"
          min="0"
          :max="catalogLine(item.id)?.refundable_quantity ?? 0"
          :name="`lines-${item.id}`"
        />
      </label>
    </fieldset>
    <label>
      Goodwill amount
      <input
        v-model.number="form.amount"
        type="number"
        min="0"
        :max="refundable.amount"
        name="amount"
      />
    </label>
    <label>
      Shipping amount
      <input v-model.number="form.shipping_amount" type="number" min="0" name="shipping_amount" />
    </label>
    <label>
      Reason
      <textarea v-model="form.reason" name="reason" maxlength="500" rows="3" required />
    </label>
    <button type="submit" class="primary-button" :disabled="form.processing">
      {{ form.processing ? 'Refunding…' : 'Request refund' }}
    </button>
  </form>
</template>
