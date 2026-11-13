<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'CampaignIndex' })

type Campaign = {
  id: string
  name: string
  status: string
  revision: number
}
defineProps<{
  campaigns: Campaign[]
  canWrite: boolean
}>()
</script>

<template>
  <Head title="Campaigns" />
  <a class="skip-link" href="#campaigns-main">Skip to campaigns</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="campaigns-main" class="setup-main">
      <p class="eyebrow">Marketing</p>
      <h1>Campaigns<span class="accent">.</span></h1>
      <p class="lede">
        Draft versioned one-off email campaigns for verified newsletter opt-in audiences. Preview
        schedules and caps here. Activation and send are separate permissions, and this workspace
        does not deliver live campaign mail.
      </p>
      <p v-if="canWrite">
        <a class="primary-button" href="/admin/marketing/campaigns/create">Create a campaign</a>
      </p>
      <p v-if="campaigns.length === 0" class="workspace-note">No campaigns yet.</p>
      <ul v-else class="product-list">
        <li v-for="campaign in campaigns" :key="campaign.id">
          <a class="tool-link" :href="`/admin/marketing/campaigns/${campaign.id}`">{{
            campaign.name
          }}</a>
          <p>{{ campaign.status }} · revision {{ campaign.revision }}</p>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
