<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'ShippingRules' })

type Country = { code: string; label: string }
type Match = { country_code: string; region_code: string | null; postal_pattern: string | null }
type Pickup = {
  name: string
  street: string
  city: string
  region_code: string | null
  postal_code: string
  country_code: string
  instructions: string | null
}
type Method = {
  label: string
  type: string
  amount: number | null
  free_min_subtotal: number | null
  table_basis: string | null
  table_rows: { min: number; max: number | null; amount: number }[]
  included_class_keys: string[]
  excluded_class_keys: string[]
  pickup: Pickup | null
}
type Zone = {
  id?: string
  name: string
  priority: number
  status: string
  matches: Match[]
  methods: Method[]
}
type Preview = {
  zoneName: string
  priority: number
  methods: {
    label: string
    type: string
    amount: number | null
    offered: boolean
    reason: string | null
  }[]
}
const props = defineProps<{
  rules: {
    origin: {
      country_code: string
      region_code: string | null
      postal_code: string
      city: string
      street: string
      version: number
    } | null
    classes: { key: string; name: string }[]
    zones: Zone[]
  }
  countries: Country[]
  canWrite: boolean
  saved: boolean
  preview: Preview | null
}>()
const errors = ref<HTMLElement | null>(null)
function emptyMethod(): Method {
  return {
    label: 'Ground',
    type: 'flat',
    amount: 799,
    free_min_subtotal: null,
    table_basis: null,
    table_rows: [],
    included_class_keys: [],
    excluded_class_keys: [],
    pickup: null,
  }
}
function emptyZone(): Zone {
  return {
    name: '',
    priority: 20,
    status: 'active',
    matches: [{ country_code: 'US', region_code: null, postal_pattern: null }],
    methods: [emptyMethod()],
  }
}
const form = useForm({
  rules: {
    origin: {
      country_code: props.rules.origin?.country_code ?? 'US',
      region_code: props.rules.origin?.region_code ?? '',
      postal_code: props.rules.origin?.postal_code ?? '',
      city: props.rules.origin?.city ?? '',
      street: props.rules.origin?.street ?? '',
      version: props.rules.origin?.version ?? null,
    },
    classes:
      props.rules.classes.length > 0
        ? props.rules.classes.map((item) => ({ ...item }))
        : [{ key: 'general', name: 'General' }],
    zones:
      props.rules.zones.length > 0 ? props.rules.zones.map((zone) => ({ ...zone })) : [emptyZone()],
  },
})
const previewForm = useForm({
  preview: {
    country_code: 'US',
    region_code: 'CA',
    postal_code: '90210',
    physical_subtotal: 5000,
    weight_grams: 499,
  },
})
function submit() {
  form.post('/admin/shipping', {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
function submitPreview() {
  previewForm.post('/admin/shipping/preview', { preserveScroll: true })
}
</script>

<template>
  <Head title="Shipping" />
  <a class="skip-link" href="#shipping-main">Skip to shipping</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="shipping-main" class="setup-main">
      <p class="eyebrow">Shipping</p>
      <h1>Zones and rates<span class="accent">.</span></h1>
      <p class="lede">
        Configure one origin, destination zones, and flat, free, table, or pickup methods.
        Same-priority overlapping destinations are rejected. Checkout quotes remain a later task.
        The store homepage stays closed.
      </p>
      <p v-if="saved" role="status" class="success-notice">Shipping rules saved.</p>
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
          <legend>Origin</legend>
          <div class="setup-field">
            <label for="origin-street">Origin street</label>
            <input id="origin-street" v-model="form.rules.origin.street" name="street" required />
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label for="origin-city">Origin city</label>
              <input id="origin-city" v-model="form.rules.origin.city" name="city" required />
            </div>
            <div class="setup-field">
              <label for="origin-region">Region</label>
              <input
                id="origin-region"
                v-model="form.rules.origin.region_code"
                name="region_code"
              />
            </div>
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label for="origin-postal">Origin postal code</label>
              <input
                id="origin-postal"
                v-model="form.rules.origin.postal_code"
                name="postal_code"
                required
              />
            </div>
            <div class="setup-field">
              <label for="origin-country">Country</label>
              <select
                id="origin-country"
                v-model="form.rules.origin.country_code"
                name="country_code"
                required
              >
                <option v-for="country in countries" :key="country.code" :value="country.code">
                  {{ country.label }}
                </option>
              </select>
            </div>
          </div>
        </fieldset>
        <fieldset class="setup-field">
          <legend>Shipping classes</legend>
          <div v-for="(shippingClass, index) in form.rules.classes" :key="index" class="setup-grid">
            <div class="setup-field">
              <label :for="`class-key-${index}`">Key</label>
              <input
                :id="`class-key-${index}`"
                v-model="shippingClass.key"
                :readonly="shippingClass.key === 'general'"
              />
            </div>
            <div class="setup-field">
              <label :for="`class-name-${index}`">Class name</label>
              <input :id="`class-name-${index}`" v-model="shippingClass.name" required />
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
          v-for="(zone, zoneIndex) in form.rules.zones"
          :key="zoneIndex"
          class="setup-field"
        >
          <legend>Zone {{ zoneIndex + 1 }}</legend>
          <div class="setup-grid">
            <div class="setup-field">
              <label :for="`zone-name-${zoneIndex}`">Zone name</label>
              <input :id="`zone-name-${zoneIndex}`" v-model="zone.name" required />
            </div>
            <div class="setup-field">
              <label :for="`zone-priority-${zoneIndex}`">Priority</label>
              <input
                :id="`zone-priority-${zoneIndex}`"
                v-model.number="zone.priority"
                type="number"
                min="1"
                required
              />
            </div>
          </div>
          <div class="setup-field">
            <label :for="`zone-status-${zoneIndex}`">Status</label>
            <select :id="`zone-status-${zoneIndex}`" v-model="zone.status">
              <option value="active">Active</option>
              <option value="draft">Draft</option>
            </select>
          </div>
          <div v-for="(match, matchIndex) in zone.matches" :key="matchIndex" class="setup-grid">
            <div class="setup-field">
              <label :for="`match-country-${zoneIndex}-${matchIndex}`">Match country</label>
              <select :id="`match-country-${zoneIndex}-${matchIndex}`" v-model="match.country_code">
                <option v-for="country in countries" :key="country.code" :value="country.code">
                  {{ country.code }}
                </option>
              </select>
            </div>
            <div class="setup-field">
              <label :for="`match-region-${zoneIndex}-${matchIndex}`">Match region</label>
              <input :id="`match-region-${zoneIndex}-${matchIndex}`" v-model="match.region_code" />
            </div>
            <div class="setup-field">
              <label :for="`match-postal-${zoneIndex}-${matchIndex}`">Postal pattern</label>
              <input
                :id="`match-postal-${zoneIndex}-${matchIndex}`"
                v-model="match.postal_pattern"
              />
            </div>
          </div>
          <div v-for="(method, methodIndex) in zone.methods" :key="methodIndex">
            <div class="setup-grid">
              <div class="setup-field">
                <label :for="`method-label-${zoneIndex}-${methodIndex}`">Method label</label>
                <input
                  :id="`method-label-${zoneIndex}-${methodIndex}`"
                  v-model="method.label"
                  required
                />
              </div>
              <div class="setup-field">
                <label :for="`method-type-${zoneIndex}-${methodIndex}`">Type</label>
                <select :id="`method-type-${zoneIndex}-${methodIndex}`" v-model="method.type">
                  <option value="flat">Flat rate</option>
                  <option value="free">Free above threshold</option>
                  <option value="table">Table</option>
                  <option value="pickup">Pickup</option>
                </select>
              </div>
            </div>
            <div v-if="method.type === 'flat'" class="setup-field">
              <label :for="`method-amount-${zoneIndex}-${methodIndex}`"
                >Amount in the smallest currency unit</label
              >
              <input
                :id="`method-amount-${zoneIndex}-${methodIndex}`"
                v-model.number="method.amount"
                type="number"
                min="0"
              />
            </div>
            <div v-if="method.type === 'free'" class="setup-field">
              <label :for="`method-threshold-${zoneIndex}-${methodIndex}`"
                >Free above physical subtotal</label
              >
              <input
                :id="`method-threshold-${zoneIndex}-${methodIndex}`"
                v-model.number="method.free_min_subtotal"
                type="number"
                min="0"
              />
            </div>
          </div>
          <button type="button" class="quiet-button" @click="zone.methods.push(emptyMethod())">
            Add method
          </button>
        </fieldset>
        <button type="button" class="quiet-button" @click="form.rules.zones.push(emptyZone())">
          Add zone
        </button>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Saving…' : 'Save shipping rules' }}
        </button>
      </form>
      <form class="setup-form" @submit.prevent="submitPreview">
        <fieldset class="setup-field">
          <legend>Preview a destination</legend>
          <div class="setup-grid">
            <div class="setup-field">
              <label for="preview-country">Country</label>
              <input id="preview-country" v-model="previewForm.preview.country_code" required />
            </div>
            <div class="setup-field">
              <label for="preview-region">Region</label>
              <input id="preview-region" v-model="previewForm.preview.region_code" />
            </div>
            <div class="setup-field">
              <label for="preview-postal">Preview postal code</label>
              <input id="preview-postal" v-model="previewForm.preview.postal_code" />
            </div>
          </div>
          <div class="setup-grid">
            <div class="setup-field">
              <label for="preview-subtotal">Physical subtotal</label>
              <input
                id="preview-subtotal"
                v-model.number="previewForm.preview.physical_subtotal"
                type="number"
                min="0"
                required
              />
            </div>
            <div class="setup-field">
              <label for="preview-weight">Weight grams</label>
              <input
                id="preview-weight"
                v-model.number="previewForm.preview.weight_grams"
                type="number"
                min="0"
              />
            </div>
          </div>
          <button type="submit" class="quiet-button" :disabled="previewForm.processing">
            Preview rates
          </button>
        </fieldset>
      </form>
      <div v-if="preview" role="status" class="workspace-note">
        <p>Matching zone: {{ preview.zoneName }}</p>
        <p v-for="method in preview.methods" :key="method.label">
          {{ method.label }} · {{ method.offered ? method.amount : method.reason }}
        </p>
      </div>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
