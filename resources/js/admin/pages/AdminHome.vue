<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3'

defineProps<{ staff: { name: string; role: string }; canManageStaff: boolean }>()
const logout = useForm({})
</script>

<template>
  <Head title="Store workspace" />
  <a class="skip-link" href="#workspace-main">Skip to workspace</a>
  <div class="page-shell">
    <header class="masthead">
      <a class="wordmark" href="/admin">mrktflow<span aria-hidden="true">•</span></a
      ><span class="status">Store workspace</span>
    </header>
    <main id="workspace-main" class="setup-main">
      <p class="eyebrow">{{ staff.role.replaceAll('_', ' ') }} account</p>
      <h1>Your store starts here<span class="accent">.</span></h1>
      <p class="lede">
        Welcome, {{ staff.name }}. Installation is complete. Your storefront stays closed while you
        add products and finish launch checks.
      </p>
      <p v-if="canManageStaff"><a class="text-link" href="/admin/staff">Manage staff</a></p>
      <form @submit.prevent="logout.post('/admin/logout')">
        <button type="submit" class="primary-button" :disabled="logout.processing">
          {{ logout.processing ? 'Signing out…' : 'Sign out' }}
        </button>
      </form>
    </main>
    <footer class="footer">
      <span>Your store. Your infrastructure.</span><a href="/">View storefront</a>
    </footer>
  </div>
</template>
