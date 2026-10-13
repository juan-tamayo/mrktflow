<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import VariantEditor from '../components/VariantEditor.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'ProductEdit' })

const props = defineProps<{
  product: {
    id: string
    title: string
    slug: string
    description: string
    status: string
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
    }[]
    option_axes: { name: string; values: string[] }[]
    category_ids: string[]
  } | null
  categories: { id: string; title: string; path: string }[]
  saved: boolean
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  title: props.product?.title ?? '',
  slug: props.product?.slug ?? '',
  description: props.product?.description ?? '',
  status: props.product?.status ?? 'draft',
  sku: props.product?.variant.sku ?? '',
  price_amount: props.product?.variant.money.amount ?? 0,
  image_alt: props.product?.image.alt ?? '',
  version: props.product?.version,
  category_ids: props.product?.category_ids ?? [],
  image: null as File | null,
})
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
      <p class="eyebrow">Simple physical product</p>
      <h1>{{ product ? 'Edit product' : 'Create a product' }}<span class="accent">.</span></h1>
      <p class="lede">
        Save the product, then add option combinations with unique SKUs. Drafts stay private. The
        store homepage stays closed; published products and categories can be browsed.
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
        <button type="submit" class="quiet-button" :disabled="form.processing">
          {{ form.processing && form.status === 'draft' ? 'Saving…' : 'Save draft' }}
        </button>
        <button
          type="button"
          class="primary-button"
          :disabled="form.processing"
          @click="submit('published')"
        >
          {{ form.processing && form.status === 'published' ? 'Publishing…' : 'Publish' }}
        </button>
      </form>
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
