<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'

defineOptions({ name: 'PricingPreview' })

type Money = { amount: number; currency: string; exponent: number; formatted: string }
type Preview = {
  ok: boolean
  reason: string | null
  cart: string
  price_mode: string
  currency: string
  exponent: number
  coupon: string | null
  merchandise: Money
  discount: Money
  shipping: Money
  tax: Money
  grand_total: Money
}
const props = defineProps<{
  currency: string
  exponent: number
  canWrite: boolean
  preview: Preview | null
}>()
const previewForm = useForm({
  preview: {
    cart: 'mixed',
    coupon: 'SAVE10',
    billing_country_code: 'US',
    billing_region_code: 'CA',
    billing_postal_code: '90210',
    shipping_country_code: 'US',
    shipping_region_code: 'CA',
    shipping_postal_code: '90210',
    physical_amount: 2000,
    digital_amount: 500,
    physical_grams: 2400,
    physical_class_key: 'general',
    digital_class_key: 'general',
    physical_tax_class_key: 'standard',
    digital_tax_class_key: 'standard',
  },
})
function submitPreview() {
  previewForm.post('/admin/pricing-preview', { preserveScroll: true })
}
</script>

<template>
  <Head title="Pricing preview" />
  <a class="skip-link" href="#pricing-preview-main">Skip to pricing preview</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="pricing-preview-main" class="setup-main">
      <p class="eyebrow">Pricing</p>
      <h1>Pricing preview<span class="accent">.</span></h1>
      <p class="lede">
        Preview physical, digital, and mixed carts with the same itemized totals checkout uses.
        Currency is {{ props.currency }} with exponent {{ props.exponent }}. The store homepage
        stays closed.
      </p>
      <form v-if="canWrite" class="setup-form" @submit.prevent="submitPreview">
        <fieldset class="setup-field">
          <legend>Preview a cart</legend>
          <div class="setup-field">
            <label for="preview-cart">Cart</label>
            <select id="preview-cart" v-model="previewForm.preview.cart">
              <option value="physical">Physical</option>
              <option value="digital">Digital</option>
              <option value="mixed">Mixed</option>
            </select>
          </div>
          <div class="setup-field">
            <label for="preview-coupon">Coupon (optional)</label>
            <input id="preview-coupon" v-model="previewForm.preview.coupon" maxlength="32" />
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label for="preview-bill-country">Billing country</label>
              <input id="preview-bill-country" v-model="previewForm.preview.billing_country_code" />
            </div>
            <div class="setup-field">
              <label for="preview-ship-country">Shipping country</label>
              <input
                id="preview-ship-country"
                v-model="previewForm.preview.shipping_country_code"
              />
            </div>
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label for="preview-bill-region">Billing region</label>
              <input id="preview-bill-region" v-model="previewForm.preview.billing_region_code" />
            </div>
            <div class="setup-field">
              <label for="preview-ship-region">Shipping region</label>
              <input id="preview-ship-region" v-model="previewForm.preview.shipping_region_code" />
            </div>
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label for="preview-bill-postal">Billing postal code</label>
              <input id="preview-bill-postal" v-model="previewForm.preview.billing_postal_code" />
            </div>
            <div class="setup-field">
              <label for="preview-ship-postal">Shipping postal code</label>
              <input id="preview-ship-postal" v-model="previewForm.preview.shipping_postal_code" />
            </div>
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label for="preview-physical">Physical amount</label>
              <input
                id="preview-physical"
                v-model.number="previewForm.preview.physical_amount"
                type="number"
                min="0"
                required
              />
            </div>
            <div class="setup-field">
              <label for="preview-digital">Digital amount</label>
              <input
                id="preview-digital"
                v-model.number="previewForm.preview.digital_amount"
                type="number"
                min="0"
                required
              />
            </div>
          </div>
          <div class="setup-field">
            <label for="preview-grams">Physical grams</label>
            <input
              id="preview-grams"
              v-model.number="previewForm.preview.physical_grams"
              type="number"
              min="1"
              required
            />
          </div>
          <button type="submit" class="primary-button" :disabled="previewForm.processing">
            {{ previewForm.processing ? 'Previewing…' : 'Preview totals' }}
          </button>
        </fieldset>
      </form>
      <div v-if="preview && preview.ok" role="status" class="workspace-note">
        <p>{{ preview.price_mode }} {{ preview.currency }}</p>
        <p>
          <span>Merchandise</span>
          <strong>{{ preview.merchandise.formatted }}</strong>
        </p>
        <p>
          <span>Discount</span>
          <strong>{{ preview.discount.formatted }}</strong>
        </p>
        <p>
          <span>Shipping</span>
          <strong>{{ preview.shipping.formatted }}</strong>
        </p>
        <p>
          <span>Tax</span>
          <strong>{{ preview.tax.formatted }}</strong>
        </p>
        <p>
          <span>Total</span>
          <strong>{{ preview.grand_total.formatted }}</strong>
        </p>
      </div>
      <div v-else-if="preview" role="alert" class="error-summary">
        <p>{{ preview.reason }}</p>
      </div>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
