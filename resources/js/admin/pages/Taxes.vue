<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'TaxRules' })

type Country = { code: string; label: string }
type Match = { country_code: string; region_code: string | null; postal_pattern: string | null }
type TaxRule = {
  id?: string
  name: string
  class_key: string
  status: string
  rate_numerator: number
  rate_denominator: number
  match: Match
}
type Preview = {
  ok: boolean
  reason: string | null
  mode: string
  tax: number
  grandTotal: number
  cart: string
}
const props = defineProps<{
  rules: {
    price_mode: string
    shipping_tax_class_key: string
    version: number | null
    classes: { key: string; name: string }[]
    rules: TaxRule[]
  }
  countries: Country[]
  canWrite: boolean
  saved: boolean
  preview: Preview | null
}>()
const errors = ref<HTMLElement | null>(null)
function emptyRule(): TaxRule {
  return {
    name: '',
    class_key: 'standard',
    status: 'active',
    rate_numerator: 10,
    rate_denominator: 100,
    match: { country_code: 'US', region_code: null, postal_pattern: null },
  }
}
const form = useForm({
  rules: {
    price_mode: props.rules.price_mode,
    shipping_tax_class_key: props.rules.shipping_tax_class_key,
    version: props.rules.version,
    classes:
      props.rules.classes.length > 0
        ? props.rules.classes.map((item) => ({ ...item }))
        : [{ key: 'standard', name: 'Standard' }],
    rules:
      props.rules.rules.length > 0 ? props.rules.rules.map((rule) => ({ ...rule })) : [emptyRule()],
  },
})
const previewForm = useForm({
  preview: {
    cart: 'physical',
    billing_country_code: 'US',
    billing_region_code: 'CA',
    billing_postal_code: '90210',
    shipping_country_code: 'US',
    shipping_region_code: 'CA',
    shipping_postal_code: '90210',
    physical_amount: 1000,
    digital_amount: 0,
    shipping_amount: 300,
    physical_class_key: 'standard',
    digital_class_key: 'standard',
  },
})
function submit() {
  form.post('/admin/taxes', {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
function submitPreview() {
  previewForm.post('/admin/taxes/preview', { preserveScroll: true })
}
</script>

<template>
  <Head title="Taxes" />
  <a class="skip-link" href="#taxes-main">Skip to taxes</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="taxes-main" class="setup-main">
      <p class="eyebrow">Pricing</p>
      <h1>Taxes<span class="accent">.</span></h1>
      <p class="lede">
        Configure tax classes, destination rates, and exclusive or inclusive price mode. Missing
        coverage blocks checkout rather than charging zero. The store homepage stays closed.
      </p>
      <p v-if="saved" role="status" class="success-notice">Tax rules saved.</p>
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
        <fieldset class="setup-field">
          <legend>Price mode</legend>
          <div class="setup-field">
            <label for="price-mode">Price mode</label>
            <select id="price-mode" v-model="form.rules.price_mode">
              <option value="exclusive">Tax exclusive</option>
              <option value="inclusive">Tax inclusive</option>
            </select>
          </div>
          <div class="setup-field">
            <label for="shipping-tax-class">Shipping tax class</label>
            <select id="shipping-tax-class" v-model="form.rules.shipping_tax_class_key">
              <option
                v-for="taxClass in form.rules.classes"
                :key="taxClass.key"
                :value="taxClass.key"
              >
                {{ taxClass.name }}
              </option>
            </select>
          </div>
        </fieldset>
        <fieldset class="setup-field">
          <legend>Tax classes</legend>
          <div v-for="(taxClass, index) in form.rules.classes" :key="index" class="setup-grid">
            <div class="setup-field">
              <label :for="`class-key-${index}`">Key</label>
              <input
                :id="`class-key-${index}`"
                v-model="taxClass.key"
                :readonly="taxClass.key === 'standard'"
              />
            </div>
            <div class="setup-field">
              <label :for="`class-name-${index}`">Class name</label>
              <input :id="`class-name-${index}`" v-model="taxClass.name" required />
            </div>
          </div>
          <button
            type="button"
            class="quiet-button"
            @click="form.rules.classes.push({ key: '', name: '' })"
          >
            Add class
          </button>
        </fieldset>
        <fieldset
          v-for="(rule, ruleIndex) in form.rules.rules"
          :key="ruleIndex"
          class="setup-field"
        >
          <legend>Rule {{ ruleIndex + 1 }}</legend>
          <div class="setup-field">
            <label :for="`rule-name-${ruleIndex}`">Rule name</label>
            <input :id="`rule-name-${ruleIndex}`" v-model="rule.name" required />
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label :for="`rule-class-${ruleIndex}`">Class</label>
              <select :id="`rule-class-${ruleIndex}`" v-model="rule.class_key">
                <option
                  v-for="taxClass in form.rules.classes"
                  :key="taxClass.key"
                  :value="taxClass.key"
                >
                  {{ taxClass.key }}
                </option>
              </select>
            </div>
            <div class="setup-field">
              <label :for="`rule-status-${ruleIndex}`">Status</label>
              <select :id="`rule-status-${ruleIndex}`" v-model="rule.status">
                <option value="active">Active</option>
                <option value="draft">Draft</option>
              </select>
            </div>
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label :for="`rule-rate-${ruleIndex}`">Rate numerator</label>
              <input
                :id="`rule-rate-${ruleIndex}`"
                v-model.number="rule.rate_numerator"
                type="number"
                min="0"
                required
              />
            </div>
            <div class="setup-field">
              <label :for="`rule-denominator-${ruleIndex}`">Rate denominator</label>
              <input
                :id="`rule-denominator-${ruleIndex}`"
                v-model.number="rule.rate_denominator"
                type="number"
                min="1"
                required
              />
            </div>
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label :for="`match-country-${ruleIndex}`">Match country</label>
              <select :id="`match-country-${ruleIndex}`" v-model="rule.match.country_code">
                <option v-for="country in countries" :key="country.code" :value="country.code">
                  {{ country.code }}
                </option>
              </select>
            </div>
            <div class="setup-field">
              <label :for="`match-region-${ruleIndex}`">Match region</label>
              <input :id="`match-region-${ruleIndex}`" v-model="rule.match.region_code" />
            </div>
            <div class="setup-field">
              <label :for="`match-postal-${ruleIndex}`">Postal pattern</label>
              <input :id="`match-postal-${ruleIndex}`" v-model="rule.match.postal_pattern" />
            </div>
          </div>
        </fieldset>
        <button type="button" class="quiet-button" @click="form.rules.rules.push(emptyRule())">
          Add rule
        </button>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Saving…' : 'Save tax rules' }}
        </button>
      </form>
      <form class="setup-form" @submit.prevent="submitPreview">
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
          <div class="setup-grid">
            <div class="setup-field">
              <label for="preview-ship-country">Shipping country</label>
              <input
                id="preview-ship-country"
                v-model="previewForm.preview.shipping_country_code"
              />
            </div>
            <div class="setup-field">
              <label for="preview-bill-country">Billing country</label>
              <input id="preview-bill-country" v-model="previewForm.preview.billing_country_code" />
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
            <label for="preview-shipping">Shipping amount</label>
            <input
              id="preview-shipping"
              v-model.number="previewForm.preview.shipping_amount"
              type="number"
              min="0"
              required
            />
          </div>
          <button type="submit" class="quiet-button" :disabled="previewForm.processing">
            Preview tax
          </button>
        </fieldset>
      </form>
      <div v-if="preview" role="status" class="workspace-note">
        <p v-if="preview.ok">
          {{ preview.mode }} tax {{ preview.tax }} · total {{ preview.grandTotal }}
        </p>
        <p v-else>Missing coverage: {{ preview.reason }}</p>
      </div>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
