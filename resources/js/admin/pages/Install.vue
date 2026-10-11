<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useForm } from '@inertiajs/vue3'
import CountryPicker from '../components/CountryPicker.vue'
import OwnerSetup from '../components/OwnerSetup.vue'

defineOptions({ name: 'StoreInstall' })

type PolicyKey = 'privacy' | 'terms' | 'returns' | 'shipping' | 'digital'
type StoreDetails = {
  name: string
  currency: string
  locale: string
  timezone: string
  contact_email: string
  sender_email: string
  sender_name: string
  sales_countries: string[]
  weight_unit: string
  length_unit: string
  policies: Record<PolicyKey, string>
}
type Check = { id: string; passed: boolean; required: boolean; message: string }
type Choice = { code: string; label: string }
const props = defineProps<{
  unlocked: boolean
  settings: StoreDetails | null
  version: number
  status: string | null
  checks: Check[]
  saved: boolean
  countries: Choice[]
  currencies: Choice[]
  timezones: string[]
}>()
function copySettings(settings: StoreDetails): StoreDetails {
  return {
    ...settings,
    sales_countries: [...settings.sales_countries],
    policies: { ...settings.policies },
  }
}
const access = useForm({ setup_secret: '' }).dontRemember('setup_secret')
const initial: StoreDetails = props.settings ?? {
  name: '',
  currency: '',
  locale: 'en',
  timezone: 'UTC',
  contact_email: '',
  sender_email: '',
  sender_name: '',
  sales_countries: [],
  weight_unit: 'kg',
  length_unit: 'cm',
  policies: { privacy: '', terms: '', returns: '', shipping: '', digital: '' },
}
const form = useForm({
  version: props.version,
  settings: copySettings(initial),
})
watch(
  () => props.unlocked,
  (unlocked) => {
    if (unlocked && props.settings) {
      form.settings = copySettings(props.settings)
      form.defaults()
    }
  },
)
const errorSummary = ref<HTMLElement | null>(null)
const blocked = computed(() => props.checks.some((check) => check.required && !check.passed))
const fields = [
  { key: 'name', label: 'Store name', type: 'text', max: 120 },
  { key: 'contact_email', label: 'Customer support email', type: 'email', max: 254 },
  { key: 'sender_name', label: 'Email sender name', type: 'text', max: 120 },
  { key: 'sender_email', label: 'Email sender address', type: 'email', max: 254 },
] as const
const policies = [
  { key: 'privacy', label: 'Privacy policy' },
  { key: 'terms', label: 'Terms of sale' },
  { key: 'returns', label: 'Returns and refunds' },
  { key: 'shipping', label: 'Shipping policy' },
  { key: 'digital', label: 'Digital delivery policy' },
] as const
function unlock() {
  access.post('/setup/access', { onFinish: () => access.reset('setup_secret') })
}
function save() {
  form.post('/setup/settings', {
    preserveScroll: true,
    onSuccess: () => {
      form.version = props.version
      if (props.settings) form.settings = copySettings(props.settings)
      form.defaults()
    },
    onError: async () => {
      await nextTick()
      errorSummary.value?.focus()
    },
  })
}
</script>

