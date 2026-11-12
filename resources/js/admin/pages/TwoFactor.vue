<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { computed, onMounted, ref } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'

defineProps<{
  mode: 'enroll' | 'challenge' | 'codes' | 'reauthenticate'
  secret: string | null
  uri: string | null
  issuer: string | null
  label: string | null
  recoveryCodes: string[]
}>()
const page = usePage()
const formErrors = computed(() => {
  const bag = page.props.errors
  if (bag === null || typeof bag !== 'object') return {} as Record<string, string>
  return bag as Record<string, string>
})
const errors = ref<HTMLElement | null>(null)
const csrf = computed(
  () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
)
onMounted(() => {
  if (Object.keys(formErrors.value).length > 0) {
    errors.value?.focus()
  }
})
</script>

<template>
  <Head title="Staff authenticator" />
  <a class="skip-link" href="#two-factor-main">Skip to authenticator</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><span class="status">Store administration</span>
    </header>
    <main id="two-factor-main" class="setup-main">
      <p class="eyebrow">Privileged staff access</p>
      <template v-if="mode === 'enroll'">
        <h1>Protect this account<span class="accent">.</span></h1>
        <p class="lede">
          Owners and privileged staff must confirm a time-based authenticator before using the
          workspace. Store the secret in your authenticator app, then enter the current code.
        </p>
        <form method="post" action="/admin/two-factor" class="setup-form">
          <input type="hidden" name="_token" :value="csrf" />
          <div
            v-if="Object.keys(formErrors).length"
            ref="errors"
            class="error-summary"
            tabindex="-1"
            role="alert"
          >
            <p v-for="(message, field) in formErrors" :key="field">{{ message }}</p>
          </div>
          <div class="setup-field">
            <label for="two-factor-issuer">Issuer</label
            ><input id="two-factor-issuer" :value="issuer ?? ''" readonly />
          </div>
          <div class="setup-field">
            <label for="two-factor-label">Account</label
            ><input id="two-factor-label" :value="label ?? ''" readonly />
          </div>
          <div class="setup-field">
            <label for="two-factor-secret">Authenticator secret</label
            ><input
              id="two-factor-secret"
              :value="secret ?? ''"
              readonly
              autocomplete="off"
              spellcheck="false"
            />
          </div>
          <div class="setup-field">
            <label for="two-factor-uri">Provisioning URI</label
            ><input
              id="two-factor-uri"
              :value="uri ?? ''"
              readonly
              autocomplete="off"
              spellcheck="false"
            />
          </div>
          <p class="field-help">
            Add this secret to a TOTP app that uses SHA-1, 6 digits, and a 30-second period. QR
            codes are not generated in this release.
          </p>
          <div class="setup-field">
            <label for="two-factor-code">Authentication code</label
            ><input
              id="two-factor-code"
              name="code"
              inputmode="numeric"
              autocomplete="one-time-code"
              required
              maxlength="32"
            />
          </div>
          <button class="primary-button" type="submit">Confirm authenticator</button>
        </form>
      </template>
      <template v-else-if="mode === 'challenge'">
        <h1>Confirm it's you<span class="accent">.</span></h1>
        <p class="lede">
          Enter the current authenticator code, or an unused recovery code if this device was lost.
          A recovery code signs this authenticator out and requires a new enrollment.
        </p>
        <form method="post" action="/admin/two-factor/challenge" class="setup-unlock">
          <input type="hidden" name="_token" :value="csrf" />
          <div
            v-if="Object.keys(formErrors).length"
            ref="errors"
            class="error-summary"
            tabindex="-1"
            role="alert"
          >
            <p v-for="(message, field) in formErrors" :key="field">{{ message }}</p>
          </div>
          <div class="setup-field">
            <label for="two-factor-challenge">Authentication code</label
            ><input
              id="two-factor-challenge"
              name="code"
              autocomplete="one-time-code"
              required
              maxlength="32"
            />
          </div>
          <button class="primary-button" type="submit">Verify</button>
        </form>
      </template>
      <template v-else-if="mode === 'codes'">
        <h1>Save these recovery codes<span class="accent">.</span></h1>
        <p class="lede">
          Store them offline. Each code works once. Reloading this page hides them. A lost
          authenticator uses one code, then requires a new authenticator.
        </p>
        <p role="status" class="success-notice">Recovery codes are shown once.</p>
        <ul class="recovery-codes">
          <li v-for="code in recoveryCodes" :key="code" class="recovery-code">{{ code }}</li>
        </ul>
        <a class="primary-button" href="/admin">Continue to workspace</a>
      </template>
      <template v-else>
        <h1>Reconfirm this action<span class="accent">.</span></h1>
        <p class="lede">
          Staff, credential, and refund changes need a fresh password and authenticator code. This
          confirmation lasts 15 minutes.
        </p>
        <form method="post" action="/admin/two-factor/reauthenticate" class="setup-form">
          <input type="hidden" name="_token" :value="csrf" />
          <div
            v-if="Object.keys(formErrors).length"
            ref="errors"
            class="error-summary"
            tabindex="-1"
            role="alert"
          >
            <p v-for="(message, field) in formErrors" :key="field">{{ message }}</p>
          </div>
          <div class="setup-field">
            <label for="two-factor-password">Current password</label
            ><input
              id="two-factor-password"
              name="password"
              type="password"
              required
              maxlength="72"
              autocomplete="current-password"
            />
          </div>
          <div class="setup-field">
            <label for="two-factor-reauth-code">Authentication code</label
            ><input
              id="two-factor-reauth-code"
              name="code"
              autocomplete="one-time-code"
              required
              maxlength="32"
            />
          </div>
          <button class="primary-button" type="submit">Confirm identity</button>
        </form>
      </template>
      <form method="post" action="/admin/logout" class="workspace-account">
        <input type="hidden" name="_token" :value="csrf" />
        <button type="submit" class="quiet-button">Sign out</button>
      </form>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/">Return to storefront</a></footer>
  </div>
</template>
