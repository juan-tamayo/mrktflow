<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'CategoryIndex' })

type Category = {
  id: string
  title: string
  path: string
  status: string
}
defineProps<{
  categories: Category[]
  canWrite: boolean
}>()
</script>

<template>
  <Head title="Categories" />
  <a class="skip-link" href="#categories-main">Skip to categories</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="categories-main" class="setup-main">
      <p class="eyebrow">Catalog</p>
      <h1>Categories<span class="accent">.</span></h1>
      <p class="lede">
        Nest categories with unique paths. Published category pages are public; drafts stay private.
        The store homepage stays closed.
      </p>
      <p v-if="canWrite">
        <a class="primary-button" href="/admin/categories/create">Create a category</a>
      </p>
      <p v-if="categories.length === 0" class="workspace-note">No categories yet.</p>
      <ul v-else class="product-list">
        <li v-for="category in categories" :key="category.id">
          <a class="tool-link" :href="`/admin/categories/${category.id}`">{{ category.title }}</a>
          <p>{{ category.path }} · {{ category.status }}</p>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
