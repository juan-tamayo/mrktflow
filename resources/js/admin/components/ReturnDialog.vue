<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'AdminReturnDialog' })

type Line = {
  id: string
  title: string
  sku: string
  max: number
}
type Action = 'create' | 'approve' | 'receive' | 'restock'
const props = defineProps<{
  orderId: string
  action: Action
  returnId?: string
  lines: Line[]
}>()
const copy: Record<Action, { heading: string; note: string; unit: string; button: string }> = {
  create: {
    heading: 'Open a return',
    note: 'Returns record goods only. Refunds stay separate, and stock comes back only through an explicit restock.',
    unit: 'returnable',
    button: 'Open return',
  },
  approve: {
    heading: 'Approve return',
    note: 'Approve the quantities the customer may send back. Zero rejects the line.',
    unit: 'requested',
    button: 'Record approval',
  },
  receive: {
    heading: 'Receive return',
    note: 'Receiving records the goods in hand. It never restocks or refunds by itself.',
    unit: 'approved outstanding',
    button: 'Record receipt',
  },
  restock: {
    heading: 'Restock return',
    note: 'Restocking is deliberate: only the quantities you enter go back on hand.',
    unit: 'received not restocked',
    button: 'Restock items',
  },
}
const text = copy[props.action]
const available = props.lines.filter((line) => line.max > 0)
const form = useForm({
  reason: '',
  idempotency_key: crypto.randomUUID(),
  lines: available.map((line) => ({ id: line.id, quantity: 0 })),
})
const url =
  props.action === 'create'
    ? `/admin/orders/${props.orderId}/returns`
    : `/admin/orders/${props.orderId}/returns/${props.returnId}/${props.action}`
const submit = () => {
  form.idempotency_key = crypto.randomUUID()
  form.post(url, { preserveScroll: true })
}
const lineFor = (id: string): Line | undefined => props.lines.find((line) => line.id === id)
</script>

<template>
  <form class="setup-form" @submit.prevent="submit">
    <h2>{{ text.heading }}</h2>
    <p class="workspace-note">{{ text.note }}</p>
    <p v-if="available.length === 0" class="workspace-note">Nothing to record for this step.</p>
    <template v-else>
      <fieldset v-for="item in form.lines" :key="item.id">
        <label>
          {{ lineFor(item.id)?.title }} ({{ lineFor(item.id)?.sku }}) {{ text.unit }}
          {{ lineFor(item.id)?.max }}
          <input
            v-model.number="item.quantity"
            type="number"
            min="0"
            :max="lineFor(item.id)?.max ?? 0"
            :name="`lines-${item.id}`"
          />
        </label>
      </fieldset>
      <label v-if="action === 'create'">
        Reason
        <textarea v-model="form.reason" name="reason" maxlength="500" rows="3" required />
      </label>
      <button type="submit" class="primary-button" :disabled="form.processing">
        {{ form.processing ? 'Recording…' : text.button }}
      </button>
    </template>
  </form>
</template>
