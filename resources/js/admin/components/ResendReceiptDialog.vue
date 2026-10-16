<script setup lang="ts">
import { useForm } from '@inertiajs/vue3'

defineOptions({ name: 'AdminResendReceiptDialog' })

const props = defineProps<{
  orderId: string
}>()
const form = useForm({
  idempotency_key: crypto.randomUUID(),
})
const submit = () => {
  form.idempotency_key = crypto.randomUUID()
  form.post(`/admin/orders/${props.orderId}/receipt`, { preserveScroll: true })
}
</script>

<template>
  <form class="setup-form" @submit.prevent="submit">
    <h2>Resend receipt</h2>
    <p class="workspace-note">
      Resend queues a new receipt intent. It does not replay the original paid event.
    </p>
    <button type="submit" class="primary-button" :disabled="form.processing">
      {{ form.processing ? 'Queueing…' : 'Resend receipt' }}
    </button>
  </form>
</template>
