<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'CouponIndex' })

type Coupon = {
  id: string
  code: string
  name: string
  status: string
  mode: string
  revision: number
}
defineProps<{
  coupons: Coupon[]
  canWrite: boolean
}>()
</script>

<template>
  <Head title="Coupons" />
  <a class="skip-link" href="#coupons-main">Skip to coupons</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="coupons-main" class="setup-main">
      <p class="eyebrow">Pricing</p>
      <h1>Coupons<span class="accent">.</span></h1>
      <p class="lede">
        One entered coupon per cart. Limits are held at checkout and committed on payment. The store
        homepage stays closed.
      </p>
      <p v-if="canWrite">
        <a class="primary-button" href="/admin/coupons/create">Create a coupon</a>
      </p>
      <p v-if="coupons.length === 0" class="workspace-note">No coupons yet.</p>
      <ul v-else class="product-list">
        <li v-for="coupon in coupons" :key="coupon.id">
          <a class="tool-link" :href="`/admin/coupons/${coupon.id}`">{{ coupon.name }}</a>
          <p>{{ coupon.code }} · {{ coupon.mode }} · {{ coupon.status }}</p>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
