<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

const props = defineProps<{
  account: {
    id: string
    name: string
    email: string
    role: string
    is_active: boolean
    version: number
  }
}>()
const form = useForm({
  access: {
    role: props.account.role,
    is_active: props.account.is_active,
    version: props.account.version,
  },
})
const errors = ref<HTMLElement | null>(null)
const roles = [
  {
    id: 'owner',
    label: 'Owner',
    description: 'Full store control, including staff, settings, integrations and refunds.',
  },
  {
    id: 'manager',
    label: 'Manager',
    description:
      'Products, orders, content and marketing. Cannot manage staff, integrations or refunds.',
  },
  {
    id: 'content_editor',
    label: 'Content editor',
    description: 'Draft content, upload media and use AI. Cannot publish or manage orders.',
  },
  {
    id: 'fulfillment',
    label: 'Fulfillment',
    description: 'Read orders and manage fulfillment. Cannot change prices, content or staff.',
  },
]
function save() {
  form.patch(`/admin/staff/${props.account.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      form.access.version = props.account.version
      form.defaults()
    },
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
</script>

<template>
  <section :aria-labelledby="`staff-${account.id}`">
    <h2 :id="`staff-${account.id}`">{{ account.name }}</h2>
    <p>{{ account.email }}</p>
    <form class="setup-form" @submit.prevent="save">
      <div
        v-if="Object.keys(form.errors).length"
        ref="errors"
        role="alert"
        tabindex="-1"
        class="error-summary"
      >
        <p v-for="(message, field) in form.errors" :key="field">{{ message }}</p>
        <a href="/admin/staff" class="text-link">Reload the staff list</a>
      </div>
      <div class="setup-grid">
        <div class="setup-field">
          <label :for="`role-${account.id}`">Role for {{ account.name }}</label
          ><select
            :id="`role-${account.id}`"
            v-model="form.access.role"
            :aria-describedby="`role-help-${account.id}`"
          >
            <option v-for="role in roles" :key="role.id" :value="role.id">{{ role.label }}</option>
          </select>
          <p :id="`role-help-${account.id}`" class="field-help">
            {{ roles.find((role) => role.id === form.access.role)?.description }}
          </p>
        </div>
        <div class="setup-field">
          <label :for="`status-${account.id}`">Access for {{ account.name }}</label
          ><select :id="`status-${account.id}`" v-model="form.access.is_active">
            <option :value="true">Active</option>
            <option :value="false">Disabled</option>
          </select>
        </div>
      </div>
      <button type="submit" class="primary-button" :disabled="form.processing">
        {{ form.processing ? 'Saving access…' : `Save access for ${account.name}` }}
      </button>
    </form>
  </section>
</template>
