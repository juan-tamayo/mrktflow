<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'TemplateImport' })

type Preview = {
  import_id: string
  documents: { kind: string; page: string; slot: string; section_count: number }[]
  media: { id: string; path: string; bytes: number; content_type: string }[]
  aliases: { products: string[]; categories: string[] }
  can_apply: boolean
  errors: string[]
}

type Applied = {
  import_id: string
  applied: boolean
  replayed: boolean
  documents: { page: string; slot: string; version: number; section_count: number }[]
}

const props = defineProps<{
  preview: Preview | null
  applied: Applied | null
  canWrite: boolean
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  file: null as File | null,
  references: {
    products: Object.fromEntries(
      (props.preview?.aliases.products ?? []).map((alias) => [alias, '']),
    ) as Record<string, string>,
    categories: Object.fromEntries(
      (props.preview?.aliases.categories ?? []).map((alias) => [alias, '']),
    ) as Record<string, string>,
  },
})
const applyForm = useForm({
  references: {
    products: { ...form.references.products },
    categories: { ...form.references.categories },
  },
})
function submit() {
  form.post('/admin/content/import', {
    forceFormData: true,
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
function apply() {
  if (props.preview === null) {
    return
  }
  applyForm.post(`/admin/content/import/${props.preview.import_id}/apply`, { preserveScroll: true })
}
</script>

<template>
  <Head title="Import template" />
  <a class="skip-link" href="#template-import-main">Skip to template import</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/content" class="text-link"
        >Pages, articles and menus</a
      >
    </header>
    <main id="template-import-main" class="setup-main">
      <p class="eyebrow">Storefront content</p>
      <h1>Import template<span class="accent">.</span></h1>
      <p class="lede">
        Upload a data-only JSON and media ZIP. PHP, Vue, scripts and unknown blocks are rejected.
        Apply writes drafts only. The storefront homepage stays closed.
      </p>
      <form v-if="canWrite" class="setup-form" @submit.prevent="submit">
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
          <label for="template-package">Template package</label>
          <input
            id="template-package"
            name="file"
            type="file"
            accept=".zip,application/zip"
            :required="preview === null"
            @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
          />
        </div>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Checking…' : 'Stage and validate package' }}
        </button>
      </form>
      <section
        v-if="preview"
        class="import-report"
        aria-labelledby="template-import-report-heading"
      >
        <h2 id="template-import-report-heading">Import report</h2>
        <p role="status">
          {{ preview.documents.length }} documents, {{ preview.media.length }} media files.
          {{
            preview.can_apply
              ? 'Ready to apply to drafts.'
              : 'Fix the problems listed before applying.'
          }}
        </p>
        <p v-for="error in preview.errors" :key="error">{{ error }}</p>
        <ul>
          <li v-for="document in preview.documents" :key="`${document.page}:${document.slot}`">
            {{ document.page }} {{ document.slot }} · {{ document.section_count }} sections
          </li>
        </ul>
        <div
          v-if="preview.aliases.categories.length || preview.aliases.products.length"
          class="setup-form"
        >
          <div
            v-for="alias in preview.aliases.categories"
            :key="`category-${alias}`"
            class="setup-field"
          >
            <label :for="`map-category-${alias}`">Category alias {{ alias }}</label>
            <input
              :id="`map-category-${alias}`"
              v-model="applyForm.references.categories[alias]"
              :aria-label="`Map category alias ${alias}`"
            />
          </div>
          <div
            v-for="alias in preview.aliases.products"
            :key="`product-${alias}`"
            class="setup-field"
          >
            <label :for="`map-product-${alias}`">Product alias {{ alias }}</label>
            <input
              :id="`map-product-${alias}`"
              v-model="applyForm.references.products[alias]"
              :aria-label="`Map product alias ${alias}`"
            />
          </div>
        </div>
        <form
          v-if="canWrite && applied === null && preview.can_apply"
          class="setup-form"
          @submit.prevent="apply"
        >
          <button
            type="submit"
            class="primary-button"
            aria-label="Apply template to drafts"
            :disabled="applyForm.processing"
          >
            {{ applyForm.processing ? 'Applying…' : 'Apply template to drafts' }}
          </button>
        </form>
        <p v-if="applied" role="status">
          Applied to drafts{{ applied.replayed ? ' (replay)' : '' }}. Live pages were not published.
        </p>
      </section>
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/content">Return to content</a>
    </footer>
  </div>
</template>
