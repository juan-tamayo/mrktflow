<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'AdminShipmentDialog' })

type Line = {
  id: string
  title: string
  sku: string
  type: string
  quantity: number
  shipped_qty: number
  shippable_quantity: number
}
const props = defineProps<{ orderId: string; lines: Line[] }>()
const shippable = props.lines.filter(
  (line) => line.type === 'physical' && line.shippable_quantity > 0,
)
const form = useForm({
  carrier: '',
  tracking_number: '',
  tracking_url: '',
  idempotency_key: crypto.randomUUID(),
  lines: shippable.map((line) => ({ id: line.id, quantity: 0 })),
})
const submit = () => {
  form.idempotency_key = crypto.randomUUID()
  form.post(`/admin/orders/${props.orderId}/shipments`, { preserveScroll: true })
}
const catalogLine = (id: string): Line | undefined => props.lines.find((line) => line.id === id)
</script>

<template>
  <form class="setup-form" @submit.prevent="submit">
    <h2>Ship items</h2>
    <p class="workspace-note">Shipments consume paid unshipped quantities and stock once.</p>
    <p v-if="shippable.length === 0" class="workspace-note">Nothing left to ship.</p>
    <template v-else>
      <fieldset v-for="item in form.lines" :key="item.id">
        <label>
          {{ catalogLine(item.id)?.title }} ({{ catalogLine(item.id)?.sku }}) unshipped
          {{ catalogLine(item.id)?.shippable_quantity }}
          <input
            v-model.number="item.quantity"
            type="number"
            min="0"
            :max="catalogLine(item.id)?.shippable_quantity ?? 0"
            :name="`lines-${item.id}`"
          />
        </label>
      </fieldset>
      <label>
        Carrier
        <input v-model="form.carrier" type="text" name="carrier" maxlength="120" />
      </label>
      <label>
        Tracking number
        <input v-model="form.tracking_number" type="text" name="tracking_number" maxlength="191" />
      </label>
      <label>
        Tracking URL
        <input v-model="form.tracking_url" type="text" name="tracking_url" maxlength="1024" />
      </label>
      <button type="submit" class="primary-button" :disabled="form.processing">
        {{ form.processing ? 'Recording…' : 'Record shipment' }}
      </button>
    </template>
  </form>
</template>
