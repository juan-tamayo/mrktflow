<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'ContentNavigation' })

type PageRecord = {
  id: string
  title: string
  slug: string
  path: string
  status: string
  version: number
}
type ArticleRecord = PageRecord & { excerpt: string }
type MenuRecord = {
  id: string
  handle: string
  title: string
  version: number
  items: { label: string; url: string }[]
}

defineProps<{
  pages: PageRecord[]
  articles: ArticleRecord[]
  menus: MenuRecord[]
  canWrite: boolean
  pageSaved: boolean
  articleSaved: boolean
  menuSaved: boolean
}>()

const pageErrors = ref<HTMLElement | null>(null)
const articleErrors = ref<HTMLElement | null>(null)
const menuErrors = ref<HTMLElement | null>(null)
const pageForm = useForm({
  page: { title: '', slug: '', status: 'draft' },
})
const articleForm = useForm({
  article: { title: '', slug: '', excerpt: '', status: 'draft' },
})
const menuForm = useForm({
  menu: {
    handle: 'main',
    title: 'Main menu',
    items: [{ label: 'Home', url: '/' }],
  },
})

function focusOnError(target: typeof pageErrors) {
  return async () => {
    await nextTick()
    target.value?.focus()
  }
}

function addItem() {
  menuForm.menu.items = [...menuForm.menu.items, { label: '', url: '/' }]
}

function removeItem(index: number) {
  menuForm.menu.items = menuForm.menu.items.filter((_, itemIndex) => itemIndex !== index)
}

function savePage() {
  pageForm.post('/admin/content/pages', { preserveScroll: true, onError: focusOnError(pageErrors) })
}

function saveArticle() {
  articleForm.post('/admin/content/articles', {
    preserveScroll: true,
    onError: focusOnError(articleErrors),
  })
}

function saveMenu() {
  menuForm.post('/admin/content/menus', { preserveScroll: true, onError: focusOnError(menuErrors) })
}
</script>

<template>
  <Head title="Pages, articles and navigation" />
  <a class="skip-link" href="#content-main">Skip to content</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="content-main" class="setup-main">
      <p class="eyebrow">Storefront content</p>
      <h1>Pages, articles and menus<span class="accent">.</span></h1>
      <p class="lede">
        Paths cannot reuse reserved routes or the category tree. Menu links stay on this store.
        Publication history arrives later; the storefront homepage stays closed to CMS pages.
      </p>

      <section aria-labelledby="pages-heading">
        <h2 id="pages-heading">Pages</h2>
        <p v-if="pageSaved" role="status" class="success-notice">Page saved.</p>
        <p v-if="pages.length === 0" class="workspace-note">No pages yet.</p>
        <ul v-else class="product-list">
          <li v-for="page in pages" :key="page.id">
            <p class="tool-link">{{ page.title }}</p>
            <p>/{{ page.path }} · {{ page.status }}</p>
          </li>
        </ul>
        <form v-if="canWrite" class="setup-form" @submit.prevent="savePage">
          <div
            v-if="Object.keys(pageForm.errors).length"
            ref="pageErrors"
            role="alert"
            tabindex="-1"
            class="error-summary"
          >
            <p v-for="(message, field) in pageForm.errors" :key="field">{{ message }}</p>
          </div>
          <label for="page-title">Title</label>
          <input
            id="page-title"
            v-model="pageForm.page.title"
            type="text"
            maxlength="200"
            required
          />
          <label for="page-slug">Slug</label>
          <input id="page-slug" v-model="pageForm.page.slug" type="text" maxlength="80" required />
          <label for="page-status">Status</label>
          <select id="page-status" v-model="pageForm.page.status">
            <option value="draft">Draft</option>
            <option value="published">Published</option>
          </select>
          <button type="submit" class="primary-button" :disabled="pageForm.processing">
            Save page
          </button>
        </form>
      </section>

      <section aria-labelledby="articles-heading">
        <h2 id="articles-heading">Articles</h2>
        <p v-if="articleSaved" role="status" class="success-notice">Article saved.</p>
        <p v-if="articles.length === 0" class="workspace-note">No articles yet.</p>
        <ul v-else class="product-list">
          <li v-for="article in articles" :key="article.id">
            <p class="tool-link">{{ article.title }}</p>
            <p>/{{ article.path }} · {{ article.status }}</p>
          </li>
        </ul>
        <form v-if="canWrite" class="setup-form" @submit.prevent="saveArticle">
          <div
            v-if="Object.keys(articleForm.errors).length"
            ref="articleErrors"
            role="alert"
            tabindex="-1"
            class="error-summary"
          >
            <p v-for="(message, field) in articleForm.errors" :key="field">{{ message }}</p>
          </div>
          <label for="article-title">Title</label>
          <input
            id="article-title"
            v-model="articleForm.article.title"
            type="text"
            maxlength="200"
            required
          />
          <label for="article-slug">Slug</label>
          <input
            id="article-slug"
            v-model="articleForm.article.slug"
            type="text"
            maxlength="80"
            required
          />
          <label for="article-excerpt">Excerpt</label>
          <textarea
            id="article-excerpt"
            v-model="articleForm.article.excerpt"
            maxlength="500"
            rows="3"
          />
          <label for="article-status">Status</label>
          <select id="article-status" v-model="articleForm.article.status">
            <option value="draft">Draft</option>
            <option value="published">Published</option>
          </select>
          <button type="submit" class="primary-button" :disabled="articleForm.processing">
            Save article
          </button>
        </form>
      </section>

      <section aria-labelledby="menus-heading">
        <h2 id="menus-heading">Menus</h2>
        <p v-if="menuSaved" role="status" class="success-notice">Menu saved.</p>
        <p v-if="menus.length === 0" class="workspace-note">No menus yet.</p>
        <ul v-else class="product-list">
          <li v-for="menu in menus" :key="menu.id">
            <p class="tool-link">{{ menu.title }}</p>
            <p>{{ menu.handle }} · {{ menu.items.length }} links</p>
          </li>
        </ul>
        <form v-if="canWrite" class="setup-form" @submit.prevent="saveMenu">
          <div
            v-if="Object.keys(menuForm.errors).length"
            ref="menuErrors"
            role="alert"
            tabindex="-1"
            class="error-summary"
          >
            <p v-for="(message, field) in menuForm.errors" :key="field">{{ message }}</p>
          </div>
          <label for="menu-title">Title</label>
          <input
            id="menu-title"
            v-model="menuForm.menu.title"
            type="text"
            maxlength="120"
            required
          />
          <label for="menu-handle">Handle</label>
          <input
            id="menu-handle"
            v-model="menuForm.menu.handle"
            type="text"
            maxlength="32"
            required
          />
          <fieldset>
            <legend>Links</legend>
            <div v-for="(item, index) in menuForm.menu.items" :key="index" class="setup-form">
              <label :for="`menu-item-label-${index}`">Label</label>
              <input
                :id="`menu-item-label-${index}`"
                v-model="item.label"
                type="text"
                maxlength="80"
                required
              />
              <label :for="`menu-item-url-${index}`">URL</label>
              <input
                :id="`menu-item-url-${index}`"
                v-model="item.url"
                type="text"
                maxlength="500"
                required
              />
              <button type="button" class="quiet-button" @click="removeItem(index)">
                Remove link
              </button>
            </div>
            <button type="button" class="quiet-button" @click="addItem">Add link</button>
          </fieldset>
          <button type="submit" class="primary-button" :disabled="menuForm.processing">
            Save menu
          </button>
        </form>
      </section>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
