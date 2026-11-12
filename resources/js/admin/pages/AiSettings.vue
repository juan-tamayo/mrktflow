<script setup lang="ts">
import BrandLogo from '../components/BrandLogo.vue'
import { Head, useForm } from '@inertiajs/vue3'
import { nextTick, ref } from 'vue'

defineOptions({ name: 'AiSettings' })

type Author = { id: string; name: string; role: string }
type Settings = {
  enabled: boolean
  provider: string
  model: string
  models: string[]
  daily_token_budget: number
  monthly_token_budget: number
  has_secret: boolean
  secret_hint: string | null
  author_ids: string[]
  version: number
}
const props = defineProps<{
  settings: Settings
  authors: Author[]
  saved: boolean
}>()
const errors = ref<HTMLElement | null>(null)
const form = useForm({
  settings: {
    enabled: props.settings.enabled,
    provider: props.settings.provider,
    model: props.settings.model,
    api_key: '',
    daily_token_budget: props.settings.daily_token_budget,
    monthly_token_budget: props.settings.monthly_token_budget,
    author_ids: [...props.settings.author_ids],
    version: props.settings.version,
  },
})
function submit() {
  form.post('/admin/ai', {
    preserveScroll: true,
    onError: async () => {
      await nextTick()
      errors.value?.focus()
    },
  })
}
</script>

<template>
  <Head title="AI authoring" />
  <a class="skip-link" href="#ai-main">Skip to AI settings</a>
  <div class="page-shell">
    <header class="masthead">
      <BrandLogo href="/admin" /><a href="/admin" class="text-link">Store workspace</a>
    </header>
    <main id="ai-main" class="setup-main">
      <p class="eyebrow">Store-owned credentials</p>
      <h1>AI authoring<span class="accent">.</span></h1>
      <p class="lede">
        Optional drafts stay off until you save a provider secret, a permitted model, token budgets,
        and authors. Manual pages, imports, checkout, and updates keep working with AI disabled.
        Generation is not dispatched from this screen.
      </p>
      <p v-if="saved" role="status" class="success-notice">AI settings saved.</p>
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
          <label for="ai-enabled">
            <input id="ai-enabled" v-model="form.settings.enabled" type="checkbox" />
            Enable built-in AI drafts
          </label>
        </div>
        <div class="setup-field">
          <label for="ai-model">Permitted model</label>
          <select id="ai-model" v-model="form.settings.model" required>
            <option v-for="model in settings.models" :key="model" :value="model">
              {{ model }}
            </option>
          </select>
        </div>
        <div class="setup-field">
          <label for="ai-key">Provider secret</label>
          <input
            id="ai-key"
            v-model="form.settings.api_key"
            type="password"
            name="api_key"
            autocomplete="off"
            maxlength="256"
            :placeholder="
              settings.has_secret
                ? `Saved key ending in ${settings.secret_hint ?? '****'}`
                : 'Paste the store-owned key'
            "
          />
          <p class="hint">The secret is encrypted and never shown again.</p>
        </div>
        <div class="setup-grid">
          <div class="setup-field">
            <label for="ai-daily">Daily token budget</label>
            <input
              id="ai-daily"
              v-model.number="form.settings.daily_token_budget"
              type="number"
              min="0"
              required
            />
          </div>
          <div class="setup-field">
            <label for="ai-monthly">Monthly token budget</label>
            <input
              id="ai-monthly"
              v-model.number="form.settings.monthly_token_budget"
              type="number"
              min="0"
              required
            />
          </div>
        </div>
        <fieldset class="setup-field">
          <legend>Authors who may spend the budget</legend>
          <p v-if="authors.length === 0" class="hint">No staff currently have the AI permission.</p>
          <label v-for="author in authors" :key="author.id">
            <input v-model="form.settings.author_ids" type="checkbox" :value="author.id" />
            {{ author.name }} ({{ author.role.replaceAll('_', ' ') }})
          </label>
        </fieldset>
        <button type="submit" class="primary-button" :disabled="form.processing">
          {{ form.processing ? 'Saving…' : 'Save AI settings' }}
        </button>
      </form>
    </main>
    <footer class="footer"><span>MRKTFlow</span><a href="/admin">Return to workspace</a></footer>
  </div>
</template>
