<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { computed, onMounted, ref } from 'vue'
import { Head, usePage } from '@inertiajs/vue3'

const page = usePage()
const errors = computed(() => {
  const bag = page.props.errors
  if (bag === null || typeof bag !== 'object') return {} as Record<string, string>
  return bag as Record<string, string>
})
const summary = ref<HTMLElement | null>(null)
const csrf = computed(
  () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? '',
)
onMounted(() => {
  if (Object.keys(errors.value).length > 0) {
    summary.value?.focus()
  }
})
</script>

<template>
  <Head title="Staff sign-in" />
  <a class="skip-link" href="#login-main">Skip to sign-in</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/" /><span class="status">Store administration</span>
    </header>
    <main id="login-main" class="setup-main">
      <p class="eyebrow">Your store workspace</p>
      <h1>Welcome back<span class="accent">.</span></h1>
      <p class="lede">Sign in with your owner or invited staff account.</p>
      <form method="post" action="/admin/login" class="setup-unlock">
        <input type="hidden" name="_token" :value="csrf" />
        <div
          v-if="Object.keys(errors).length"
          ref="summary"
          class="error-summary"
          tabindex="-1"
          role="alert"
        >
          <p v-for="(message, field) in errors" :key="field">{{ message }}</p>
        </div>
        <div class="setup-field">
          <label for="staff-email">Email</label
          ><input
            id="staff-email"
            name="email"
            type="email"
            required
            maxlength="254"
            autocomplete="username"
          />
        </div>
        <div class="setup-field">
          <label for="staff-password">Password</label
          ><input
            id="staff-password"
            name="password"
            type="password"
            required
            maxlength="72"
            autocomplete="current-password"
          />
        </div>
        <button class="primary-button" type="submit">Sign in</button>
      </form>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/">Return to storefront</a></footer>
  </div>
</template>
