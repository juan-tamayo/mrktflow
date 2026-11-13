<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'CouponCampaignIndex' })

type Campaign = {
  id: string
  name: string
  kind: string
  status: string
  revision: number
}
defineProps<{
  campaigns: Campaign[]
  canWrite: boolean
}>()
</script>

<template>
  <Head title="Coupon campaigns" />
  <a class="skip-link" href="#coupon-campaigns-main">Skip to coupon campaigns</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="coupon-campaigns-main" class="setup-main">
      <p class="eyebrow">Pricing</p>
      <h1>Coupon campaigns<span class="accent">.</span></h1>
      <p class="lede">
        Issue reusable codes, bounded unique batches, and recipient-bound grants through stable
        source intents. Replays return the same benefit. The store homepage stays closed.
      </p>
      <p v-if="canWrite">
        <a class="primary-button" href="/admin/coupon-campaigns/create">Create a campaign</a>
      </p>
      <p v-if="campaigns.length === 0" class="workspace-note">No coupon campaigns yet.</p>
      <ul v-else class="product-list">
        <li v-for="campaign in campaigns" :key="campaign.id">
          <a class="tool-link" :href="`/admin/coupon-campaigns/${campaign.id}`">{{
            campaign.name
          }}</a>
          <p>{{ campaign.kind }} · {{ campaign.status }} · revision {{ campaign.revision }}</p>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
