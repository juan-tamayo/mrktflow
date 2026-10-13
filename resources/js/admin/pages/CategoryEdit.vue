<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'CategoryEdit' })

type Parent = { id: string; title: string; path: string }
const props = defineProps<{
  category: {
    id: string
    title: string
    slug: string
    description: string
    status: string
    version: number
    parent_id: string | null
  } | null
  parents: Parent[]
  saved: boolean
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  category: {
    title: props.category?.title ?? '',
    slug: props.category?.slug ?? '',
    description: props.category?.description ?? '',
    status: props.category?.status ?? 'draft',
    parent_id: props.category?.parent_id ?? '',
    version: props.category?.version,
  },
})
function submit(status: 'draft' | 'published') {
  form.category.status = status
  const options = {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  }
  if (props.category === null) {
    form.post('/admin/categories', options)
    return
  }
  form.patch(`/admin/categories/${props.category.id}`, options)
}
</script>

<template>
  <Head :title="category ? 'Edit category' : 'Create a category'" />
  <a class="skip-link" href="#category-main">Skip to category</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/categories" class="text-link">Categories</a>
    </header>
    <main id="category-main" class="setup-main">
      <p class="eyebrow">Category tree</p>
      <h1>{{ category ? 'Edit category' : 'Create a category' }}<span class="accent">.</span></h1>
      <p class="lede">
        Paths are unique. A category cannot be nested under itself, and reserved storefront names
        are blocked.
      </p>
      <p v-if="saved" role="status" class="success-notice">Category saved.</p>
      <form class="setup-form" @submit.prevent="submit('draft')">
        <div
          v-if="Object.keys(form.errors).length"
          ref="errors"
          role="alert"
          tabindex="-1"
          class="error-summary"
        >
          <p v-for="(message, field) in form.errors" :key="field">{{ message }}</p>
        </div>
        <div class="setup-field">
          <label for="category-title">Title</label>
          <input
            id="category-title"
            v-model="form.category.title"
            name="title"
            maxlength="200"
            required
          />
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="category-slug">Slug</label>
            <input
              id="category-slug"
              v-model="form.category.slug"
              name="slug"
              maxlength="80"
              required
            />
          </div>
          <div class="setup-field">
            <label for="category-parent">Parent category</label>
            <select id="category-parent" v-model="form.category.parent_id" name="parent_id">
              <option value="">None (top level)</option>
              <option v-for="parent in parents" :key="parent.id" :value="parent.id">
                {{ parent.path }}
              </option>
            </select>
          </div>
        </div>
        <div class="setup-field">
          <label for="category-description">Description</label>
          <textarea
            id="category-description"
            v-model="form.category.description"
            name="description"
            rows="5"
            maxlength="5000"
            required
          />
        </div>
        <button type="submit" class="quiet-button" :disabled="form.processing">
          {{ form.processing && form.category.status === 'draft' ? 'Saving…' : 'Save draft' }}
        </button>
        <button
          type="button"
          class="primary-button"
          :disabled="form.processing"
          @click="submit('published')"
        >
          {{ form.processing && form.category.status === 'published' ? 'Publishing…' : 'Publish' }}
        </button>
      </form>
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/categories">Return to categories</a>
    </footer>
  </div>
</template>
