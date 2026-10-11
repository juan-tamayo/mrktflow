<script setup lang="ts">
import { nextTick, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import { takeInvitationToken } from '../invitation-token'

const form = useForm({
  invitation_token: takeInvitationToken(),
  owner: { name: '', password: '', password_confirmation: '' },
}).dontRemember('invitation_token', 'owner')
const errors = ref<HTMLElement | null>(null)
function accept() {
  form.post('/staff/invitation', {
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
  <Head title="Accept staff invitation" />
  <a class="skip-link" href="#invitation-main">Skip to invitation</a>
  <div class="page-shell">
    <header class="masthead">
      <a href="/" class="wordmark">mrktflow<span aria-hidden="true">•</span></a
      ><span class="status">Staff invitation</span>
    </header>
    <main id="invitation-main" class="setup-main">
      <p class="eyebrow">Join the store team</p>
      <h1>Your workspace awaits<span class="accent">.</span></h1>
      <p class="lede">
        An owner has chosen your staff email and role. Set your name and a unique password to accept
        their invitation.
      </p>
      <p v-if="!form.invitation_token" role="status" class="success-notice">
        Open the complete invitation link supplied by your store owner. If you refreshed this page,
        open the original link again.
      </p>
      <form v-else class="setup-form" @submit.prevent="accept">
        <div
          v-if="Object.keys(form.errors).length"
          ref="errors"
          class="error-summary"
          role="alert"
          tabindex="-1"
        >
          <p v-for="(message, field) in form.errors" :key="field">{{ message }}</p>
        </div>
        <div class="setup-field">
          <label for="invite-name">Your name</label
          ><input
            id="invite-name"
            v-model="form.owner.name"
            required
            maxlength="120"
            autocomplete="name"
          />
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="invite-password">Password</label
            ><input
              id="invite-password"
              v-model="form.owner.password"
              type="password"
              required
              minlength="12"
              maxlength="72"
              autocomplete="new-password"
              aria-describedby="invite-password-help"
            />
          </div>
          <div class="setup-field">
            <label for="invite-confirmation">Confirm password</label
            ><input
              id="invite-confirmation"
              v-model="form.owner.password_confirmation"
              type="password"
              required
              maxlength="72"
              autocomplete="new-password"
            />
          </div>
        </div>
        <p id="invite-password-help" class="field-help">
          Use at least 12 characters and at most 72 bytes. Your invitation expires after 48 hours
          and can be used once.
        </p>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Creating your account…' : 'Accept invitation' }}
        </button>
      </form>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin/login">Staff sign-in</a></footer>
  </div>
</template>
