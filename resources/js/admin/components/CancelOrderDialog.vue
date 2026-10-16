<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'AdminCancelOrderDialog' })

const props = defineProps<{
  orderId: string
  cancellable: boolean
}>()
const form = useForm({
  reason: '',
  idempotency_key: crypto.randomUUID(),
})
const submit = () => {
  form.idempotency_key = crypto.randomUUID()
  form.post(`/admin/orders/${props.orderId}/cancellation`, { preserveScroll: true })
}
</script>

<template>
  <form v-if="cancellable" class="setup-form" @submit.prevent="submit">
    <h2>Cancel order</h2>
    <p class="workspace-note">
      Cancellation refunds remaining capture and releases unshipped allocations. Shipped lines
      require a return.
    </p>
    <label>
      Reason
      <textarea v-model="form.reason" name="reason" maxlength="500" rows="3" required />
    </label>
    <button type="submit" class="primary-button" :disabled="form.processing">
      {{ form.processing ? 'Cancelling…' : 'Cancel order' }}
    </button>
  </form>
</template>
