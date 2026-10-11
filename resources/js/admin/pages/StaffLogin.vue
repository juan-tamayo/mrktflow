<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { nextTick, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'

const form = useForm({ email: '', password: '' }).dontRemember('password')
const errors = ref<HTMLElement | null>(null)
function signIn() {
  form.post('/admin/login', {
    onFinish: () => form.reset('password'),
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
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
      <form class="setup-unlock" @submit.prevent="signIn">
        <div
          v-if="Object.keys(form.errors).length"
          ref="errors"
          class="error-summary"
          tabindex="-1"
          role="alert"
        >
          <p v-for="(message, field) in form.errors" :key="field">{{ message }}</p>
        </div>
        <div class="setup-field">
          <label for="staff-email">Email</label
          ><input
            id="staff-email"
            v-model="form.email"
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
            v-model="form.password"
            type="password"
            required
            maxlength="72"
            autocomplete="current-password"
          />
        </div>
        <button class="primary-button" type="submit" :disabled="form.processing">
          {{ form.processing ? 'Signing in…' : 'Sign in' }}
        </button>
      </form>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/">Return to storefront</a></footer>
  </div>
</template>
