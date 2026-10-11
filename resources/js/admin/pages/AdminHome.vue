<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'

defineProps<{ staff: { name: string; role: string }; canManageStaff: boolean }>()
const logout = useForm({})
</script>

<template>
  <Head title="Store workspace" />
  <a class="skip-link" href="#workspace-main">Skip to workspace</a>
  <div class="page-shell workspace-shell">
    <header class="masthead">
      <BrandLogo href="/admin" />
      <div class="workspace-account">
        <span class="status">Store workspace</span>
        <form @submit.prevent="logout.post('/admin/logout')">
          <button type="submit" class="quiet-button" :disabled="logout.processing">
            {{ logout.processing ? 'Signing out…' : 'Sign out' }}
          </button>
        </form>
      </div>
    </header>
    <main id="workspace-main" class="workspace-main">
      <div class="workspace-heading">
        <div>
          <p class="eyebrow">{{ staff.role.replaceAll('_', ' ') }} account</p>
          <h1>Your store starts here<span class="accent">.</span></h1>
        </div>
        <p class="workspace-welcome">Welcome, {{ staff.name }}.</p>
      </div>
      <div class="workspace-grid">
        <section class="launch-panel" aria-labelledby="launch-heading">
          <div class="launch-topline">
            <p class="panel-label">Your next chapter</p>
            <span class="closed-badge">Storefront closed</span>
          </div>
          <h2 id="launch-heading">A little preparation.<br />A store that's yours.</h2>
          <p>
            Installation is complete. Your storefront stays closed while you add products and finish
            launch checks.
          </p>
          <a href="/" class="light-button">View storefront <span aria-hidden="true">↗</span></a>
          <div class="launch-signature" aria-hidden="true">
            <img :src="'/brand/mark.svg'" width="120" height="120" alt="" />
            <span>Independent<br />by design.</span>
          </div>
        </section>
        <section class="workspace-tools" aria-labelledby="workspace-tools-heading">
          <p class="panel-label">Make yourself at home</p>
          <h2 id="workspace-tools-heading">Your workspace</h2>
          <div v-if="canManageStaff" class="workspace-tool">
            <span class="tool-number" aria-hidden="true">01</span>
            <div>
              <a class="tool-link" href="/admin/staff">Manage staff</a>
              <p>Invite your team and choose who can do what.</p>
            </div>
            <span aria-hidden="true">↗</span>
          </div>
          <div class="workspace-note">
            <h3>Built on your terms</h3>
            <p>
              Your store runs on your own infrastructure. This workspace will grow as the catalog,
              checkout, and marketing features are added.
            </p>
          </div>
          <p class="foundation-label">Foundation installed · Commerce features in development</p>
        </section>
      </div>
    </main>
    <footer class="footer">
      <span>Your store. Your infrastructure.</span><span>MRKTFlow</span>
    </footer>
  </div>
</template>
