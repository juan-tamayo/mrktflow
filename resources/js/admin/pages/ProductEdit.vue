<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import AttributeFields, { type SchemaField } from '../components/AttributeFields.vue'
import DigitalAssets from '../components/DigitalAssets.vue'
import StockEditor from '../components/StockEditor.vue'
import VariantEditor from '../components/VariantEditor.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { computed, nextTick, ref } from 'vue'

defineOptions({ name: 'ProductEdit' })

const props = withDefaults(
  defineProps<{
    product: {
      id: string
      title: string
      slug: string
      description: string
      status: string
      type?: string
      version: number
      image: { url: string; alt: string }
      variant: { sku: string; money: { amount: number } }
      variants: {
        id: string
        sku: string
        isDefault: boolean
        available: boolean
        options: { name: string; value: string }[]
        money: { amount: number }
        tracks_inventory?: boolean
        on_hand?: number | null
        reserved?: number
        available_quantity?: number | null
        low_stock_threshold?: number | null
        low_stock?: boolean
        digital_files?: { id?: string; label: string }[]
      }[]
      option_axes: { name: string; values: string[] }[]
      category_ids: string[]
      digital_assets?: {
        id: string
        label: string
        versions: {
          id: string
          version: number
          status: string
          bytes: number
          content_type: string
        }[]
      }[]
      schema_id?: string | null
      attributes?: Record<string, string | boolean>
    } | null
    categories: { id: string; title: string; path: string }[]
    schemas?: {
      id: string
      key: string
      label: string
      attributes: SchemaField[]
    }[]
    saved: boolean
  }>(),
  { schemas: () => [] },
)
const errors = ref<HTMLElement | null>(null)
const archiveForm = useForm({
  version: props.product?.version,
})
function archive() {
  if (props.product === null || props.product.status === 'archived') {
    return
  }
  archiveForm.version = props.product.version
  archiveForm.post(`/admin/products/${props.product.id}/archive`, {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
const form = useForm({
  title: props.product?.title ?? '',
  slug: props.product?.slug ?? '',
  description: props.product?.description ?? '',
  status: props.product?.status ?? 'draft',
  type: props.product?.type ?? 'physical',
  sku: props.product?.variant.sku ?? '',
  price_amount: props.product?.variant.money.amount ?? 0,
  image_alt: props.product?.image.alt ?? '',
  version: props.product?.version,
  category_ids: props.product?.category_ids ?? [],
  schema_id: props.product?.schema_id ?? '',
  attributes: props.product?.attributes ?? {},
  image: null as File | null,
})
const selectedFields = computed(
  () => props.schemas.find((schema) => schema.id === form.schema_id)?.attributes ?? [],
)
function submit(status: 'draft' | 'published') {
  form.status = status
  const options = {
    forceFormData: true,
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  }
  if (props.product === null) {
    form.post('/admin/products', options)
    return
  }
  form.patch(`/admin/products/${props.product.id}`, options)
}
</script>

<template>
  <Head :title="product ? 'Edit product' : 'Create a product'" />
  <a class="skip-link" href="#product-main">Skip to product</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/products" class="text-link">Products</a>
    </header>
    <main id="product-main" class="setup-main">
      <p class="eyebrow">
        {{ product?.type === 'digital' ? 'Digital product' : 'Catalog product' }}
      </p>
      <h1>{{ product ? 'Edit product' : 'Create a product' }}<span class="accent">.</span></h1>
      <p class="lede">
        Save the product, then add option combinations with unique SKUs. Digital products attach
        private file versions after save. Drafts stay private. Archiving keeps SKUs for order
        history and hides the product from the storefront. The store homepage stays closed;
        published products and categories can be browsed.
      </p>
      <p v-if="saved" role="status" class="success-notice">Product saved.</p>
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
          <label for="product-title">Title</label>
          <input id="product-title" v-model="form.title" name="title" maxlength="200" required />
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="product-slug">Slug</label>
            <input id="product-slug" v-model="form.slug" name="slug" maxlength="80" required />
          </div>
          <div class="setup-field">
            <label for="product-sku">SKU</label>
            <input id="product-sku" v-model="form.sku" name="sku" maxlength="64" required />
          </div>
        </div>
        <div class="setup-field">
          <label for="product-type">Product type</label>
          <select
            id="product-type"
            v-model="form.type"
            name="type"
            :disabled="product !== null"
            required
          >
            <option value="physical">Physical</option>
            <option value="digital">Digital</option>
          </select>
          <p class="field-help">Type is set when the product is created and cannot be changed.</p>
        </div>
        <div class="setup-field">
          <label for="product-description">Description</label>
          <textarea
            id="product-description"
            v-model="form.description"
            name="description"
            rows="5"
            maxlength="5000"
            required
          />
        </div>
        <div class="setup-field">
          <label for="product-price">Price in the smallest currency unit</label>
          <input
            id="product-price"
            v-model.number="form.price_amount"
            name="price_amount"
            type="number"
            min="0"
            step="1"
            required
          />
          <p class="field-help">Use cents for USD. The store currency is applied on the server.</p>
        </div>
        <div class="setup-field">
          <label for="product-image">Image</label>
          <input
            id="product-image"
            name="image"
            type="file"
            accept="image/png,image/jpeg,image/webp"
            :required="product === null"
            @change="form.image = ($event.target as HTMLInputElement).files?.[0] ?? null"
          />
          <img
            v-if="product"
            class="product-edit-preview"
            :src="product.image.url"
            :alt="product.image.alt"
            width="160"
            height="160"
          />
        </div>
        <div class="setup-field">
          <label for="product-alt">Image description</label>
          <input
            id="product-alt"
            v-model="form.image_alt"
            name="image_alt"
            maxlength="200"
            required
          />
        </div>
        <div v-if="schemas.length" class="setup-field">
          <label for="product-schema">Product schema</label>
          <select id="product-schema" v-model="form.schema_id" name="schema_id">
            <option value="">None (core fields only)</option>
            <option v-for="schema in schemas" :key="schema.id" :value="schema.id">
              {{ schema.label }}
            </option>
          </select>
        </div>
        <AttributeFields
          v-if="selectedFields.length"
          v-model:values="form.attributes"
          mode="values"
          :fields="selectedFields"
        />
        <fieldset v-if="categories.length" class="setup-field">
          <legend>Categories</legend>
          <label v-for="category in categories" :key="category.id" class="category-option">
            <input
              v-model="form.category_ids"
              type="checkbox"
              :value="category.id"
              :name="`category_ids[]`"
            />
            {{ category.path }}
          </label>
        </fieldset>
        <button
          type="submit"
          class="quiet-button"
          :disabled="form.processing || product?.status === 'archived'"
        >
          {{ form.processing && form.status === 'draft' ? 'Saving…' : 'Save draft' }}
        </button>
        <button
          type="button"
          class="primary-button"
          :disabled="form.processing || product?.status === 'archived'"
          @click="submit('published')"
        >
          {{ form.processing && form.status === 'published' ? 'Publishing…' : 'Publish' }}
        </button>
      </form>
      <form
        v-if="product && product.status !== 'archived'"
        class="setup-form"
        @submit.prevent="archive"
      >
        <p class="workspace-note">
          Archive removes this product from public browse and search. Variants and SKUs stay stored
          so later orders can still refer to them.
        </p>
        <button type="submit" class="quiet-button" :disabled="archiveForm.processing">
          {{ archiveForm.processing ? 'Archiving…' : 'Archive product' }}
        </button>
      </form>
      <p v-else-if="product?.status === 'archived'" class="workspace-note">
        This product is archived. SKUs remain for order history and are omitted from the public
        catalog.
      </p>
      <StockEditor
        v-if="product && product.type !== 'digital'"
        :key="product.version"
        :product-id="product.id"
        :variants="product.variants"
      />
      <DigitalAssets
        v-if="product && product.type === 'digital'"
        :key="'digital-' + product.version"
        :product-id="product.id"
        :variants="product.variants"
        :assets="product.digital_assets ?? []"
      />
      <VariantEditor
        v-if="product"
        :product-id="product.id"
        :version="product.version"
        :option-axes="product.option_axes"
        :variants="product.variants"
        :slug="product.slug"
      />
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/products">Return to products</a>
    </footer>
  </div>
</template>
