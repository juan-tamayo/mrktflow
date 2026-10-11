<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { useForm } from '@inertiajs/vue3'

defineProps<{ blocked: boolean }>()
const form = useForm({
  owner: { name: '', email: '', password: '', password_confirmation: '' },
}).dontRemember('owner')
const errors = ref<HTMLElement | null>(null)
function createOwner() {
  form.post('/setup/owner', {
    preserveScroll: true,
    onFinish: () => {
      form.owner.password = ''
      form.owner.password_confirmation = ''
    },
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
</script>

<template>
  <section aria-labelledby="owner-heading">
    <h2 id="owner-heading">Create your owner account</h2>
    <p class="field-help">
      This account controls your store and staff access. Creating it finishes installation and
      permanently closes the setup wizard. Your storefront stays closed while you prepare to launch.
    </p>
    <form class="setup-form" @submit.prevent="createOwner">
      <div
        v-if="Object.keys(form.errors).length"
        ref="errors"
        class="error-summary"
        tabindex="-1"
        role="alert"
      >
        <p>Check your account details and try again.</p>
        <ul>
          <li v-for="(message, field) in form.errors" :key="field">{{ message }}</li>
        </ul>
      </div>
      <div class="setup-grid">
        <div class="setup-field">
          <label for="owner-name">Your name</label>
          <input
            id="owner-name"
            v-model="form.owner.name"
            required
            maxlength="120"
            autocomplete="name"
          />
        </div>
        <div class="setup-field">
          <label for="owner-email">Owner email</label>
          <input
            id="owner-email"
            v-model="form.owner.email"
            type="email"
            required
            maxlength="254"
            autocomplete="username"
          />
        </div>
        <div class="setup-field">
          <label for="owner-password">Password</label>
          <input
            id="owner-password"
            v-model="form.owner.password"
            type="password"
            required
            minlength="12"
            maxlength="72"
            autocomplete="new-password"
            aria-describedby="owner-password-help"
          />
        </div>
        <div class="setup-field">
          <label for="owner-confirmation">Confirm password</label>
          <input
            id="owner-confirmation"
            v-model="form.owner.password_confirmation"
            type="password"
            required
            maxlength="72"
            autocomplete="new-password"
          />
        </div>
      </div>
      <p id="owner-password-help" class="field-help">
        Use a long, unique password: at least 12 characters and at most 72 bytes.
      </p>
      <button class="primary-button" type="submit" :disabled="blocked || form.processing">
        {{ form.processing ? 'Creating account…' : 'Create owner account' }}
      </button>
    </form>
  </section>
</template>
