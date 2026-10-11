<script setup lang="ts">
import { nextTick, ref } from 'vue'

const email = ref('')
const role = ref('content_editor')
const busy = ref(false)
const message = ref('')
const invitationUrl = ref('')
const errors = ref<HTMLElement | null>(null)
const linkField = ref<HTMLTextAreaElement | null>(null)
const copied = ref(false)
async function create() {
  message.value = ''
  invitationUrl.value = ''
  copied.value = false
  busy.value = true
  const controller = new AbortController()
  const timeout = window.setTimeout(() => controller.abort(), 20_000)
  try {
    const cookie = document.cookie.split('; ').find((value) => value.startsWith('XSRF-TOKEN='))
    if (!cookie) throw new Error('Missing request-forgery proof')
    const response = await fetch('/admin/staff/invitations', {
      method: 'POST',
      credentials: 'same-origin',
      signal: controller.signal,
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-XSRF-TOKEN': decodeURIComponent(cookie.slice('XSRF-TOKEN='.length)),
      },
      body: JSON.stringify({ invitation: { email: email.value, role: role.value } }),
    })
    if (response.status === 429)
      message.value = 'Too many invitations. Wait a minute and try again.'
    else if (response.status === 401 || response.status === 403 || response.status === 419)
      message.value = 'Your access changed or expired. Sign in again before inviting staff.'
    else if (response.status === 409)
      message.value = 'A staff account already uses this email. Edit their existing access instead.'
    else if (response.status === 422) message.value = 'Check the email address and selected role.'
    else if (response.status !== 201)
      message.value = 'The invitation could not be created. Try again.'
    else {
      const result: unknown = await response.json()
      if (
        !result ||
        typeof result !== 'object' ||
        !('path' in result) ||
        typeof result.path !== 'string'
      )
        throw new Error('Invalid invitation response')
      const url = new URL(result.path, window.location.origin)
      if (
        url.origin !== window.location.origin ||
        url.pathname !== '/staff/invitation' ||
        url.hash.length !== 65 ||
        !/^#[a-f0-9]+$/.test(url.hash)
      )
        throw new Error('Invalid invitation location')
      invitationUrl.value = url.href
    }
  } catch {
    message.value =
      'The invitation response was interrupted. Try again to create a new link; any previous unused link for this email will stop working.'
  } finally {
    window.clearTimeout(timeout)
    busy.value = false
    if (message.value) {
      await nextTick()
      errors.value?.focus()
    }
  }
}
async function copy() {
  try {
    await navigator.clipboard.writeText(invitationUrl.value)
    copied.value = true
  } catch {
    linkField.value?.focus()
    linkField.value?.select()
  }
}
</script>

<template>
  <section aria-labelledby="invite-staff-heading">
    <h2 id="invite-staff-heading">Invite a team member</h2>
    <p class="field-help">
      Create a private link and share it directly with the intended person. It expires after 48
      hours. Creating another invitation for the same email revokes its previous unused link.
    </p>
    <form class="setup-form" @submit.prevent="create">
      <div v-if="message" ref="errors" class="error-summary" role="alert" tabindex="-1">
        {{ message }}
      </div>
      <div class="setup-grid">
        <div class="setup-field">
          <label for="invited-email">Invited staff email</label
          ><input
            id="invited-email"
            v-model="email"
            type="email"
            required
            maxlength="254"
            autocomplete="off"
          />
        </div>
        <div class="setup-field">
          <label for="invited-role">Invited staff role</label
          ><select id="invited-role" v-model="role">
            <option value="owner">Owner — full store control</option>
            <option value="manager">Manager — products, orders, content and marketing</option>
            <option value="content_editor">Content editor — drafts and media</option>
            <option value="fulfillment">Fulfillment — orders and shipping</option>
          </select>
        </div>
      </div>
      <button type="submit" class="primary-button" :disabled="busy">
        {{ busy ? 'Creating invitation…' : 'Create invitation link' }}
      </button>
    </form>
    <div v-if="invitationUrl" role="region" aria-label="Your new invitation">
      <p class="success-notice" role="status">
        Invitation created. Save the link now; it disappears when you reload this page.
      </p>
      <label for="invitation-link">Private invitation link</label
      ><textarea
        id="invitation-link"
        ref="linkField"
        :value="invitationUrl"
        readonly
        rows="3"
        spellcheck="false"
      />
      <button type="button" class="primary-button" @click="copy">Copy invitation link</button>
      <p v-if="copied" role="status">Invitation link copied.</p>
    </div>
  </section>
</template>
