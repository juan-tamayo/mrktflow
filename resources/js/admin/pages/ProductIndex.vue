<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'ProductIndex' })

type Product = {
  id: string
  title: string
  slug: string
  status: string
  updated_at: string
}
defineProps<{
  products: { data: Product[]; next_page_url: string | null; prev_page_url: string | null }
  canWrite: boolean
}>()
</script>

<template>
  <Head title="Products" />
  <a class="skip-link" href="#products-main">Skip to products</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="products-main" class="setup-main">
      <p class="eyebrow">Catalog</p>
      <h1>Products<span class="accent">.</span></h1>
      <p class="lede">
        Save a simple physical product as a draft, then publish it when the title, SKU, price, and
        image are ready. Drafts stay out of the public catalog. The store homepage remains closed.
      </p>
      <p v-if="canWrite">
        <a class="primary-button" href="/admin/products/create">Create a product</a>
      </p>
      <p v-if="products.data.length === 0" class="workspace-note">No products yet.</p>
      <ul v-else class="product-list">
        <li v-for="product in products.data" :key="product.id">
          <a class="tool-link" :href="`/admin/products/${product.id}`">{{ product.title }}</a>
          <p>{{ product.slug }} · {{ product.status }}</p>
        </li>
      </ul>
      <nav aria-label="Product list pages">
        <a v-if="products.prev_page_url" :href="products.prev_page_url" class="text-link"
          >Previous products</a
        >
        <a v-if="products.next_page_url" :href="products.next_page_url" class="text-link"
          >More products</a
        >
      </nav>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
