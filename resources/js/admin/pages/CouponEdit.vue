<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'CouponEdit' })

type CatalogItem = { id: string; title: string; path?: string }
const props = defineProps<{
  coupon: {
    id: string
    code: string
    name: string
    mode: string
    amount: number | null
    percent_numerator: number | null
    percent_denominator: number | null
    min_eligible_subtotal: number
    starts_at: string | null
    ends_at: string | null
    max_redemptions: number | null
    max_redemptions_per_customer: number | null
    status: string
    version: number
    product_ids: string[]
    category_ids: string[]
  } | null
  products: CatalogItem[]
  categories: CatalogItem[]
  saved: boolean
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  coupon: {
    code: props.coupon?.code ?? '',
    name: props.coupon?.name ?? '',
    mode: props.coupon?.mode ?? 'percentage',
    amount: props.coupon?.amount,
    percent_numerator: props.coupon?.percent_numerator ?? 10,
    percent_denominator: props.coupon?.percent_denominator ?? 100,
    min_eligible_subtotal: props.coupon?.min_eligible_subtotal ?? 0,
    starts_at: props.coupon?.starts_at ?? '',
    ends_at: props.coupon?.ends_at ?? '',
    max_redemptions: props.coupon?.max_redemptions,
    max_redemptions_per_customer: props.coupon?.max_redemptions_per_customer,
    status: props.coupon?.status ?? 'active',
    version: props.coupon?.version,
    product_ids: [...(props.coupon?.product_ids ?? [])],
    category_ids: [...(props.coupon?.category_ids ?? [])],
  },
})
function toggle(list: 'product_ids' | 'category_ids', id: string) {
  const current = form.coupon[list]
  if (current.includes(id)) {
    form.coupon[list] = current.filter((value) => value !== id)
    return
  }
  form.coupon[list] = [...current, id]
}
function submit() {
  const options = {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  }
  if (props.coupon === null) {
    form.post('/admin/coupons', options)
    return
  }
  form.patch(`/admin/coupons/${props.coupon.id}`, options)
}
</script>

<template>
  <Head :title="coupon ? 'Edit coupon' : 'Create a coupon'" />
  <a class="skip-link" href="#coupon-main">Skip to coupon</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin/coupons" class="text-link">Coupons</a>
    </header>
    <main id="coupon-main" class="setup-main">
      <p class="eyebrow">One coupon per cart</p>
      <h1>{{ coupon ? 'Edit coupon' : 'Create a coupon' }}<span class="accent">.</span></h1>
      <p class="lede">
        Fixed, percentage, or free-shipping rules use integer money. Redemption caps are held
        atomically later at checkout. Shoppers cannot enter a code until checkout is built.
      </p>
      <p v-if="saved" role="status" class="success-notice">Coupon saved.</p>
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
        <div class="setup-field">
          <label for="coupon-name">Name</label>
          <input id="coupon-name" v-model="form.coupon.name" name="name" maxlength="120" required />
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="coupon-code">Code</label>
            <input
              id="coupon-code"
              v-model="form.coupon.code"
              name="code"
              maxlength="32"
              required
            />
          </div>
          <div class="setup-field">
            <label for="coupon-mode">Mode</label>
            <select id="coupon-mode" v-model="form.coupon.mode" name="mode" required>
              <option value="percentage">Percentage</option>
              <option value="fixed">Fixed amount</option>
              <option value="free_shipping">Free shipping</option>
            </select>
          </div>
        </div>
        <div v-if="form.coupon.mode === 'fixed'" class="setup-field">
          <label for="coupon-amount">Amount in the smallest currency unit</label>
          <input
            id="coupon-amount"
            v-model.number="form.coupon.amount"
            name="amount"
            type="number"
            min="1"
            required
          />
        </div>
        <div v-if="form.coupon.mode === 'percentage'" class="setup-field">
          <label for="coupon-percent">Percent</label>
          <input
            id="coupon-percent"
            v-model.number="form.coupon.percent_numerator"
            name="percent_numerator"
            type="number"
            min="1"
            max="100"
            required
          />
        </div>
        <div class="setup-field">
          <label for="coupon-min">Minimum eligible subtotal</label>
          <input
            id="coupon-min"
            v-model.number="form.coupon.min_eligible_subtotal"
            name="min_eligible_subtotal"
            type="number"
            min="0"
            required
          />
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="coupon-starts">Starts at (UTC)</label>
            <input id="coupon-starts" v-model="form.coupon.starts_at" name="starts_at" />
          </div>
          <div class="setup-field">
            <label for="coupon-ends">Ends at (UTC)</label>
            <input id="coupon-ends" v-model="form.coupon.ends_at" name="ends_at" />
          </div>
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="coupon-max">Total redemptions</label>
            <input
              id="coupon-max"
              v-model.number="form.coupon.max_redemptions"
              name="max_redemptions"
              type="number"
              min="1"
            />
          </div>
          <div class="setup-field">
            <label for="coupon-per-customer">Per customer</label>
            <input
              id="coupon-per-customer"
              v-model.number="form.coupon.max_redemptions_per_customer"
              name="max_redemptions_per_customer"
              type="number"
              min="1"
              max="99"
            />
          </div>
        </div>
        <div class="setup-field">
          <label for="coupon-status">Status</label>
          <select id="coupon-status" v-model="form.coupon.status" name="status" required>
            <option value="draft">Draft</option>
            <option value="active">Active</option>
            <option value="paused">Paused</option>
          </select>
        </div>
        <fieldset class="setup-field">
          <legend>Eligible products</legend>
          <p v-if="products.length === 0">
            No products yet. Empty scope includes the whole catalog.
          </p>
          <label v-for="product in products" :key="product.id">
            <input
              type="checkbox"
              :value="product.id"
              :checked="form.coupon.product_ids.includes(product.id)"
              @change="toggle('product_ids', product.id)"
            />
            {{ product.title }}
          </label>
        </fieldset>
        <fieldset class="setup-field">
          <legend>Eligible categories</legend>
          <p v-if="categories.length === 0">
            No categories yet. Empty scope includes the whole catalog.
          </p>
          <label v-for="category in categories" :key="category.id">
            <input
              type="checkbox"
              :value="category.id"
              :checked="form.coupon.category_ids.includes(category.id)"
              @change="toggle('category_ids', category.id)"
            />
            {{ category.title }}
          </label>
        </fieldset>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Saving…' : 'Save coupon' }}
        </button>
      </form>
    </main>
    <footer class="footer">
      <span>MRKTFlow</span><a href="/admin/coupons">Return to coupons</a>
    </footer>
  </div>
</template>
