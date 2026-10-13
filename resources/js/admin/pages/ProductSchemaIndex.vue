<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head } from '@inertiajs/vue3'

defineOptions({ name: 'ProductSchemaIndex' })

type Schema = {
  id: string
  key: string
  label: string
  comparison_group: string
  schema_version: number
}
defineProps<{
  schemas: Schema[]
  canWrite: boolean
}>()
</script>

<template>
  <Head title="Product schemas" />
  <a class="skip-link" href="#schemas-main">Skip to schemas</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="schemas-main" class="setup-main">
      <p class="eyebrow">Catalog</p>
      <h1>Product schemas<span class="accent">.</span></h1>
      <p class="lede">
        Typed fields stay on a versioned schema. Products keep core price and SKU fields separately.
        The store homepage stays closed.
      </p>
      <p v-if="canWrite">
        <a class="primary-button" href="/admin/schemas/create">Create a schema</a>
      </p>
      <p v-if="schemas.length === 0" class="workspace-note">No schemas yet.</p>
      <ul v-else class="product-list">
        <li v-for="schema in schemas" :key="schema.id">
          <a class="tool-link" :href="`/admin/schemas/${schema.id}`">{{ schema.label }}</a>
          <p>
            {{ schema.key }} · version {{ schema.schema_version }} · {{ schema.comparison_group }}
          </p>
        </li>
      </ul>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
