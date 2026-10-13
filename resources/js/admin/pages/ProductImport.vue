<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'ProductImport' })

type PreviewRow = {
  line: number
  sku: string
  intent: string
  valid: boolean
  errors: string[]
  product_id: string | null
}

const props = defineProps<{
  sampleColumns: string[]
  preview: {
    import_id: string
    headers: string[]
    mapping: Record<string, string>
    unmapped: string[]
    rows: PreviewRow[]
    valid_count: number
    error_count: number
    create_count: number
    update_count: number
  } | null
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  import_id: props.preview?.import_id ?? '',
  mapping: { ...(props.preview?.mapping ?? {}) } as Record<string, string>,
  file: null as File | null,
})
function submit() {
  form.post('/admin/products/import', {
    forceFormData: true,
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
</script>

<template>
  <Head title="Import products" />
  <a class="skip-link" href="#import-main">Skip to import</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/products" class="text-link">Products</a>
    </header>
    <main id="import-main" class="setup-main">
      <p class="eyebrow">Catalog</p>
      <h1>Import CSV<span class="accent">.</span></h1>
      <p class="lede">
        Upload a CSV, map columns, and review row-specific errors. Nothing is written to the catalog
        until a later apply step. Spreadsheet formulas are rejected. The store homepage stays
        closed.
      </p>
      <p class="workspace-note">
        Sample columns: {{ sampleColumns.join(', ') }}. Extra headers named like sensor.megapixels
        map to typed schema attributes. Matching SKUs are treated as updates.
      </p>
      <form class="setup-form" @submit.prevent="submit">
        <div
          v-if="Object.keys(form.errors).length"
          ref="errors"
          role="alert"
          tabindex="-1"
          class="error-summary"
        >
          <p v-for="(message, field) in form.errors" :key="field">{{ message }}</p>
        </div>
        <input v-if="preview" type="hidden" name="import_id" :value="preview.import_id" />
        <div class="setup-field">
          <label for="import-file">CSV file</label>
          <input
            id="import-file"
            name="file"
            type="file"
            accept=".csv,text/csv"
            :required="preview === null"
            @change="form.file = ($event.target as HTMLInputElement).files?.[0] ?? null"
          />
        </div>
        <fieldset v-if="preview" class="setup-field">
          <legend>Column mapping</legend>
          <div v-for="header in preview.headers" :key="header" class="setup-field">
            <label :for="`map-${header}`">{{ header }}</label>
            <select
              :id="`map-${header}`"
              v-model="form.mapping[header]"
              :name="`mapping[${header}]`"
            >
              <option value="ignore">Ignore</option>
              <option
                v-if="form.mapping[header]?.startsWith('attr:')"
                :value="form.mapping[header]"
              >
                {{ form.mapping[header] }}
              </option>
              <option v-for="column in sampleColumns" :key="column" :value="column">
                {{ column }}
              </option>
            </select>
          </div>
        </fieldset>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Checking…' : 'Dry-run validation' }}
        </button>
      </form>
      <section v-if="preview" class="import-report" aria-labelledby="import-report-heading">
        <h2 id="import-report-heading">Dry-run report</h2>
        <p role="status">
          {{ preview.valid_count }} valid, {{ preview.error_count }} with errors,
          {{ preview.create_count }} create, {{ preview.update_count }} update. No catalog rows were
          written.
        </p>
        <p v-if="preview.error_count">
          <a class="text-link" :href="`/admin/products/import/${preview.import_id}/errors.csv`"
            >Download row errors</a
          >
        </p>
        <table class="import-table">
          <caption>
            Row-specific validation
          </caption>
          <thead>
            <tr>
              <th scope="col">Line</th>
              <th scope="col">SKU</th>
              <th scope="col">Intent</th>
              <th scope="col">Result</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in preview.rows" :key="row.line">
              <td>{{ row.line }}</td>
              <td>{{ row.sku }}</td>
              <td>{{ row.intent }}</td>
              <td>
                <span v-if="row.valid">Valid</span>
                <span v-else>{{ row.errors.join(' ') }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </section>
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/products">Return to products</a>
    </footer>
  </div>
</template>
