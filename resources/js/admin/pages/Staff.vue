<script setup lang="ts">
import { Head } from '@inertiajs/vue3'
import StaffAccess from '../components/StaffAccess.vue'

defineOptions({ name: 'StaffManagement' })

type Account = {
  id: string
  name: string
  email: string
  role: string
  is_active: boolean
  version: number
}
defineProps<{
  accounts: { data: Account[]; next_page_url: string | null; prev_page_url: string | null }
  saved: boolean
}>()
</script>

<template>
  <Head title="Staff access" />
  <a class="skip-link" href="#staff-main">Skip to staff</a>
  <div class="page-shell">
    <header class="masthead">
      <a href="/admin" class="wordmark">mrktflow<span aria-hidden="true">•</span></a
      ><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="staff-main" class="setup-main">
      <p class="eyebrow">People and permissions</p>
      <h1>Staff access<span class="accent">.</span></h1>
      <p class="lede">
        Choose who can run your store. Changes sign the affected account out of existing sessions.
        Keep at least one active owner.
      </p>
      <p v-if="saved" role="status" class="success-notice">Staff access saved.</p>
      <StaffAccess v-for="account in accounts.data" :key="account.id" :account="account" />
      <nav aria-label="Staff list pages">
        <a v-if="accounts.prev_page_url" :href="accounts.prev_page_url" class="text-link"
          >Previous accounts</a
        >
        <a v-if="accounts.next_page_url" :href="accounts.next_page_url" class="text-link"
          >More accounts</a
        >
      </nav>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