<template>
  <a class="skip-link" href="#setup-main">Skip to setup</a>
  <div class="page-shell">
    <header class="masthead">
      <a href="/" class="wordmark">mrktflow<span aria-hidden="true">•</span></a>
      <span class="status">Store setup</span>
    </header>
    <main id="setup-main" class="setup-main">
      <template v-if="!unlocked">
        <p class="eyebrow">A store of your own</p>
        <h1>Unlock your store setup<span class="accent">.</span></h1>
        <p class="lede">
          Enter the private installation secret supplied by your server administrator. Only someone
          with this secret can begin setup.
        </p>
        <form class="setup-unlock" @submit.prevent="unlock">
          <label for="setup-secret">Installation secret</label>
          <input
            id="setup-secret"
            v-model="access.setup_secret"
            type="password"
            name="setup_secret"
            required
            maxlength="64"
            autocomplete="off"
            spellcheck="false"
            :aria-invalid="Boolean(access.errors.setup_secret)"
            :aria-describedby="access.errors.setup_secret ? 'secret-error' : undefined"
          />
          <p v-if="access.errors.setup_secret" id="secret-error" class="field-error" role="alert">
            {{ access.errors.setup_secret }}
          </p>
          <button class="primary-button" type="submit" :disabled="access.processing">
            {{ access.processing ? 'Checking access…' : 'Unlock setup' }}
          </button>
        </form>
      </template>
      <template v-else>
        <p class="eyebrow">Make it yours</p>
        <h1>Set up your store<span class="accent">.</span></h1>
        <p class="lede">
          Choose how your store sells and communicates. Saving these details keeps your storefront
          closed while you finish owner setup.
        </p>
        <p v-if="saved" class="success-notice" role="status">
          Store details saved. Your storefront is still closed.
        </p>
        <section
          v-if="checks.some((check) => !check.passed)"
          class="setup-checks"
          aria-labelledby="checks-title"
        >
          <h2 id="checks-title">Before your store opens</h2>
          <ul>
            <li v-for="check in checks.filter((item) => !item.passed)" :key="check.id">
              <strong>{{ check.required ? 'Needs attention: ' : 'Before opening: ' }}</strong
              >{{ check.message }}
            </li>
          </ul>
        </section>
        <form class="setup-form" @submit.prevent="save">
          <div
            v-if="form.hasErrors"
            ref="errorSummary"
            class="error-summary"
            tabindex="-1"
            role="alert"
          >
            <h2>Please check these details</h2>
            <a v-if="form.errors.settings" href="/setup">Reload the saved store details</a>
            <ul>
              <li v-for="(error, key) in form.errors" :key="key">{{ error }}</li>
            </ul>
          </div>
          <fieldset>
            <legend>Store and contact details</legend>
            <div class="setup-grid">
              <div v-for="field in fields" :key="field.key" class="setup-field">
                <label :for="field.key">{{ field.label }}</label>
                <input
                  :id="field.key"
                  v-model="form.settings[field.key]"
                  :name="field.key"
                  :type="field.type"
                  required
                  :maxlength="field.max"
                  :aria-invalid="Boolean(form.errors[`settings.${field.key}`])"
                  :aria-describedby="
                    form.errors[`settings.${field.key}`] ? `${field.key}-error` : undefined
                  "
                />
                <p
                  v-if="form.errors[`settings.${field.key}`]"
                  :id="`${field.key}-error`"
                  class="field-error"
                >
                  {{ form.errors[`settings.${field.key}`] }}
                </p>
              </div>
              <div class="setup-field">
                <label for="currency">Store currency</label
                ><select id="currency" v-model="form.settings.currency" required>
                  <option disabled value="">Choose a currency</option>
                  <option v-for="choice in currencies" :key="choice.code" :value="choice.code">
                    {{ choice.label }}
                  </option>
                </select>
              </div>
              <div class="setup-field">
                <label for="timezone">Store timezone</label
                ><select id="timezone" v-model="form.settings.timezone" required>
                  <option v-for="zone in timezones" :key="zone" :value="zone">
                    {{ zone.replaceAll('_', ' ') }}
                  </option>
                </select>
              </div>
              <div class="setup-field">
                <label for="locale">Publishing language</label
                ><select id="locale" v-model="form.settings.locale">
                  <option value="en">English</option>
                </select>
              </div>
            </div>
          </fieldset>
          <fieldset>
            <legend>Where you sell</legend>
            <p class="field-help">Choose every country your store will serve.</p>
            <CountryPicker v-model="form.settings.sales_countries" :options="countries" />
            <div class="setup-grid">
              <div class="setup-field">
                <label for="weight">Product weight unit</label
                ><select id="weight" v-model="form.settings.weight_unit">
                  <option value="kg">Kilograms</option>
                  <option value="lb">Pounds</option>
                </select>
              </div>
              <div class="setup-field">
                <label for="length">Product dimension unit</label
                ><select id="length" v-model="form.settings.length_unit">
                  <option value="cm">Centimeters</option>
                  <option value="in">Inches</option>
                </select>
              </div>
            </div>
          </fieldset>
          <fieldset>
            <legend>Customer policies</legend>
            <p class="field-help">
              Write the terms customers will see before buying physical or digital products.
            </p>
            <div v-for="policy in policies" :key="policy.key" class="setup-field">
              <label :for="`policy-${policy.key}`">{{ policy.label }}</label
              ><textarea
                :id="`policy-${policy.key}`"
                v-model="form.settings.policies[policy.key]"
                required
                maxlength="10000"
                rows="4"
              />
            </div>
          </fieldset>
          <button type="submit" class="primary-button" :disabled="blocked || form.processing">
            {{ form.processing ? 'Saving details…' : 'Save store details' }}
          </button>
        </form>
        <OwnerSetup v-if="status === 'pending_owner'" :blocked="blocked" />
      </template>
    </main>
    <footer class="footer">
      <span>Your store. Your infrastructure.</span><a href="/">Return to storefront</a>
    </footer>
  </div>
</template>
